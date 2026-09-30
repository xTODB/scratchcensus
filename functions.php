<?php
require_once __DIR__ . '/config.php';

// ---- Crawler tuning. Each can be overridden by defining it in config.php
// (config.php is loaded first, so its value wins).
defined('CRAWL_CONCURRENCY')          || define('CRAWL_CONCURRENCY', 12);     // simultaneous requests to Scratch (was 8). Override in config.php to tune without redeploying; if a 429 shows up, drop this and raise CRAWL_REQUEST_GAP
defined('CRAWL_REQUEST_GAP')          || define('CRAWL_REQUEST_GAP', 0.06);   // min seconds between request STARTS (0.06 = max ~16 req/s; was 0.1 = 10/s, which is what capped the crawler at ~8/s)
defined('CRAWL_CHUNK_SIZE')           || define('CRAWL_CHUNK_SIZE', 60);      // rows claimed + processed per round (was 40): fewer rounds = fewer claim/write round trips and fewer end-of-round drains
defined('CRAWL_TIME_BUDGET_SEC')      || define('CRAWL_TIME_BUDGET_SEC', 45); // stop starting new rounds after this long. Back at the proven-safe 45s: the 120s test hit a 500, almost certainly iFastNet's own request timeout (unrelated to this budget's own bookkeeping) - see crawl.php for how to diagnose and raise this safely
defined('CRAWL_MAX_RETRIES')          || define('CRAWL_MAX_RETRIES', 3);      // transient failures before a row becomes 'error'
defined('CRAWL_CLAIM_TTL_SEC')        || define('CRAWL_CLAIM_TTL_SEC', 300);  // a claim (or retry cooldown) expires after this
defined('CRAWL_PRIORITY_LANE_SHARE')  || define('CRAWL_PRIORITY_LANE_SHARE', 0.7); // rest of each round is plain oldest-first
defined('DISCOVER_FOLLOWERS_MIN')     || define('DISCOVER_FOLLOWERS_MIN', 25); // only mine "followers" of users at/above this
defined('DISCOVER_FOLLOWING_MIN')     || define('DISCOVER_FOLLOWING_MIN', 5);  // only mine "following" of users at/above this
defined('DISCOVERY_ENABLED')          || define('DISCOVERY_ENABLED', true);    // master switch. false = never discover, count-only fetches always. Override in config.php: define('DISCOVERY_ENABLED', false);
defined('DISCOVERY_PAUSE_PENDING')    || define('DISCOVERY_PAUSE_PENDING', 10000); // pause discovery whenever this many rows are pending (resumes by itself once the queue drops below it). 0 = NO CAP: discovery runs no matter how long the queue is. Only applies while DISCOVERY_ENABLED is true
defined('DISCOVER_PAGE_SIZE')          || define('DISCOVER_PAGE_SIZE', 40);        // names per API request (Scratch's max; was 20, so every request now returns twice as many)
defined('DISCOVER_FOLLOWERS_MAX_PAGES')|| define('DISCOVER_FOLLOWERS_MAX_PAGES', 5); // up to 200 followers per user (was 40). Also capped by the user's real follower count, so small accounts never cost a wasted request
defined('DISCOVER_FOLLOWING_MAX_PAGES')|| define('DISCOVER_FOLLOWING_MAX_PAGES', 5); // up to 200 followed accounts per user (was 40)

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
    // HTTP/2 if this libcurl has it (falls back to 1.1 silently if not).
    if (defined('CURL_HTTP_VERSION_2TLS')) curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
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

    // One multi handle for the whole process: its connection cache survives
    // between chunks, so requests reuse open TLS connections to scratch.mit.edu
    // instead of paying a new handshake every time (the old code closed the
    // multi handle after every chunk).
    static $mh = null;
    if ($mh === null) {
        $mh = curl_multi_init();
        if (defined('CURLMOPT_MAX_HOST_CONNECTIONS')) curl_multi_setopt($mh, CURLMOPT_MAX_HOST_CONNECTIONS, CRAWL_CONCURRENCY);
        if (defined('CURLPIPE_MULTIPLEX')) curl_multi_setopt($mh, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
    }
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

// Discovery only, not a full crawl: up to DISCOVER_*_MAX_PAGES pages of
// DISCOVER_PAGE_SIZE people per direction. Followers paging is also capped by
// the user's known follower count, so nobody costs an empty extra request.
// $jobs: username => ['followers' => bool, 'following' => bool, 'count' => int].
// Everything is fetched in parallel, one round per page number. Returns
// ['items' => queue items, 'rate_limited' => bool].
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
            if (empty($job[$kind])) continue;
            if ($kind === 'followers') {
                $maxPages = min(DISCOVER_FOLLOWERS_MAX_PAGES, max(1, (int)ceil($job['count'] / DISCOVER_PAGE_SIZE)));
            } else {
                $maxPages = max(1, DISCOVER_FOLLOWING_MAX_PAGES);
            }
            $requests[$username . '|' . $kind] = ['user' => (string)$username, 'kind' => $kind, 'offset' => 0, 'page' => 1, 'max' => $maxPages];
        }
    }

    while ($requests && !$rateLimited) {
        $urls = [];
        foreach ($requests as $k => $rq) {
            $urls[$k] = 'https://api.scratch.mit.edu/users/' . rawurlencode($rq['user'])
                . '/' . $rq['kind'] . '?limit=' . DISCOVER_PAGE_SIZE . '&offset=' . $rq['offset'];
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
            // A short page means that was the last one; otherwise keep going up to the cap.
            if (count($data) >= DISCOVER_PAGE_SIZE && $rq['page'] < $rq['max']) {
                $next[$k] = ['user' => $rq['user'], 'kind' => $rq['kind'], 'offset' => $rq['offset'] + DISCOVER_PAGE_SIZE, 'page' => $rq['page'] + 1, 'max' => $rq['max']];
            }
        }
        $requests = $next;
    }
    return ['items' => $items, 'rate_limited' => $rateLimited];
}

// Single place that decides whether discovery may run right now.
function discoveryAllowed(int $pending): bool {
    if (!DISCOVERY_ENABLED) return false;
    return DISCOVERY_PAUSE_PENDING <= 0 || $pending < DISCOVERY_PAUSE_PENDING;
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

// $idToCount: id => follower_count. One query however many rows are in it,
// via CASE, instead of one UPDATE per row.
function markFetchedBulk(array $idToCount): void {
    if (!$idToCount) return;
    $db = getDB();
    $case = 'CASE id ';
    $types = '';
    $params = [];
    foreach ($idToCount as $id => $count) {
        $case .= 'WHEN ? THEN ? ';
        $types .= 'ii';
        $params[] = $id;
        $params[] = $count;
    }
    $case .= 'END';
    $ids = array_keys($idToCount);
    $inClause = implode(',', array_fill(0, count($ids), '?'));
    $types .= str_repeat('i', count($ids));
    $params = array_merge($params, $ids);

    $stmt = $db->prepare("UPDATE scratchers SET follower_count = $case,
        status = 'fetched', checked_at = NOW(), claim_token = NULL, claimed_at = NULL
        WHERE id IN ($inClause)");
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $stmt->close();
}

// Permanent: Scratch answered 404, so the account is deleted or never existed.
function markErrorBulk(array $ids): void {
    if (!$ids) return;
    $db = getDB();
    $inClause = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("UPDATE scratchers SET status = 'error', checked_at = NOW(), claim_token = NULL, claimed_at = NULL WHERE id IN ($inClause)");
    $stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
    $stmt->execute();
    $stmt->close();
}

// Transient (timeout, 5xx, odd page): count a retry and park the row behind a
// claim cooldown (claim_token 'retry' + claimed_at now = ineligible until the
// claim TTL passes). status only flips to 'error' after CRAWL_MAX_RETRIES.
// status is assigned before retries on purpose: MySQL evaluates SET left to
// right, so it must see the OLD retries value. retries is a per-row column
// already, so unlike follower_count this needs no CASE to batch it - the
// same SET clause is correct for every matched row.
function markRetryBulk(array $ids): void {
    if (!$ids) return;
    $db = getDB();
    $max = CRAWL_MAX_RETRIES;
    $inClause = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("UPDATE scratchers
        SET status = IF(retries + 1 >= ?, 'error', 'pending'),
            retries = retries + 1,
            claim_token = 'retry', claimed_at = NOW()
        WHERE id IN ($inClause)");
    $stmt->bind_param('i' . str_repeat('i', count($ids)), $max, ...$ids);
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
    $toFetch = []; // id => count
    $toError = []; // ids
    $toRetry = []; // ids
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        $res = $r['results'][$id] ?? null;
        if ($res === null) continue; // never attempted (rate limit stopped the run): released below, no penalty

        $count = $res['code'] === 200 ? parseFollowerCount($res['body']) : null;
        if ($count !== null) {
            $toFetch[$id] = $count;
            $jobs[$row['username']] = discoveryJobFor($count);
            $stats['fetched']++;
            $done++;
        } elseif ($res['code'] === 404) {
            $toError[] = $id;
            $stats['errors']++;
            $done++;
        } elseif ($res['code'] === 429) {
            // rate limited: not this user's fault, leave the row alone
        } else {
            $toRetry[] = $id;
            $stats['retried']++;
        }
    }
    // At most 3 queries for the whole chunk instead of one per row.
    markFetchedBulk($toFetch);
    markErrorBulk($toError);
    markRetryBulk($toRetry);

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
    // Discovery is most of the requests, and it only matters once the queue is
    // nearly empty. Re-checked before every round so it switches on the moment
    // the queue drains mid-batch. 'discovery' in the stats means "at least one
    // round ran with it on".
    $pending = (int)getDB()->query("SELECT COUNT(*) AS c FROM scratchers WHERE status = 'pending'")->fetch_assoc()['c'];
    $stats = ['fetched' => 0, 'errors' => 0, 'retried' => 0, 'queued' => 0, 'rate_limited' => false,
              'discovery' => false, 'pending_at_start' => $pending];
    $processed = 0;
    $first = true;

    while ($processed < $limit && (microtime(true) - $start) < $budgetSec) {
        if (!$first && DISCOVERY_PAUSE_PENDING > 0) { // no cap = no need to recount every round
            $pending = (int)getDB()->query("SELECT COUNT(*) AS c FROM scratchers WHERE status = 'pending'")->fetch_assoc()['c'];
        }
        $first = false;
        $discover = discoveryAllowed($pending);
        if ($discover) $stats['discovery'] = true;
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

// Ranks mirror the leaderboard's own ordering (follower_count DESC, username
// ASC), so a searched-up username shows its real position in the full list.
// No window functions (ROW_NUMBER etc.) - can't assume MySQL 8 on iFastNet.
//
// The old version ran a correlated COUNT(*) subquery once per result row (up
// to 100 per page), each scanning up to the whole table: seconds at 100k+
// rows. Now:
//  - rankOf(): one row's rank, two OR-free range counts on
//    idx_status_followers_username (status, follower_count, username).
//  - f= / f< / f> searches return one CONTIGUOUS block of the leaderboard, so
//    only the first row on the page needs rankOf(); the rest are +1 each.
//  - username searches (not contiguous) use one follower_count histogram for
//    "how many are above this count" plus a tie-break count only for rows that
//    share their follower count with someone else.
function rankOf(int $followers, string $username): int {
    $db = getDB();
    $stmt = $db->prepare("SELECT
        (SELECT COUNT(*) FROM scratchers WHERE status = 'fetched' AND follower_count > ?)
      + (SELECT COUNT(*) FROM scratchers WHERE status = 'fetched' AND follower_count = ? AND username < ?)
      + 1 AS r");
    $stmt->bind_param('iis', $followers, $followers, $username);
    $stmt->execute();
    $r = (int)$stmt->get_result()->fetch_assoc()['r'];
    $stmt->close();
    return $r;
}

// follower_count => how many fetched rows have exactly that count. One
// covering-index scan; a few thousand distinct values, so the result is small.
function getFollowerHistogram(): array {
    $db = getDB();
    $res = $db->query("SELECT follower_count, COUNT(*) AS c FROM scratchers WHERE status = 'fetched' GROUP BY follower_count");
    $hist = [];
    while ($row = $res->fetch_assoc()) $hist[(int)$row['follower_count']] = (int)$row['c'];
    return $hist;
}

// Adds 'rank' to rows that are NOT a contiguous slice of the leaderboard.
function attachRanks(array &$rows): void {
    if (!$rows) return;
    $hist = getFollowerHistogram();
    krsort($hist);
    $above = []; // follower_count => rows with a strictly higher count
    $run = 0;
    foreach ($hist as $fc => $c) {
        $above[$fc] = $run;
        $run += $c;
    }
    $db = getDB();
    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM scratchers WHERE status = 'fetched' AND follower_count = ? AND username < ?");
    foreach ($rows as &$row) {
        $fc = (int)$row['follower_count'];
        $ties = 0;
        if (($hist[$fc] ?? 1) > 1) { // only rows sharing a count need the tie-break query
            $stmt->bind_param('is', $fc, $row['username']);
            $stmt->execute();
            $ties = (int)$stmt->get_result()->fetch_assoc()['c'];
        }
        $row['rank'] = ($above[$fc] ?? 0) + $ties + 1;
    }
    unset($row);
    $stmt->close();
}

// The list queries below sort with follower_count DESC + username ASC, which
// MySQL can't do straight off the index, so it sorts. Selecting only indexed
// columns keeps that sort index-only; checked_at (not in the index) is then
// looked up for just the <=100 rows on the page instead of every matched row.
function attachCheckedAt(array &$rows): void {
    if (!$rows) return;
    $names = array_column($rows, 'username');
    $db = getDB();
    $in = implode(',', array_fill(0, count($names), '?'));
    $stmt = $db->prepare("SELECT username, checked_at FROM scratchers WHERE username IN ($in)");
    $stmt->bind_param(str_repeat('s', count($names)), ...$names);
    $stmt->execute();
    $map = [];
    foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $map[$r['username']] = $r['checked_at'];
    $stmt->close();
    foreach ($rows as &$row) $row['checked_at'] = $map[$row['username']] ?? null;
    unset($row);
}

// Escapes LIKE's own wildcards (% and _) plus the escape character itself, so
// a literal "_" - a normal character in Scratch usernames - or "%" in a
// search term is matched literally instead of as a wildcard. Backslash is
// LIKE's default escape character, no ESCAPE clause needed.
function likeEscape(string $s): string {
    return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

// ---- Combined search. One query string can mix any of:
//   f>=12 f<=15     follower-count comparisons (=, <, <=, >, >=), all must hold
//   exact:username  that one user only (still has to pass the other parts)
//   anything else   partial, case-insensitive username match
// e.g. "f>=12 f<=15", "f<=300 a", "exact:griffpatch f=787134".
// Returns ['conds' => [[op, int], ...], 'exact' => ?string, 'text' => string].
function parseSearchQuery(string $q): array {
    $conds = [];
    $q = preg_replace_callback('/(?<![\w-])f\s*(<=|>=|=|<|>)\s*(\d{1,10})(?![\w-])/i', function ($m) use (&$conds) {
        $conds[] = [$m[1], (int)$m[2]];
        return ' ';
    }, $q);
    $exact = null;
    $q = preg_replace_callback('/(?<!\S)exact:(\S*)/i', function ($m) use (&$exact) {
        if ($m[1] !== '') $exact = $m[1];
        return ' ';
    }, $q);
    $text = trim(preg_replace('/\s+/', ' ', $q));
    return ['conds' => $conds, 'exact' => $exact, 'text' => $text];
}

// Does an already-loaded row pass the follower comparisons and text part?
// (Used for exact:, where the row is fetched by name first.)
function rowMatchesSearch(array $row, array $conds, string $text): bool {
    $fc = (int)$row['follower_count'];
    foreach ($conds as [$op, $v]) {
        if ($op === '=' && !($fc == $v)) return false;
        if ($op === '<' && !($fc < $v)) return false;
        if ($op === '<=' && !($fc <= $v)) return false;
        if ($op === '>' && !($fc > $v)) return false;
        if ($op === '>=' && !($fc >= $v)) return false;
    }
    return $text === '' || stripos($row['username'], $text) !== false;
}

// $conds ops are whitelisted here - never interpolate a raw user string as an
// operator, it isn't a bound parameter. Returns [where, types, params].
function buildSearchWhere(array $conds, string $text): array {
    $where = "status = 'fetched'";
    $types = '';
    $params = [];
    foreach ($conds as [$op, $v]) {
        if (!in_array($op, ['=', '<', '<=', '>', '>='], true)) continue;
        $where .= " AND follower_count $op ?";
        $types .= 'i';
        $params[] = $v;
    }
    if ($text !== '') {
        $where .= " AND username LIKE ?";
        $types .= 's';
        $params[] = '%' . likeEscape($text) . '%';
    }
    return [$where, $types, $params];
}

// Same shape as before: ['rows' => [...with 'rank'...], 'total' => int].
// With only follower comparisons the matches are one CONTIGUOUS block of the
// leaderboard (rank the first row, the rest are +1 each). Adding a username
// part breaks that, so those use the histogram ranks.
function searchScratchersAdvanced(array $conds, string $text, int $page, int $perPage = 100): array {
    $db = getDB();
    [$where, $types, $params] = buildSearchWhere($conds, $text);

    $stmt = $db->prepare("SELECT COUNT(*) AS c FROM scratchers WHERE $where");
    if ($params) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $total = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();

    $offset = ($page - 1) * $perPage;
    $stmt = $db->prepare("SELECT username, follower_count FROM scratchers WHERE $where
        ORDER BY follower_count DESC, username ASC LIMIT ? OFFSET ?");
    $stmt->bind_param($types . 'ii', ...array_merge($params, [$perPage, $offset]));
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    attachCheckedAt($rows);
    if ($rows) {
        if ($text === '') {
            $first = rankOf((int)$rows[0]['follower_count'], $rows[0]['username']);
            foreach ($rows as $i => &$row) $row['rank'] = $first + $i;
            unset($row);
        } else {
            attachRanks($rows);
        }
    }
    return ['rows' => $rows, 'total' => $total];
}

// exact: operator - one specific username, own rank, no pagination.
// Returns null if that username has no fetched row yet (never crawled,
// still pending, or errored) so the caller can offer to crawl it.
function getExactScratcher(string $username): ?array {
    $db = getDB();
    $stmt = $db->prepare("SELECT username, follower_count, checked_at
        FROM scratchers WHERE status = 'fetched' AND username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $row['rank'] = rankOf((int)$row['follower_count'], $row['username']);
    return $row;
}

// ---- Public "crawl now" button (crawl-now.php) - lets visitors trigger a
// small batch themselves instead of waiting for the next cron run. Kept
// separate from the cron's own CRAWL_BATCH_SIZE/CRON_SECRET: this one has no
// secret (anyone can click it) so it processes far fewer usernames per click
// and is rate-limited per IP via crawl_triggers, same pattern as the main
// ScratchNews site's form_submissions rate limiting. Rows are claimed
// atomically (claimPendingRows), so overlapping clicks and cron runs never
// fetch the same row twice.
const PUBLIC_CRAWL_BATCH_SIZE = 150; // was 5, sized for the old per-user request cost. crawlBatch is still capped by CRAWL_TIME_BUDGET_SEC (45s) either way, so this just lets one click use that same 45s instead of stopping at 5 users
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

    // Same pause as the batch crawler: while the queue is already huge, a
    // public "Crawl User" click shouldn't add thousands more rows on top of it.
    $pending = (int)$db->query("SELECT COUNT(*) AS c FROM scratchers WHERE status = 'pending'")->fetch_assoc()['c'];
    $job = discoveryJobFor($count);
    if (($job['followers'] || $job['following']) && discoveryAllowed($pending)) {
        $d = discoverBatch([$username => $job]);
        queueUsernamesBulk($d['items']);
    }

    return ['ok' => true, 'count' => $count];
}
