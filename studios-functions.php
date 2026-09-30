<?php
// Studio crawler. Lives in its own file so functions.php (the users crawler)
// stays untouched. Reuses httpMultiGet(), getDB() and the CRAWL_* knobs.
//
// Flow, per round:
//   1. claim pending studios, fetch api.scratch.mit.edu/studios/<id> (1 request each)
//   2. for studios with enough followers, fetch their managers + curators and
//      queue those people (studio_people)
//   3. claim pending people, fetch the studios they curate, queue those ids
// Tables: see studios-schema.sql.
require_once __DIR__ . '/functions.php';

// ---- Tuning. Override any of these in config.php (config.php loads first).
defined('STUDIO_TIME_BUDGET_SEC')        || define('STUDIO_TIME_BUDGET_SEC', 20);  // per cron run. Kept small on purpose: the users cron already uses ~13 req/s from the same IP, and Scratch's rate limit is shared
defined('STUDIO_CHUNK_SIZE')             || define('STUDIO_CHUNK_SIZE', 40);       // studios claimed per round
defined('STUDIO_PEOPLE_CHUNK_SIZE')      || define('STUDIO_PEOPLE_CHUNK_SIZE', 20);// people claimed per round
defined('STUDIO_DISCOVERY_ENABLED')      || define('STUDIO_DISCOVERY_ENABLED', true); // false = only fetch studios already queued, never mine people
defined('STUDIO_DISCOVER_MIN_FOLLOWERS') || define('STUDIO_DISCOVER_MIN_FOLLOWERS', 5); // only read managers/curators of studios with at least this many followers
defined('STUDIO_CURATOR_PAGES')          || define('STUDIO_CURATOR_PAGES', 1);     // pages of 40 curators per studio
defined('STUDIO_CURATE_MAX_PAGES')       || define('STUDIO_CURATE_MAX_PAGES', 3);  // pages of 40 curated studios per person
defined('STUDIO_PAGE_SIZE')              || define('STUDIO_PAGE_SIZE', 40);
defined('STUDIO_SEED_ID')                || define('STUDIO_SEED_ID', 56);          // Mick's Gallery, the root

const STUDIO_API = 'https://api.scratch.mit.edu';

function studioUrl(int $id): string { return STUDIO_API . '/studios/' . $id; }

// Fetches list endpoints (managers, curators, curated studios) with paging.
// $baseUrls: key => url (no limit/offset). Returns
// ['lists' => key => items, 'codes' => key => first page HTTP code, 'rate_limited' => bool].
// A key missing from 'codes' was not attempted (429 stopped it).
function httpPagedJson(array $baseUrls, int $maxPages): array {
    $lists = [];
    $codes = [];
    $rateLimited = false;
    $active = [];
    foreach ($baseUrls as $k => $u) {
        $active[$k] = ['url' => $u, 'offset' => 0, 'page' => 1];
        $lists[$k] = [];
    }
    while ($active && !$rateLimited) {
        $urls = [];
        foreach ($active as $k => $a) {
            $urls[$k] = $a['url'] . (strpos($a['url'], '?') === false ? '?' : '&')
                . 'limit=' . STUDIO_PAGE_SIZE . '&offset=' . $a['offset'];
        }
        $r = httpMultiGet($urls);
        if ($r['rate_limited']) $rateLimited = true;
        $next = [];
        foreach ($active as $k => $a) {
            $x = $r['results'][$k] ?? null;
            if (!$x) continue;
            $d = $x['code'] === 200 ? json_decode((string)$x['body'], true) : null;
            if ($a['page'] === 1) $codes[$k] = ($x['code'] === 200 && !is_array($d)) ? 0 : $x['code'];
            if (!is_array($d)) continue;
            foreach ($d as $item) $lists[$k][] = $item;
            if (count($d) >= STUDIO_PAGE_SIZE && $a['page'] < $maxPages) {
                $a['offset'] += STUDIO_PAGE_SIZE;
                $a['page']++;
                $next[$k] = $a;
            }
        }
        $active = $next;
    }
    return ['lists' => $lists, 'codes' => $codes, 'rate_limited' => $rateLimited];
}

// Turns a /studios/<id> JSON body into the fields we store, or null if it
// isn't a studio. "host" is a numeric user id in Scratch's API; handle an
// object too in case that ever changes.
function parseStudio(?string $body): ?array {
    $d = json_decode((string)$body, true);
    if (!is_array($d) || !isset($d['id'])) return null;
    $created = null;
    if (!empty($d['history']['created']) && ($t = strtotime($d['history']['created'])) !== false) {
        $created = gmdate('Y-m-d H:i:s', $t);
    }
    $host = $d['host'] ?? null;
    if (is_array($host)) $host = $host['id'] ?? null;
    $title = (string)($d['title'] ?? '');
    $title = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $title) ?? $title; // emoji etc: safe if the connection isn't utf8mb4
    $title = function_exists('mb_substr') ? mb_substr($title, 0, 255) : substr($title, 0, 255);
    return [
        'title'            => $title,
        'host_id'          => $host !== null ? (int)$host : null,
        'followers'        => (int)($d['stats']['followers'] ?? 0),
        'projects'         => (int)($d['stats']['projects'] ?? 0),
        'managers'         => (int)($d['stats']['managers'] ?? 0),
        'comments'         => (int)($d['stats']['comments'] ?? 0),
        'open_to_all'      => !empty($d['open_to_all']) ? 1 : 0,
        'is_public'        => !empty($d['public']) ? 1 : 0,
        'comments_allowed' => !empty($d['comments_allowed']) ? 1 : 0,
        'created'          => $created,
    ];
}

// $items: list of [id, discovered_from, priority]. INSERT IGNORE, 200 per query.
function queueStudios(array $items): int {
    if (!$items) return 0;
    $db = getDB();
    $seen = [];
    $unique = [];
    foreach ($items as $it) {
        $id = (int)$it[0];
        if ($id <= 0 || isset($seen[$id])) continue;
        $seen[$id] = true;
        $unique[] = [$id, $it[1], (int)$it[2]];
    }
    $added = 0;
    foreach (array_chunk($unique, 200) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '(?, ?, ?)'));
        $params = [];
        foreach ($chunk as $it) { $params[] = $it[0]; $params[] = $it[1]; $params[] = $it[2]; }
        $stmt = $db->prepare("INSERT IGNORE INTO studios (id, discovered_from, priority) VALUES $ph");
        $stmt->bind_param(str_repeat('isi', count($chunk)), ...$params);
        $stmt->execute();
        $added += $stmt->affected_rows;
        $stmt->close();
    }
    return $added;
}

// $items: list of [username, studio_id, priority].
function queueStudioPeople(array $items): int {
    if (!$items) return 0;
    $db = getDB();
    $seen = [];
    $unique = [];
    foreach ($items as $it) {
        $name = trim((string)$it[0]);
        $k = strtolower($name);
        if ($name === '' || isset($seen[$k])) continue;
        $seen[$k] = true;
        $unique[] = [$name, (int)$it[1], (int)$it[2]];
    }
    $added = 0;
    foreach (array_chunk($unique, 200) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '(?, ?, ?)'));
        $params = [];
        foreach ($chunk as $it) { $params[] = $it[0]; $params[] = $it[1]; $params[] = $it[2]; }
        $stmt = $db->prepare("INSERT IGNORE INTO studio_people (username, discovered_studio, priority) VALUES $ph");
        $stmt->bind_param(str_repeat('sii', count($chunk)), ...$params);
        $stmt->execute();
        $added += $stmt->affected_rows;
        $stmt->close();
    }
    return $added;
}

// Token-based claim, same idea as claimPendingRows(): overlapping cron runs
// never take the same rows, and a crashed run's claims expire after the TTL.
function studioClaim(string $table, string $cols, int $n): array {
    $db = getDB();
    $token = bin2hex(random_bytes(8));
    $ttl = CRAWL_CLAIM_TTL_SEC;
    $stmt = $db->prepare("UPDATE $table SET claim_token = ?, claimed_at = NOW()
        WHERE status = 'pending'
          AND (claim_token IS NULL OR claimed_at < DATE_SUB(NOW(), INTERVAL ? SECOND))
        ORDER BY priority DESC, id ASC LIMIT ?");
    $stmt->bind_param('sii', $token, $ttl, $n);
    $stmt->execute();
    $stmt->close();
    $stmt = $db->prepare("SELECT $cols FROM $table WHERE claim_token = ? AND status = 'pending'");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return ['token' => $token, 'rows' => $rows];
}

function studioReleaseClaim(string $table, string $token): void {
    $stmt = getDB()->prepare("UPDATE $table SET claim_token = NULL, claimed_at = NULL WHERE claim_token = ? AND status = 'pending'");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->close();
}

function studioMarkFetched(int $id, array $s): void {
    $stmt = getDB()->prepare("UPDATE studios SET title = ?, host_id = ?, follower_count = ?, project_count = ?,
        manager_count = ?, comment_count = ?, open_to_all = ?, is_public = ?, comments_allowed = ?, created_on = ?,
        status = 'fetched', retries = 0, checked_at = NOW(), claim_token = NULL, claimed_at = NULL WHERE id = ?");
    $stmt->bind_param('siiiiiiiisi', $s['title'], $s['host_id'], $s['followers'], $s['projects'], $s['managers'],
        $s['comments'], $s['open_to_all'], $s['is_public'], $s['comments_allowed'], $s['created'], $id);
    $stmt->execute();
    $stmt->close();
}

function studioSetHostUsername(int $id, string $username): void {
    $stmt = getDB()->prepare("UPDATE studios SET host_username = ? WHERE id = ?");
    $stmt->bind_param('si', $username, $id);
    $stmt->execute();
    $stmt->close();
}

// Permanent: Scratch answered 404. $table is 'studios' or 'studio_people'.
function studioMarkError(string $table, array $ids): void {
    if (!$ids) return;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = getDB()->prepare("UPDATE $table SET status = 'error', claim_token = NULL, claimed_at = NULL WHERE id IN ($in)");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $stmt->close();
}

// Transient failure: count a retry and park the row behind the claim cooldown.
// Becomes 'error' after CRAWL_MAX_RETRIES.
function studioMarkRetry(string $table, array $ids): void {
    if (!$ids) return;
    $max = CRAWL_MAX_RETRIES;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = getDB()->prepare("UPDATE $table
        SET status = IF(retries + 1 >= ?, 'error', 'pending'), retries = retries + 1,
            claim_token = 'retry', claimed_at = NOW()
        WHERE id IN ($in)");
    $stmt->bind_param('i' . str_repeat('i', count($ids)), $max, ...$ids);
    $stmt->execute();
    $stmt->close();
}

function studioMarkMined(array $ids): void {
    if (!$ids) return;
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = getDB()->prepare("UPDATE studio_people SET status = 'mined', retries = 0, mined_at = NOW(), claim_token = NULL, claimed_at = NULL WHERE id IN ($in)");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $stmt->close();
}

// Queues the root studio the first time, so a fresh install just works.
function studioSeedIfEmpty(): void {
    $c = (int)getDB()->query("SELECT COUNT(*) AS c FROM studios")->fetch_assoc()['c'];
    if ($c === 0) queueStudios([[STUDIO_SEED_ID, 'seed', 2000000000]]);
}

// Step 1 + 2 for one claimed chunk of studios.
function studioFetchRound(array $claim, array &$st): void {
    $urls = [];
    foreach ($claim['rows'] as $r) $urls[$r['id']] = studioUrl((int)$r['id']);
    $res = httpMultiGet($urls);
    $st['requests'] += count($res['results']);
    if ($res['rate_limited']) $st['rate_limited'] = true;

    $fetched = []; $errors = []; $retry = [];
    foreach ($claim['rows'] as $r) {
        $id = (int)$r['id'];
        $x = $res['results'][$id] ?? null;
        if (!$x) continue; // not attempted (429); claim is released after the round
        $s = $x['code'] === 200 ? parseStudio($x['body']) : null;
        if ($s) {
            studioMarkFetched($id, $s);
            $fetched[$id] = $s;
            $st['studios_fetched']++;
        } elseif ($x['code'] === 404) {
            $errors[] = $id;
        } else {
            $retry[] = $id;
        }
    }
    studioMarkError('studios', $errors);
    studioMarkRetry('studios', $retry);
    $st['studios_errors'] += count($errors);
    $st['studios_retried'] += count($retry);

    if (!STUDIO_DISCOVERY_ENABLED || $st['rate_limited']) return;

    // Managers (includes the host) and curators of studios worth mining.
    $lists = [];
    foreach ($fetched as $id => $s) {
        if ($s['followers'] < STUDIO_DISCOVER_MIN_FOLLOWERS) continue;
        $lists['m' . $id] = studioUrl($id) . '/managers';
        $lists['c' . $id] = studioUrl($id) . '/curators';
    }
    if (!$lists) return;
    $m = httpPagedJson($lists, 1);
    $st['requests'] += count($m['codes']);
    if ($m['rate_limited']) $st['rate_limited'] = true;

    $people = [];
    foreach ($m['lists'] as $key => $users) {
        $kind = $key[0];
        $id = (int)substr($key, 1);
        $s = $fetched[$id];
        foreach ($users as $u) {
            if (empty($u['username'])) continue;
            $people[] = [$u['username'], $id, $s['followers']];
            if ($kind === 'm' && $s['host_id'] !== null && isset($u['id']) && (int)$u['id'] === $s['host_id']) {
                studioSetHostUsername($id, (string)$u['username']);
            }
        }
    }
    $st['people_queued'] += queueStudioPeople($people);
}

// Step 3 for one claimed chunk of people: queue the studios they curate.
function studioPeopleRound(array $claim, array &$st): void {
    $byKey = [];
    $bases = [];
    foreach ($claim['rows'] as $r) {
        $byKey['p' . $r['id']] = $r;
        $bases['p' . $r['id']] = STUDIO_API . '/users/' . rawurlencode($r['username']) . '/studios/curate';
    }
    $p = httpPagedJson($bases, STUDIO_CURATE_MAX_PAGES);
    $st['requests'] += count($p['codes']);
    if ($p['rate_limited']) $st['rate_limited'] = true;

    $mined = []; $errors = []; $retry = []; $items = [];
    foreach ($claim['rows'] as $r) {
        $k = 'p' . $r['id'];
        if (!isset($p['codes'][$k])) continue; // not attempted
        $code = $p['codes'][$k];
        if ($code === 200) {
            $mined[] = (int)$r['id'];
            foreach ($p['lists'][$k] as $studio) {
                if (!empty($studio['id'])) $items[] = [(int)$studio['id'], $r['username'], 0];
            }
        } elseif ($code === 404) {
            $errors[] = (int)$r['id'];
        } else {
            $retry[] = (int)$r['id'];
        }
    }
    studioMarkMined($mined);
    studioMarkError('studio_people', $errors);
    studioMarkRetry('studio_people', $retry);
    $st['people_mined'] += count($mined);
    $st['people_errors'] += count($errors);
    $st['studios_queued'] += queueStudios($items);
}

// One cron run. Returns this run's counters.
function crawlStudiosBatch(int $budgetSec = STUDIO_TIME_BUDGET_SEC): array {
    $start = microtime(true);
    $st = ['studios_fetched' => 0, 'studios_errors' => 0, 'studios_retried' => 0, 'studios_queued' => 0,
           'people_mined' => 0, 'people_queued' => 0, 'people_errors' => 0, 'requests' => 0, 'rate_limited' => false];
    studioSeedIfEmpty();

    while ((microtime(true) - $start) < $budgetSec) {
        $sc = studioClaim('studios', 'id', STUDIO_CHUNK_SIZE);
        $pc = STUDIO_DISCOVERY_ENABLED
            ? studioClaim('studio_people', 'id, username', STUDIO_PEOPLE_CHUNK_SIZE)
            : ['token' => '', 'rows' => []];
        if (!$sc['rows'] && !$pc['rows']) break;

        if ($sc['rows']) studioFetchRound($sc, $st);
        if ($pc['rows'] && !$st['rate_limited']) studioPeopleRound($pc, $st);

        // Anything still pending under our tokens was not attempted (429 or
        // skipped): hand it back right away instead of waiting for the TTL.
        studioReleaseClaim('studios', $sc['token']);
        if ($pc['token'] !== '') studioReleaseClaim('studio_people', $pc['token']);
        if ($st['rate_limited']) break;
    }
    return $st;
}
