<?php
require_once __DIR__ . '/config.php';

// ---- Crawler tuning. Each can be overridden by defining it in config.php
// (config.php is loaded first, so its value wins).
defined('CRAWL_CONCURRENCY')          || define('CRAWL_CONCURRENCY', 4);      // simultaneous requests to Scratch
defined('CRAWL_REQUEST_GAP')          || define('CRAWL_REQUEST_GAP', 0.1);    // min seconds between request STARTS (0.1 = max ~10 req/s)
defined('CRAWL_CHUNK_SIZE')           || define('CRAWL_CHUNK_SIZE', 20);      // rows claimed + processed per round
defined('CRAWL_TIME_BUDGET_SEC')      || define('CRAWL_TIME_BUDGET_SEC', 45); // stop starting new rounds after this long. Back at the proven-safe 45s: the 120s test hit a 500, almost certainly iFastNet's own request timeout (unrelated to this budget's own bookkeeping) - see crawl.php for how to diagnose and raise this safely
defined('CRAWL_MAX_RETRIES')          || define('CRAWL_MAX_RETRIES', 3);      // transient failures before a row becomes 'error'
defined('CRAWL_CLAIM_TTL_SEC')        || define('CRAWL_CLAIM_TTL_SEC', 300);  // a claim (or retry cooldown) expires after this
defined('CRAWL_PRIORITY_LANE_SHARE')  || define('CRAWL_PRIORITY_LANE_SHARE', 0.7); // rest of each round is plain oldest-first
defined('DISCOVER_FOLLOWERS_MIN')     || define('DISCOVER_FOLLOWERS_MIN', 25); // only mine "followers" of users at/above this
defined('DISCOVER_FOLLOWING_MIN')     || define('DISCOVER_FOLLOWING_MIN', 5);  // only mine "following" of users at/above this
defined('DISCOVERY_PAUSE_PENDING')    || define('DISCOVERY_PAUSE_PENDING', 20000); // queue already this long? skip discovery, count-only fetches (~4x faster)

function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

function newCurlHandle(string $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_ENCODING => '', // accept gzip/deflate, the followers page is big
        CURLOPT_USERAGENT => 'ScratchCensus/0.1 (+https://scratchnews.net/s/census - contact via ScratchNews)',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    return $ch;
}

// Fetches many URLs at once (up to CRAWL_CONCURRENCY in flight, request
// starts spaced CRAWL_REQUEST_GAP apart). $urls is key => url.
// Returns ['results' => key => ['code' => int, 'body' => ?string],
//          'rate_limited' => bool].
// code 0 = network error/timeout. A 429 stops any not-yet-started requests:
// their keys are simply missing from 'results' (caller treats that as "not
// attempted", not as a failure).
function httpMultiGet(array $urls): array {
    $results = [];
    $rateLimited = false;
    if (!$urls) return ['results' => $results, 'rate_limited' => false];

    $mh = curl_multi_init();
    $keys = array_keys($urls);
    $total = count($keys);
    $next = 0;
    $handles = []; // spl_object_id => [handle, key]
    $lastStart = 0.0;

    while (true) {
        while (count($handles) < CRAWL_CONCURRENCY && $next < $total && !$rateLimited) {
            $wait = CRAWL_REQUEST_GAP - (microtime(true) - $lastStart);
            if ($wait > 0) usleep((int)($wait * 1000000));
            $lastStart = microtime(true);
            $key = $keys[$next++];
            $ch = newCurlHandle($urls[$key]);
            curl_multi_add_handle($mh, $ch);
            $handles[spl_object_id($ch)] = [$ch, $key];
        }
        if (!$handles) break;

        do {
            $status = curl_multi_exec($mh, $running);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        if (curl_multi_select($mh, 0.5) === -1) usleep(10000);

        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $id = spl_object_id($ch);
            $key = $handles[$id][1];
            if ($info['result'] === CURLE_OK) {
                $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $results[$key] = ['code' => $code, 'body' => curl_multi_getcontent($ch)];
                if ($code === 429) $rateLimited = true;
            } else {
                $results[$key] = ['code' => 0, 'body' => null];
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            unset($handles[$id]);
        }
    }
    curl_multi_close($mh);
    return ['results' => $results, 'rate_limited' => $rateLimited];
}

function followersPageUrl(string $username): string {
    return 'https://scratch.mit.edu/users/' . rawurlencode($username) . '/followers/';
}

// Scratch has no official follower-count endpoint. The followers page's HTML
// includes "Followers (<N>)" in the tab heading - one request regardless of
// how many followers the user has. Same trick scratchattach uses.
function parseFollowerCount(?string $html): ?int {
    if ($html !== null && preg_match('/Followers\s*\((\d+)\)/i', $html, $m)) {
        return (int)$m[1];
    }
    return null;
}

function queueUsername(string $username, ?string $discoveredFrom = null, int $priority = 0): void {
    $db = getDB();
    $stmt = $db->prepare("INSERT IGNORE INTO scratchers (username, discovered_from, priority) VALUES (?, ?, ?)");
    $stmt->bind_param('ssi', $username, $discoveredFrom, $priority);
    $stmt->execute();
    $stmt->close();
}

// One INSERT IGNORE per 200 usernames instead of one per username.
// $items: list of [username, discovered_from, priority].
function queueUsernamesBulk(array $items): void {
    if (!$items) return;
    $db = getDB();
    $seen = [];
    $unique = [];
    foreach ($items as $it) {
        $k = strtolower($it[0]);
        if (isset($seen[$k])) continue;
        $seen[$k] = true;
        $unique[] = $it;
    }
    foreach (array_chunk($unique, 200) as $chunk) {
        $placeholders = implode(',', array_fill(0, count($chunk), '(?, ?, ?)'));
        $params = [];
        foreach ($chunk as $it) {
            $params[] = $it[0];
            $params[] = $it[1];
            $params[] = (int)$it[2];
        }
        $stmt = $db->prepare("INSERT IGNORE INTO scratchers (username, discovered_from, priority) VALUES $placeholders");
        $stmt->bind_param(str_repeat('ssi', count($chunk)), ...$params);
        $stmt->execute();
        $stmt->close();
    }
}

// Discovery only, not a full crawl: up to 2 pages (40 people) per direction.
// $jobs: username => ['followers' => bool, 'following' => bool, 'count' => int].
// Everything is fetched in parallel. Returns ['items' => queue items,
// 'rate_limited' => bool].
//  - "following" finds peers (big creators follow each other), so those get
//    priority = the discovering user's follower count and are crawled first.
//  - "followers" mostly finds brand-new accounts, priority 0 (still crawled
//    via the oldest-first lane, so the long tail keeps filling in).
function discoverBatch(array $jobs): array {
    $items = [];
    $rateLimited = false;
    $requests = [];
    foreach ($jobs as $username => $job) {
        foreach (['followers', 'following'] as $kind) {
            if (!empty($job[$kind])) {
                $requests[$username . '|' . $kind] = ['user' => (string)$username, 'kind' => $kind, 'offset' => 0];
            }
        }
    }

    for ($round = 0; $round < 2 && $requests && !$rateLimited; $round++) {
        $urls = [];
        foreach ($requests as $k => $rq) {
            $urls[$k] = 'https://api.scratch.mit.edu/users/' . rawurlencode($rq['user'])
                . '/' . $rq['kind'] . '?limit=20&offset=' . $rq['offset'];
        }
        $r = httpMultiGet($urls);
        if ($r['rate_limited']) $rateLimited = true;

        $next = [];
        foreach ($requests as $k => $rq) {
            $res = $r['results'][$k] ?? null;
            if (!$res || $res['code'] !== 200) continue;
            $data = json_decode((string)$res['body'], true);
            if (!is_array($data) || count($data) === 0) continue;
            $priority = $rq['kind'] === 'following' ? (int)$jobs[$rq['user']]['count'] : 0;
            foreach ($data as $u) {
                if (!empty($u['username'])) $items[] = [$u['username'], $rq['user'], $priority];
            }
            if (count($data) >= 20) {
                $next[$k] = ['user' => $rq['user'], 'kind' => $rq['kind'], 'offset' => 20];
            }
        }
        $requests = $next;
    }
    return ['items' => $items, 'rate_limited' => $rateLimited];
}

function discoveryJobFor(int $count): array {
    return [
        'followers' => $count >= DISCOVER_FOLLOWERS_MIN,
        'following' => $count >= DISCOVER_FOLLOWING_MIN,
        'count' => $count,
    ];
}

// Atomically claims up to $n pending rows: a single UPDATE stamps them with a
// random token, so cron runs and public button clicks can never grab the same
// rows. Claims expire after CRAWL_CLAIM_TTL_SEC, so a run that dies mid-way
// doesn't strand its rows. Two lanes: most rows by priority (peers of popular
// users first), the rest oldest-first so the low-priority tail isn't starved.
function claimPendingRows(int $n): array {
    $db = getDB();
    $token = bin2hex(random_bytes(8));
    $ttl = CRAWL_CLAIM_TTL_SEC;
    $priorityN = (int)ceil($n * CRAWL_PRIORITY_LANE_SHARE);
    $lanes = [['priority DESC, id ASC', $priorityN], ['id ASC', $n - $priorityN]];

    foreach ($lanes as $lane) {
        $order = $lane[0];
        $count = $lane[1];
        if ($count <= 0) continue;
        $stmt = $db->prepare("UPDATE scratchers SET claim_token = ?, claimed_at = NOW()
            WHERE status = 'pending'
              AND (claim_token IS NULL OR claimed_at < DATE_SUB(NOW(), INTERVAL ? SECOND))
            ORDER BY $order LIMIT ?");
        $stmt->bind_param('sii', $token, $ttl, $count);
        $stmt->execute();
        $stmt->close();
    }

    $stmt = $db->prepare("SELECT id, username FROM scratchers WHERE claim_token = ? AND status = 'pending'");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return ['token' => $token, 'rows' => $rows];
}

function markFetched(int $id, int $count): void {
    $db = getDB();
    $stmt = $db->prepare("UPDATE scratchers SET follower_count = ?, status = 'fetched', checked_at = NOW(), claim_token = NULL, claimed_at = NULL WHERE id = ?");
    $stmt->bind_param('ii', $count, $id);
    $stmt->execute();
    $stmt->close();
}

// Permanent: Scratch answered 404, so the account is deleted or never existed.
function markError(int $id): void {
    $db = getDB();
    $stmt = $db->prepare("UPDATE scratchers SET status = 'error', checked_at = NOW(), claim_token = NULL, claimed_at = NULL WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}

// Transient (timeout, 5xx, odd page): count a retry and park the row behind a
// claim cooldown (claim_token 'retry' + claimed_at now = ineligible until the
// claim TTL passes). status only flips to 'error' after CRAWL_MAX_RETRIES.
// status is assigned before retries on purpose: MySQL evaluates SET left to
// right, so it must see the OLD retries value.
function markRetry(int $id): void {
    $db = getDB();
    $max = CRAWL_MAX_RETRIES;
    $stmt = $db->prepare("UPDATE scratchers
        SET status = IF(retries + 1 >= ?, 'error', 'pending'),
            retries = retries + 1,
            claim_token = 'retry', claimed_at = NOW()
        WHERE id = ?");
    $stmt->bind_param('ii', $max, $id);
    $stmt->execute();
    $stmt->close();
}

function releaseClaim(string $token): void {
    $db = getDB();
    $stmt = $db->prepare("UPDATE scratchers SET claim_token = NULL, claimed_at = NULL WHERE claim_token = ? AND status = 'pending'");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->close();
}

// Last run's numbers, for cron/crawl.php's text output.
function crawlStats(?array $set = null): array {
    static $s = ['fetched' => 0, 'errors' => 0, 'retried' => 0, 'queued' => 0, 'rate_limited' => false, 'discovery' => true, 'pending_at_start' => 0];
    if ($set !== null) $s = $set;
    return $s;
}

// One round: claim rows, fetch all their follower counts in parallel, classify
// each result, then run discovery in parallel for the ones that qualify.
// Returns rows finalized (fetched or permanent error) and whether Scratch
// rate-limited us.
function crawlChunk(array $claim, array &$stats, bool $discover = true): array {
    $rows = $claim['rows'];
    $urls = [];
    foreach ($rows as $row) $urls[$row['id']] = followersPageUrl($row['username']);
    $r = httpMultiGet($urls);
    $rateLimited = $r['rate_limited'];

    $done = 0;
    $jobs = [];
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $res = $r['results'][$id] ?? null;
        if ($res === null) continue; // never attempted (rate limit stopped the run): released below, no penalty

        $count = $res['code'] === 200 ? parseFollowerCount($res['body']) : null;
        if ($count !== null) {
            markFetched($id, $count);
            $jobs[$row['username']] = discoveryJobFor($count);
            $stats['fetched']++;
            $done++;
        } elseif ($res['code'] === 404) {
            markError($id);
            $stats['errors']++;
            $done++;
        } elseif ($res['code'] === 429) {
            // rate limited: not this user's fault, leave the row alone
        } else {
            markRetry($id);
            $stats['retried']++;
        }
    }

    $jobs = array_filter($jobs, function ($j) { return $j['followers'] || $j['following']; });
    if ($discover && $jobs && !$rateLimited) {
        $d = discoverBatch($jobs);
        if ($d['rate_limited']) $rateLimited = true;
        queueUsernamesBulk($d['items']);
        $stats['queued'] += count($d['items']);
    }

    releaseClaim($claim['token']);
    return ['done' => $done, 'rate_limited' => $rateLimited];
}

// Processes up to $limit pending scratchers in rounds of CRAWL_CHUNK_SIZE,
// stopping early when the time budget runs out, the queue is empty, or Scratch
// answers 429. Returns how many rows were finalized this run.
function crawlBatch(int $limit, int $budgetSec = CRAWL_TIME_BUDGET_SEC): int {
    @set_time_limit($budgetSec + 30);
    $start = microtime(true);
    // Discovery is ~4 of every 5 requests, and it only matters while the queue
    // is short. With a long queue, fetch counts only and drain it fast.
    $pending = (int)getDB()->query("SELECT COUNT(*) AS c FROM scratchers WHERE status = 'pending'")->fetch_assoc()['c'];
    $discover = $pending < DISCOVERY_PAUSE_PENDING;
    $stats = ['fetched' => 0, 'errors' => 0, 'retried' => 0, 'queued' => 0, 'rate_limited' => false,
              'discovery' => $discover, 'pending_at_start' => $pending];
    $processed = 0;

    while ($processed < $limit && (microtime(true) - $start) < $budgetSec) {
        $claim = claimPendingRows(min(CRAWL_CHUNK_SIZE, $limit - $processed));
        if (!$claim['rows']) break;
        $res = crawlChunk($claim, $stats, $discover);
        $processed += $res['done'];
        if ($res['rate_limited']) {
            $stats['rate_limited'] = true;
            break;
        }
    }
    crawlStats($stats);
    return $processed;
}

function getScratcherCount(): int {
    $db = getDB();
    $result = $db->query("SELECT COUNT(*) AS c FROM scratchers WHERE status = 'fetched'");
    return (int)$result->fetch_assoc()['c'];
}

function getScratchersPage(int $page, int $perPage = 100): array {
    $db = getDB();
    $offset = ($page - 1) * $perPage;
    $stmt = $db->prepare("SELECT username, follower_count, checked_at FROM scratchers WHERE status = 'fetched' ORDER BY follower_count DESC, username ASC LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $perPage, $offset);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// The rank subquery mirrors the leaderboard's own ordering (follower_count
// DESC, username ASC) so a searched-up username shows its real position in
// the full list, not just a 1/2/3 within the search results. No window
// functions (ROW_NUMBER etc.) - can't assume MySQL 8 on iFastNet.
const RANK_SUBQUERY = "(SELECT COUNT(*) FROM scratchers s2 WHERE s2.status = 'fetched'
    AND (s2.follower_count > s1.follower_count
         OR (s2.follower_count = s1.follower_count AND s2.username < s1.username))) + 1";

// Partial, case-insensitive username search (the `username` query box).
// Returns ['rows' => [...with 'rank'...], 'total' => int].
function searchScratchers(string $term, int $page, int $perPage = 100): array {
    $db = getDB();
    // Bound as a parameter below, not concatenated into the SQL string, so
    // no manual escaping needed here - just wrap it for LIKE's wildcards.
    $like = '%' . $term . '%';

    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM scratchers WHERE status = 'fetched' AND username LIKE ?");
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $offset = ($page - 1) * $perPage;
    $stmt = $db->prepare("SELECT username, follower_count, checked_at, " . RANK_SUBQUERY . " AS rank
        FROM scratchers s1 WHERE status = 'fetched' AND username LIKE ?
        ORDER BY follower_count DESC, username ASC LIMIT ? OFFSET ?");
    $stmt->bind_param('sii', $like, $perPage, $offset);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return ['rows' => $rows, 'total' => $total];
}

// exact: operator - one specific username, own rank, no pagination.
// Returns null if that username has no fetched row yet (never crawled,
// still pending, or errored) so the caller can offer to crawl it.
function getExactScratcher(string $username): ?array {
    $db = getDB();
    $stmt = $db->prepare("SELECT username, follower_count, checked_at, " . RANK_SUBQUERY . " AS rank
        FROM scratchers s1 WHERE status = 'fetched' AND username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// ---- Public "crawl now" button (crawl-now.php) - lets visitors trigger a
// small batch themselves instead of waiting for the next cron run. Kept
// separate from the cron's own CRAWL_BATCH_SIZE/CRON_SECRET: this one has no
// secret (anyone can click it) so it processes far fewer usernames per click
// and is rate-limited per IP via crawl_triggers, same pattern as the main
// ScratchNews site's form_submissions rate limiting. Rows are claimed
// atomically (claimPendingRows), so overlapping clicks and cron runs never
// fetch the same row twice.
const PUBLIC_CRAWL_BATCH_SIZE = 5; // halved back down - each user now costs ~2x the requests (follower + following discovery)
const CRAWL_TRIGGER_COOLDOWN_SEC = 20; // per-IP cooldown between button clicks

function getClientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function isCrawlTriggerLimited(string $ip): bool {
    $db = getDB();
    $window = CRAWL_TRIGGER_COOLDOWN_SEC;
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM crawl_triggers WHERE ip_address = ? AND triggered_at > DATE_SUB(NOW(), INTERVAL ? SECOND)");
    $stmt->bind_param('si', $ip, $window);
    $stmt->execute();
    $cnt = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
    $stmt->close();
    return $cnt > 0;
}

// Call only after a trigger has actually run crawlBatch() - not on a
// cooldown-blocked attempt, so a blocked click doesn't reset its own cooldown.
function recordCrawlTrigger(string $ip): void {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO crawl_triggers (ip_address) VALUES (?)");
    $stmt->bind_param('s', $ip);
    $stmt->execute();
    $stmt->close();
    // Table only needs to hold one cooldown window's worth of rows.
    $db->query("DELETE FROM crawl_triggers WHERE triggered_at < DATE_SUB(NOW(), INTERVAL " . CRAWL_TRIGGER_COOLDOWN_SEC . " SECOND)");
}

// ---- Public "crawl a specific username" (crawl-user.php) - same idea as the
// batch button, but targets one username someone typed in directly, so it
// shows up immediately rather than waiting for BFS discovery to stumble onto
// them. Shares the same crawl_triggers cooldown as the batch button - both
// hit scratch.mit.edu, so no reason to let someone bypass the cooldown by
// switching between the two actions.
function isValidScratchUsername(string $username): bool {
    return (bool)preg_match('/^[A-Za-z0-9_\-.]{1,50}$/', $username);
}

function crawlSingleUsername(string $username): array {
    queueUsername($username); // ensures a row exists if this is a brand new username
    $db = getDB();

    $r = httpMultiGet(['c' => followersPageUrl($username)]);
    $res = $r['results']['c'] ?? ['code' => 0, 'body' => null];
    $count = $res['code'] === 200 ? parseFollowerCount($res['body']) : null;

    if ($count === null) {
        // Only a real 404 means "no such user". A timeout/429/5xx says nothing
        // about the account, so don't mark it error.
        if ($res['code'] === 404) {
            $stmt = $db->prepare("UPDATE scratchers SET status = 'error', checked_at = NOW() WHERE username = ?");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $stmt->close();
        }
        return ['ok' => false, 'transient' => $res['code'] !== 404];
    }

    $stmt = $db->prepare("UPDATE scratchers SET follower_count = ?, status = 'fetched', checked_at = NOW(), claim_token = NULL, claimed_at = NULL WHERE username = ?");
    $stmt->bind_param('is', $count, $username);
    $stmt->execute();
    $stmt->close();

    $job = discoveryJobFor($count);
    if ($job['followers'] || $job['following']) {
        $d = discoverBatch([$username => $job]);
        queueUsernamesBulk($d['items']);
    }

    return ['ok' => true, 'count' => $count];
}
