<?php
// Public, read-only ScratchCensus API. Routes (all GET, JSON):
//   /api                      this list
//   /api/stats                totals
//   /api/users                ?page &limit &q &fmin &fmax        leaderboard / search
//   /api/users/<username>     one Scratcher
//   /api/studios              ?page &limit &q &fmin &fmax &access=open|closed
//   /api/studios/<id>         one studio
//   /api/forums               the forums
//   /api/topics               ?page &limit &sort=views|replies &forum=<id>
//   /api/growth               ?type=users|studios &dir=up|down &limit
// Per-IP rate limit and a short response cache: see includes/api-support.php.
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/studios-functions.php';
require_once __DIR__ . '/forums-functions.php';
require_once __DIR__ . '/includes/api-support.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    http_response_code(204);
    exit;
}
if (!API_ENABLED) apiError(503, 'The API is switched off right now.');
if ($method !== 'GET' && $method !== 'HEAD') apiError(405, 'Only GET is supported.', ['Allow' => 'GET, HEAD, OPTIONS']);

// ---- route: the part of the path after /api
$path = (string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$route = preg_match('~/api(?:\.php)?(/.*)?$~', $path, $m) ? trim($m[1] ?? '', '/') : '';
$parts = $route === '' ? [] : explode('/', $route);
$endpoint = $parts[0] ?? '';
$arg = isset($parts[1]) ? rawurldecode($parts[1]) : null;
if (count($parts) > 2) apiError(404, 'Unknown endpoint. See /s/census/api');

// ---- shared helpers
function apiRowUser(array $r): array {
    return [
        'rank' => isset($r['rank']) ? (int)$r['rank'] : null,
        'username' => $r['username'],
        'followers' => (int)$r['follower_count'],
        'change' => isset($r['delta']) && $r['delta'] !== null ? (int)$r['delta'] : null,
        'checked_at' => $r['checked_at'] ?? null,
        'profile_url' => 'https://scratch.mit.edu/users/' . rawurlencode($r['username']) . '/',
        'picture' => userPicUrl(isset($r['scratch_id']) ? (int)$r['scratch_id'] : null),
        'country' => isset($r['country']) && $r['country'] !== '' ? $r['country'] : null,
    ];
}
function apiRowStudio(array $r): array {
    return [
        'rank' => isset($r['rank']) ? (int)$r['rank'] : null,
        'id' => (int)$r['id'],
        'title' => $r['title'],
        'host' => $r['host_username'] ?? null,
        'followers' => (int)$r['follower_count'],
        'projects' => (int)$r['project_count'],
        'open_to_all' => (bool)$r['open_to_all'],
        'created_on' => $r['created_on'] ?? null,
        'change' => isset($r['delta']) && $r['delta'] !== null ? (int)$r['delta'] : null,
        'url' => 'https://scratch.mit.edu/studios/' . (int)$r['id'] . '/',
        'thumbnail' => studioPicUrl((int)$r['id']),
    ];
}
function apiPaging(int $page, int $limit, int $total): array {
    return ['page' => $page, 'limit' => $limit, 'total' => $total, 'total_pages' => max(1, (int)ceil($total / $limit))];
}
// q / fmin / fmax shared by users and studios (country only for users). Returns [search string, was a search used]
function apiSearchString(bool $withCountry = false): array {
    $q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 100));
    if ($withCountry) {
        $c = trim(str_replace('"', '', mb_substr((string)($_GET['country'] ?? ''), 0, 64)));
        if ($c !== '') $q .= ' country:"' . $c . '"';
    }
    $fmin = apiInt($_GET['fmin'] ?? null, -1, -1, 2000000000);
    $fmax = apiInt($_GET['fmax'] ?? null, -1, -1, 2000000000);
    if ($fmin >= 0) $q .= ' f>=' . $fmin;
    if ($fmax >= 0) $q .= ' f<=' . $fmax;
    return [trim($q), trim($q) !== ''];
}

// ---- handlers (each returns the array to send)
function apiUsers(?string $name): array {
    if ($name !== null) {
        if (!isValidScratchUsername($name)) apiError(400, 'That is not a valid Scratch username.');
        $row = getExactScratcher($name);
        if (!$row) apiError(404, 'That Scratcher is not tracked yet. Add them on the Crawl page.');
        return ['user' => apiRowUser($row)];
    }
    $limit = apiInt($_GET['limit'] ?? null, 50, 1, (int)API_MAX_LIMIT);
    [$qs, $search] = apiSearchString(true);
    $page = apiInt($_GET['page'] ?? null, 1, 1, $search ? 500 : 100000);
    if (!$search) {
        $total = getScratcherCount();
        $totalPages = max(1, (int)ceil($total / $limit));
        $page = min($page, $totalPages);
        $rows = getScratchersPage($page, $limit);
        foreach ($rows as $i => &$r) $r['rank'] = ($page - 1) * $limit + $i + 1;
        unset($r);
    } else {
        $p = parseSearchQuery($qs);
        if ($p['exact'] !== null) {
            $row = getExactScratcher($p['exact']);
            $rows = ($row && rowMatchesSearch($row, $p['conds'], $p['text'], $p['country'])) ? [$row] : [];
            $total = count($rows);
            $page = 1;
        } else {
            $res = searchScratchersAdvanced($p['conds'], $p['text'], $page, $limit, $p['country']);
            $rows = $res['rows'];
            $total = $res['total'];
        }
    }
    return ['paging' => apiPaging($page, $limit, $total), 'results' => array_map('apiRowUser', $rows)];
}

function apiStudios(?string $id): array {
    if ($id !== null) {
        if (!preg_match('/^\d{1,10}$/', $id)) apiError(400, 'That is not a valid studio id.');
        $stmt = getDB()->prepare("SELECT id, title, host_username, follower_count, project_count, open_to_all, created_on,
            IF(checked_at >= DATE_SUB(NOW(), INTERVAL 2 DAY), follower_delta, NULL) AS delta
            FROM studios WHERE id = ? AND status = 'fetched'");
        $sid = (int)$id;
        $stmt->bind_param('i', $sid);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) apiError(404, 'That studio is not tracked yet. Add it on the Crawl page.');
        $row['rank'] = studioRankOf((int)$row['follower_count'], (int)$row['id']);
        return ['studio' => apiRowStudio($row)];
    }
    $limit = apiInt($_GET['limit'] ?? null, 50, 1, (int)API_MAX_LIMIT);
    [$qs, $search] = apiSearchString();
    $access = in_array($_GET['access'] ?? '', ['open', 'closed'], true) ? $_GET['access'] : '';
    if ($access !== '') $qs = trim($qs . ' ' . $access);
    $filtered = $qs !== '';
    $page = apiInt($_GET['page'] ?? null, 1, 1, $filtered ? 500 : 100000);
    $parsed = $filtered ? parseStudioSearch($qs) : null;
    $res = getStudiosPage($parsed, $page, $limit);
    return ['paging' => apiPaging($page, $limit, (int)$res['total']), 'results' => array_map('apiRowStudio', $res['rows'])];
}

function apiForums(): array {
    $rows = forumRows("SELECT id, name, category, topic_count, post_count FROM forums WHERE enabled = 1 ORDER BY category, name");
    return ['results' => array_map(fn($r) => [
        'id' => (int)$r['id'], 'name' => $r['name'], 'category' => $r['category'],
        'topics' => (int)$r['topic_count'], 'posts' => (int)$r['post_count'],
        'url' => 'https://scratch.mit.edu/discuss/' . (int)$r['id'] . '/',
    ], $rows)];
}

function apiTopics(): array {
    $limit = apiInt($_GET['limit'] ?? null, 50, 1, (int)API_MAX_LIMIT);
    $page = apiInt($_GET['page'] ?? null, 1, 1, 5000);
    $sort = ($_GET['sort'] ?? '') === 'replies' ? 'replies' : 'views';
    $forum = apiInt($_GET['forum'] ?? null, 0, 0, 2000000000);
    $res = getForumTopicsPage($sort, $forum, $page, $limit);
    return ['paging' => apiPaging($page, $limit, (int)$res['total']), 'sort' => $sort, 'results' => array_map(fn($r) => [
        'rank' => (int)$r['rank'], 'id' => (int)$r['id'], 'title' => $r['title'], 'author' => $r['author'],
        'replies' => (int)$r['replies'], 'views' => (int)$r['views'], 'sticky' => (bool)$r['sticky'], 'closed' => (bool)$r['closed'],
        'forum_id' => (int)$r['forum_id'], 'forum' => $r['forum_name'],
        'url' => 'https://scratch.mit.edu/discuss/topic/' . (int)$r['id'] . '/',
    ], $res['rows'])];
}

function apiGrowth(): array {
    $type = ($_GET['type'] ?? '') === 'studios' ? 'studios' : 'users';
    $dir = ($_GET['dir'] ?? '') === 'down' ? 'down' : 'up';
    $limit = apiInt($_GET['limit'] ?? null, 100, 1, 100);
    $cmp = $dir === 'up' ? '> 0' : '< 0';
    $order = $dir === 'up' ? 'DESC' : 'ASC';
    if ($type === 'users') {
        $sql = "SELECT username, scratch_id, follower_count, follower_delta AS delta, checked_at FROM scratchers
                WHERE status = 'fetched' AND follower_delta $cmp AND checked_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
                ORDER BY follower_delta $order, username ASC LIMIT $limit";
    } else {
        $sql = "SELECT id, title, host_username, follower_count, project_count, open_to_all, created_on, follower_delta AS delta FROM studios
                WHERE status = 'fetched' AND follower_delta $cmp AND checked_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
                ORDER BY follower_delta $order, id ASC LIMIT $limit";
    }
    $rows = getDB()->query($sql)->fetch_all(MYSQLI_ASSOC);
    foreach ($rows as $i => &$r) $r['rank'] = $i + 1;
    unset($r);
    return ['type' => $type, 'direction' => $dir, 'results' => array_map($type === 'users' ? 'apiRowUser' : 'apiRowStudio', $rows)];
}

function apiCountries(): array {
    $c = getCountryChoices();
    return ['known_users' => (int)$c['known'], 'results' => array_map(fn($r) => ['country' => $r[0], 'users' => (int)$r[1]], $c['list'])];
}

function apiStats(): array {
    $f = getForumStatsCached();
    return ['users' => getScratcherCount(), 'studios' => getStudioCount(), 'forum_topics' => (int)$f['topics'], 'forum_posts_stored' => (int)$f['posts'], 'forums' => (int)$f['forums']];
}

function apiIndex(): array {
    return [
        'name' => 'ScratchCensus API',
        'about' => 'Read-only JSON about tracked Scratchers, studios and forum topics. Not affiliated with the Scratch Team.',
        'base' => 'https://scratchnews.net/s/census/api',
        'docs' => 'https://scratchnews.net/s/census/api-docs',
        'rate_limit' => ['units_per_window' => (int)API_RATE_LIMIT, 'window_seconds' => (int)API_RATE_WINDOW_SEC, 'note' => 'A plain request costs 1 unit, a search (q, fmin, fmax, country) costs 3. Responses carry X-RateLimit-* headers; over the limit you get HTTP 429 with Retry-After.'],
        'cache' => 'Responses are cached for ' . (int)API_CACHE_TTL_SEC . ' seconds.',
        'max_limit' => (int)API_MAX_LIMIT,
        'endpoints' => [
            '/users' => 'page, limit, q (search syntax like the site, e.g. exact:griffpatch or f>=100), fmin, fmax, country (e.g. Moldova)',
            '/countries' => 'every country with how many tracked Scratchers it has',
            '/users/{username}' => 'one Scratcher with rank',
            '/studios' => 'page, limit, q, fmin, fmax, access=open|closed',
            '/studios/{id}' => 'one studio with rank',
            '/forums' => 'the forums',
            '/topics' => 'page, limit, sort=views|replies, forum={id}',
            '/growth' => 'type=users|studios, dir=up|down, limit',
            '/stats' => 'totals',
        ],
        'tips' => 'Add ?pretty=1 for readable JSON. Please keep your own request rate polite and credit ScratchCensus.',
    ];
}

// ---- run: rate limit, cache, handler
$handlers = ['' => 'apiIndex', 'stats' => 'apiStats', 'forums' => 'apiForums', 'topics' => 'apiTopics', 'growth' => 'apiGrowth', 'countries' => 'apiCountries', 'users' => 'apiUsers', 'studios' => 'apiStudios'];
if (!isset($handlers[$endpoint])) apiError(404, 'Unknown endpoint. See /s/census/api');
if ($arg !== null && !in_array($endpoint, ['users', 'studios'], true)) apiError(404, 'Unknown endpoint. See /s/census/api');

$isSearch = isset($_GET['q']) && trim((string)$_GET['q']) !== '' || (isset($_GET['fmin']) && $_GET['fmin'] !== '') || (isset($_GET['fmax']) && $_GET['fmax'] !== '') || ($endpoint === 'users' && isset($_GET['country']) && trim((string)$_GET['country']) !== '');
$cost = $endpoint === '' ? 0 : (($isSearch && in_array($endpoint, ['users', 'studios'], true) && $arg === null) ? 3 : 1);
$GLOBALS['api_headers'] = ['Access-Control-Allow-Origin' => '*', 'X-RateLimit-Limit' => (string)API_RATE_LIMIT];
if ($cost > 0) {
    [$ok, $left, $reset] = apiRateCheck($cost);
    $GLOBALS['api_headers']['X-RateLimit-Remaining'] = (string)$left;
    $GLOBALS['api_headers']['X-RateLimit-Reset'] = (string)$reset;
    if (!$ok) apiError(429, 'Rate limit reached. Try again in ' . $reset . ' seconds.', ['Retry-After' => (string)max(1, $reset)]);
}

// cache key = the route plus only the parameters this endpoint understands
$known = ['users' => ['page', 'limit', 'q', 'fmin', 'fmax', 'country'], 'studios' => ['page', 'limit', 'q', 'fmin', 'fmax', 'access'],
          'topics' => ['page', 'limit', 'sort', 'forum'], 'growth' => ['type', 'dir', 'limit']];
$kp = [];
foreach ($known[$endpoint] ?? [] as $k) if (isset($_GET[$k])) $kp[$k] = (string)$_GET[$k];
ksort($kp);
$key = $endpoint . '/' . ($arg ?? '') . '?' . http_build_query($kp);

$json = $endpoint === '' ? null : apiCacheGet($key);
if ($json === null) {
    try {
        $data = $endpoint === 'users' || $endpoint === 'studios' ? $handlers[$endpoint]($arg) : $handlers[$endpoint]();
    } catch (\Throwable $e) {
        apiError(500, 'The API is having trouble right now. Try again in a moment.');
    }
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($endpoint !== '') apiCachePut($key, $json);
}

$etag = '"' . md5($json) . '"';
header('Access-Control-Allow-Origin: *');
foreach ($GLOBALS['api_headers'] as $k => $v) header("$k: $v");
header('Cache-Control: public, max-age=' . (int)API_CACHE_TTL_SEC);
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) { http_response_code(304); exit; }
header('Content-Type: application/json; charset=utf-8');
if ($method === 'HEAD') exit;
echo isset($_GET['pretty']) ? json_encode(json_decode($json, true), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : $json;
echo "\n";
