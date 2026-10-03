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
defined('STUDIO_DISCOVERY_PAUSE_PENDING')|| define('STUDIO_DISCOVERY_PAUSE_PENDING', 10000); // pause studio discovery (people mining + queueing) while this many studios are pending; resumes by itself below it. 0 = no cap
defined('STUDIO_DISCOVER_MIN_FOLLOWERS') || define('STUDIO_DISCOVER_MIN_FOLLOWERS', 5); // only read managers/curators of studios with at least this many followers
defined('STUDIO_CURATOR_PAGES')          || define('STUDIO_CURATOR_PAGES', 1);     // pages of 40 curators per studio
defined('STUDIO_CURATE_MAX_PAGES')       || define('STUDIO_CURATE_MAX_PAGES', 3);  // pages of 40 curated studios per person
defined('STUDIO_PAGE_SIZE')              || define('STUDIO_PAGE_SIZE', 40);
defined('STUDIO_SEED_ID')                || define('STUDIO_SEED_ID', 56);          // Mick's Gallery, the root
defined('STUDIO_REFRESH_ENABLED')        || define('STUDIO_REFRESH_ENABLED', true);   // re-fetch the top studios so the list can show follower changes
defined('STUDIO_REFRESH_TOP_N')          || define('STUDIO_REFRESH_TOP_N', 10000);    // how many of the biggest studios get refreshed
defined('STUDIO_REFRESH_INTERVAL_HOURS') || define('STUDIO_REFRESH_INTERVAL_HOURS', 24); // a studio is due again this long after its last check
defined('STUDIO_REFRESH_TIME_SHARE')     || define('STUDIO_REFRESH_TIME_SHARE', 0.5); // up to this share of a studio cron run goes to refreshing due studios first

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

function studioPendingCount(): int {
    return (int)getDB()->query("SELECT COUNT(*) AS c FROM studios WHERE status = 'pending'")->fetch_assoc()['c'];
}

// Master switch plus the pending cap. Mirrors discoveryAllowed() for users.
function studioDiscoveryAllowed(int $pending): bool {
    if (!STUDIO_DISCOVERY_ENABLED) return false;
    return STUDIO_DISCOVERY_PAUSE_PENDING <= 0 || $pending < STUDIO_DISCOVERY_PAUSE_PENDING;
}

// Queues the root studio the first time, so a fresh install just works.
function studioSeedIfEmpty(): void {
    $c = (int)getDB()->query("SELECT COUNT(*) AS c FROM studios")->fetch_assoc()['c'];
    if ($c === 0) queueStudios([[STUDIO_SEED_ID, 'seed', 2000000000]]);
}

// Step 1 + 2 for one claimed chunk of studios.
function studioFetchRound(array $claim, array &$st, bool $discover = true): void {
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

    if (!$discover || $st['rate_limited']) return;

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

// ---- Top-studio refresh. Same idea as the users' refresh in functions.php: the
// biggest STUDIO_REFRESH_TOP_N studios are fetched again once
// STUDIO_REFRESH_INTERVAL_HOURS have passed since their last check, and the change
// (new - old) is stored in follower_delta. The list shows it for 2 days.

// follower_count of the STUDIO_REFRESH_TOP_N-th biggest studio (0 if there are fewer).
function studioRefreshThreshold(): int {
    static $t = null;
    if ($t !== null) return $t;
    $off = max(0, STUDIO_REFRESH_TOP_N - 1);
    $stmt = getDB()->prepare("SELECT follower_count FROM studios WHERE status = 'fetched' ORDER BY follower_count DESC LIMIT 1 OFFSET ?");
    $stmt->bind_param('i', $off);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $t = $row ? (int)$row['follower_count'] : 0;
}

function studioClaimRefresh(int $n): array {
    $db = getDB();
    $token = bin2hex(random_bytes(8));
    $ttl = CRAWL_CLAIM_TTL_SEC;
    $hours = STUDIO_REFRESH_INTERVAL_HOURS;
    $min = max(1, studioRefreshThreshold());
    $stmt = $db->prepare("UPDATE studios SET claim_token = ?, claimed_at = NOW()
        WHERE status = 'fetched' AND follower_count >= ?
          AND checked_at < DATE_SUB(NOW(), INTERVAL ? HOUR)
          AND (claim_token IS NULL OR claimed_at < DATE_SUB(NOW(), INTERVAL ? SECOND))
        ORDER BY follower_count DESC, id ASC LIMIT ?");
    $stmt->bind_param('siiii', $token, $min, $hours, $ttl, $n);
    $stmt->execute();
    $stmt->close();
    $stmt = $db->prepare("SELECT id, follower_count FROM studios WHERE claim_token = ? AND status = 'fetched'");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return ['token' => $token, 'rows' => $rows];
}

// Stores a fresh fetch of an already-fetched studio, with its follower change.
function studioMarkRefreshed(int $id, array $s, int $delta): void {
    $stmt = getDB()->prepare("UPDATE studios SET title = ?, host_id = ?, follower_count = ?, follower_delta = ?, project_count = ?,
        manager_count = ?, comment_count = ?, open_to_all = ?, is_public = ?, comments_allowed = ?, created_on = ?,
        checked_at = NOW(), claim_token = NULL, claimed_at = NULL WHERE id = ?");
    $stmt->bind_param('siiiiiiiiisi', $s['title'], $s['host_id'], $s['followers'], $delta, $s['projects'], $s['managers'],
        $s['comments'], $s['open_to_all'], $s['is_public'], $s['comments_allowed'], $s['created'], $id);
    $stmt->execute();
    $stmt->close();
}

// One round: returns how many studios were claimed (0 = nothing is due).
// A page that fails (timeout, odd answer) only has checked_at moved, so it waits a
// full interval; a 404 means the studio is gone and it leaves the list.
function studioRefreshRound(array &$st, int $n): int {
    $claim = studioClaimRefresh($n);
    if (!$claim['rows']) return 0;
    $urls = [];
    foreach ($claim['rows'] as $r) $urls[$r['id']] = studioUrl((int)$r['id']);
    $res = httpMultiGet($urls);
    $st['requests'] += count($res['results']);
    if ($res['rate_limited']) $st['rate_limited'] = true;

    $gone = []; $skip = [];
    foreach ($claim['rows'] as $r) {
        $id = (int)$r['id'];
        $x = $res['results'][$id] ?? null;
        if (!$x || $x['code'] === 429) continue; // not attempted: released below
        $s = $x['code'] === 200 ? parseStudio($x['body']) : null;
        if ($s) {
            studioMarkRefreshed($id, $s, (int)$s['followers'] - (int)$r['follower_count']);
            $st['studios_refreshed']++;
        } elseif ($x['code'] === 404) {
            $gone[] = $id;
        } else {
            $skip[] = $id;
        }
    }
    studioMarkError('studios', $gone);
    if ($skip) {
        $in = implode(',', array_fill(0, count($skip), '?'));
        $stmt = getDB()->prepare("UPDATE studios SET checked_at = NOW(), claim_token = NULL, claimed_at = NULL WHERE id IN ($in)");
        $stmt->bind_param(str_repeat('i', count($skip)), ...$skip);
        $stmt->execute();
        $stmt->close();
    }
    // anything still carrying our token was never attempted: free it
    $stmt = getDB()->prepare("UPDATE studios SET claim_token = NULL, claimed_at = NULL WHERE claim_token = ? AND status = 'fetched'");
    $stmt->bind_param('s', $claim['token']);
    $stmt->execute();
    $stmt->close();
    return count($claim['rows']);
}

// One cron run. Returns this run's counters.
function crawlStudiosBatch(int $budgetSec = STUDIO_TIME_BUDGET_SEC): array {
    $start = microtime(true);
    $st = ['studios_fetched' => 0, 'studios_refreshed' => 0, 'studios_errors' => 0, 'studios_retried' => 0, 'studios_queued' => 0,
           'people_mined' => 0, 'people_queued' => 0, 'people_errors' => 0, 'requests' => 0, 'rate_limited' => false];
    studioSeedIfEmpty();

    // Due top studios first, but only up to their share of the time budget.
    if (STUDIO_REFRESH_ENABLED) {
        $until = $start + $budgetSec * STUDIO_REFRESH_TIME_SHARE;
        while (microtime(true) < $until && !$st['rate_limited']) {
            if (studioRefreshRound($st, STUDIO_CHUNK_SIZE) === 0) break;
        }
    }

    while ((microtime(true) - $start) < $budgetSec && !$st['rate_limited']) {
        // Only recount pending each round when a cap is set.
        $discover = studioDiscoveryAllowed(STUDIO_DISCOVERY_PAUSE_PENDING > 0 ? studioPendingCount() : 0);
        $sc = studioClaim('studios', 'id', STUDIO_CHUNK_SIZE);
        $pc = $discover
            ? studioClaim('studio_people', 'id, username', STUDIO_PEOPLE_CHUNK_SIZE)
            : ['token' => '', 'rows' => []];
        if (!$sc['rows'] && !$pc['rows']) break;

        if ($sc['rows']) studioFetchRound($sc, $st, $discover);
        if ($pc['rows'] && !$st['rate_limited']) studioPeopleRound($pc, $st);

        // Anything still pending under our tokens was not attempted (429 or
        // skipped): hand it back right away instead of waiting for the TTL.
        studioReleaseClaim('studios', $sc['token']);
        if ($pc['token'] !== '') studioReleaseClaim('studio_people', $pc['token']);
        if ($st['rate_limited']) break;
    }
    return $st;
}

// ---- Public studios page (studios.php) ------------------------------------
// Search mirrors the users page. One query string can mix:
//   f>=100 f<=500   follower comparisons (=, <, <=, >, >=)
//   open / closed   open_to_all = 1 / 0
//   id:56           that one studio
//   anything else   partial, case-insensitive title match
function parseStudioSearch(string $q): array {
    $base = parseSearchQuery($q); // f ops; "exact:" is pulled out but unused here
    $text = $base['text'];
    if ($base['exact'] !== null) $text = trim($text . ' ' . $base['exact']);
    $id = null;
    $open = null;
    $text = preg_replace_callback('/(?<!\S)id:(\d{1,10})(?!\S)/i', function ($m) use (&$id) {
        $id = (int)$m[1];
        return ' ';
    }, $text);
    $text = preg_replace_callback('/(?<!\S)(open|closed)(?!\S)/i', function ($m) use (&$open) {
        $open = strtolower($m[1]) === 'open' ? 1 : 0;
        return ' ';
    }, $text);
    $text = trim(preg_replace('/\s+/', ' ', $text));
    return ['conds' => $base['conds'], 'id' => $id, 'open' => $open, 'text' => $text];
}

function buildStudioWhere(array $p): array {
    $where = "status = 'fetched'";
    $types = '';
    $params = [];
    foreach ($p['conds'] as [$op, $v]) {
        if (!in_array($op, ['=', '<', '<=', '>', '>='], true)) continue;
        $where .= " AND follower_count $op ?";
        $types .= 'i';
        $params[] = $v;
    }
    if ($p['id'] !== null) { $where .= " AND id = ?"; $types .= 'i'; $params[] = $p['id']; }
    if ($p['open'] !== null) { $where .= " AND open_to_all = ?"; $types .= 'i'; $params[] = $p['open']; }
    if ($p['text'] !== '') { $where .= " AND title LIKE ?"; $types .= 's'; $params[] = '%' . likeEscape($p['text']) . '%'; }
    return [$where, $types, $params];
}

function getStudioCount(): int {
    $file = sys_get_temp_dir() . '/scratchcensus_studiocount_' . md5(__DIR__) . '.txt';
    $raw = @file_get_contents($file);
    if ($raw !== false && preg_match('/^(\d+) (\d+)$/', $raw, $m) && (time() - (int)$m[2]) < 60) {
        return (int)$m[1];
    }
    $count = (int)getDB()->query("SELECT COUNT(*) AS c FROM studios WHERE status = 'fetched'")->fetch_assoc()['c'];
    @file_put_contents($file, $count . ' ' . time(), LOCK_EX);
    return $count;
}

// follower_count => number of fetched studios with exactly that count, cached 60s.
// $open = 1 / 0 counts only open / closed studios (null = all). The open/closed
// variants need INDEX (status, open_to_all, follower_count, id) to stay fast.
function studioFollowerHistogram(?int $open = null): array {
    $file = sys_get_temp_dir() . '/scratchcensus_studiohist_' . md5(__DIR__) . '_' . ($open === null ? 'all' : $open) . '.json';
    if (is_file($file) && (time() - (int)@filemtime($file)) < 60) {
        $cached = json_decode((string)@file_get_contents($file), true);
        if (is_array($cached) && $cached) {
            $hist = [];
            foreach ($cached as $fc => $c) $hist[(int)$fc] = (int)$c;
            return $hist;
        }
    }
    $sql = "SELECT follower_count, COUNT(*) AS c FROM studios WHERE status = 'fetched'"
         . ($open === null ? '' : ' AND open_to_all = ' . (int)$open) . ' GROUP BY follower_count';
    $res = getDB()->query($sql);
    $hist = [];
    while ($row = $res->fetch_assoc()) $hist[(int)$row['follower_count']] = (int)$row['c'];
    @file_put_contents($file, json_encode($hist), LOCK_EX);
    return $hist;
}

// The histogram narrowed to the follower comparisons of a search (f>=100 etc).
function studioFilteredHistogram(?int $open, array $conds): array {
    $hist = studioFollowerHistogram($open);
    if (!$conds) return $hist;
    foreach ($hist as $fc => $c) {
        foreach ($conds as [$op, $v]) {
            if ($op === '=') $ok = $fc == $v;
            elseif ($op === '<') $ok = $fc < $v;
            elseif ($op === '<=') $ok = $fc <= $v;
            elseif ($op === '>') $ok = $fc > $v;
            elseif ($op === '>=') $ok = $fc >= $v;
            else $ok = true;
            if (!$ok) { unset($hist[$fc]); break; }
        }
    }
    return $hist;
}

// $n studios of the list (follower_count DESC, id ASC) from $offset, optionally only
// open/closed ones and/or within follower comparisons, read in index order with no
// sort. The mixed ORDER BY can't use an ascending index, so MySQL used to sort every
// matching studio on every page. Same idea as leaderboardRows() in functions.php:
// find the follower_count at the offset from the histogram, read that tie group by
// id ASC, then lower groups by a backward scan (id DESC) with each group flipped,
// re-reading the last group in ASC order in case it was cut short.
// Needs INDEX (status, follower_count, id) and INDEX (status, open_to_all, follower_count, id).
function studioLeaderboardRows(int $offset, int $n, ?int $open = null, array $conds = []): array {
    $db = getDB();
    $cols = "id, title, host_username, follower_count, project_count, open_to_all, created_on,
        IF(checked_at >= DATE_SUB(NOW(), INTERVAL 2 DAY), follower_delta, NULL) AS delta";
    $q = function (string $sql, string $types, array $params) use ($db): array {
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $r;
    };
    $extra = '';
    $xt = '';
    $xp = [];
    if ($open !== null) { $extra .= ' AND open_to_all = ?'; $xt .= 'i'; $xp[] = $open; }
    foreach ($conds as [$op, $v]) {
        if (!in_array($op, ['=', '<', '<=', '>', '>='], true)) continue;
        $extra .= " AND follower_count $op ?";
        $xt .= 'i';
        $xp[] = $v;
    }
    $group = "SELECT $cols FROM studios WHERE status = 'fetched'$extra AND follower_count = ? ORDER BY id ASC LIMIT ? OFFSET ?";

    $hist = studioFilteredHistogram($open, $conds);
    krsort($hist);
    $above = 0;
    $cur = null;
    $skip = 0;
    foreach ($hist as $fc => $c) {
        if ($offset < $above + $c) { $cur = $fc; $skip = $offset - $above; break; }
        $above += $c;
    }
    if ($cur === null) return [];

    $out = $q($group, $xt . 'iii', array_merge($xp, [$cur, $n, $skip]));
    $need = $n - count($out);
    while ($need > 0) {
        $batch = $q("SELECT $cols FROM studios WHERE status = 'fetched'$extra AND follower_count < ?
            ORDER BY follower_count DESC, id DESC LIMIT ?", $xt . 'ii', array_merge($xp, [$cur, $need]));
        if (!$batch) break;
        $groups = [];
        foreach ($batch as $row) $groups[(int)$row['follower_count']][] = $row;
        $cut = null;
        if (count($batch) === $need) {
            end($groups);
            $cut = key($groups);
            unset($groups[$cut]);
        }
        foreach ($groups as $fc => $rows) {
            foreach (array_reverse($rows) as $row) $out[] = $row;
            $cur = $fc;
        }
        if ($cut !== null) {
            foreach ($q($group, $xt . 'iii', array_merge($xp, [$cut, $n - count($out), 0])) as $row) $out[] = $row;
            $cur = $cut;
        }
        $need = $n - count($out);
        if ($cut === null) break;
    }
    return $out;
}

// Rank in the full list: follower_count DESC, id ASC.
function studioRankOf(int $followers, int $id): int {
    $stmt = getDB()->prepare("SELECT
        (SELECT COUNT(*) FROM studios WHERE status = 'fetched' AND follower_count > ?)
      + (SELECT COUNT(*) FROM studios WHERE status = 'fetched' AND follower_count = ? AND id < ?)
      + 1 AS r");
    $stmt->bind_param('iii', $followers, $followers, $id);
    $stmt->execute();
    $r = (int)$stmt->get_result()->fetch_assoc()['r'];
    $stmt->close();
    return $r;
}

// Adds 'rank' (position in the FULL list) to rows that are not a contiguous slice of
// it. Studios above = running total from the cached histogram; position inside the
// tie group = one covering-index read of that group's ids per distinct follower_count
// on the page, instead of two COUNT(*) scans per row.
function studioAttachRanks(array &$rows): void {
    if (!$rows) return;
    $hist = studioFollowerHistogram();
    krsort($hist);
    $maxId = [];
    foreach ($rows as $r) {
        $fc = (int)$r['follower_count'];
        $maxId[$fc] = max($maxId[$fc] ?? 0, (int)$r['id']);
    }
    $above = [];
    $run = 0;
    foreach ($hist as $fc => $c) {
        if (isset($maxId[$fc])) $above[$fc] = $run;
        $run += $c;
    }
    $db = getDB();
    $stmt = $db->prepare("SELECT id FROM studios WHERE status = 'fetched' AND follower_count = ? AND id <= ? ORDER BY id ASC");
    $pos = [];
    foreach ($maxId as $fc => $mid) {
        $stmt->bind_param('ii', $fc, $mid);
        $stmt->execute();
        $i = 0;
        foreach ($stmt->get_result()->fetch_all(MYSQLI_NUM) as [$id]) $pos[$fc][(int)$id] = $i++;
    }
    $stmt->close();
    foreach ($rows as &$r) {
        $fc = (int)$r['follower_count'];
        $r['rank'] = isset($above[$fc], $pos[$fc][(int)$r['id']]) ? $above[$fc] + $pos[$fc][(int)$r['id']] + 1 : studioRankOf($fc, (int)$r['id']);
    }
    unset($r);
}

// $p = null for plain browsing. Returns ['rows' => [...with 'rank'...], 'total' => int].
function getStudiosPage(?array $p, int $page, int $perPage = 100): array {
    $db = getDB();
    $offset = ($page - 1) * $perPage;
    // open/closed and follower comparisons only (no title text, no id): served from
    // the indexes and histograms, no sort and no scan.
    $fast = !$p || ($p['text'] === '' && $p['id'] === null);
    if ($fast) {
        $open = $p['open'] ?? null;
        $conds = $p['conds'] ?? [];
        $total = array_sum(studioFilteredHistogram($open, $conds));
        $rows = studioLeaderboardRows($offset, $perPage, $open, $conds);
        if ($rows && $open === null) {
            // the whole list or one contiguous block of it
            $first = !$p || !$conds ? $offset + 1 : studioRankOf((int)$rows[0]['follower_count'], (int)$rows[0]['id']);
            foreach ($rows as $i => &$r) $r['rank'] = $first + $i;
            unset($r);
        } else {
            studioAttachRanks($rows);
        }
        return ['rows' => $rows, 'total' => $total];
    }

    [$where, $types, $params] = buildStudioWhere($p);
    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM studios WHERE $where");
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $stmt = $db->prepare("SELECT id, title, host_username, follower_count, project_count, open_to_all, created_on,
        IF(checked_at >= DATE_SUB(NOW(), INTERVAL 2 DAY), follower_delta, NULL) AS delta
        FROM studios WHERE $where ORDER BY follower_count DESC, id ASC LIMIT ? OFFSET ?");
    $stmt->bind_param($types . 'ii', ...array_merge($params, [$perPage, $offset]));
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    studioAttachRanks($rows);
    return ['rows' => $rows, 'total' => $total];
}

// Long titles (and unbroken strings like "AAAAAAAA...") would stretch the
// table, so cut them at 60 characters. The full title stays in the link's
// tooltip.
function shortTitle(string $t, int $max = 60): string {
    $len = function_exists('mb_strlen') ? mb_strlen($t) : strlen($t);
    if ($len <= $max) return $t;
    $cut = function_exists('mb_substr') ? mb_substr($t, 0, $max) : substr($t, 0, $max);
    return rtrim($cut) . '...';
}

// ---- Public crawl buttons (crawl.php -> crawl-run.php) ---------------------
defined('PUBLIC_STUDIO_CRAWL_SEC') || define('PUBLIC_STUDIO_CRAWL_SEC', 15); // one click on "Crawl Studios" works for about this long

// Accepts a studio id ("56") or a studio link ("https://scratch.mit.edu/studios/56/"). Null if it is neither.
function parseStudioIdInput(string $s): ?int {
    $s = trim($s);
    if (preg_match('~studios/(\d{1,10})~i', $s, $m)) return (int)$m[1];
    if (preg_match('/^#?(\d{1,10})$/', $s, $m)) return (int)$m[1];
    return null;
}

// Fetches one studio right now (adding it to the list if it is new, refreshing it if it is not).
// Returns ['ok' => true, 'title' => ..., 'count' => followers] or ['ok' => false, 'reason' => 'notfound'|'busy'].
function crawlSingleStudio(int $id): array {
    queueStudios([[$id, 'manual', 2000000000]]); // INSERT IGNORE: makes sure a row exists
    $x = httpMultiGet(['s' => studioUrl($id)])['results']['s'] ?? null;
    if (!$x) return ['ok' => false, 'reason' => 'busy'];
    if ($x['code'] === 200 && ($s = parseStudio($x['body']))) {
        studioMarkFetched($id, $s);
        return ['ok' => true, 'title' => $s['title'], 'count' => $s['followers']];
    }
    if ($x['code'] === 404) {
        studioMarkError('studios', [$id]);
        return ['ok' => false, 'reason' => 'notfound'];
    }
    return ['ok' => false, 'reason' => 'busy'];
}