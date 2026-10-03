<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/studios-functions.php';
require_once __DIR__ . '/forums-functions.php';

// Public crawl buttons for studios, forum topics, forum posts, and one studio by id.
// (Users keep crawl-now.php and crawl-user.php.) Everything redirects back to /crawl with a message.
function crawlBack(array $q): void {
    header('Location: /s/census/crawl?' . http_build_query($q));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') crawlBack([]);

$ip = getClientIp();
if (isCrawlTriggerLimited($ip)) crawlBack(['msg' => 'cooldown', 'wait' => CRAWL_TRIGGER_COOLDOWN_SEC]);

$what = (string)($_POST['what'] ?? '');
@set_time_limit(90);

if ($what === 'studio') {
    $id = parseStudioIdInput((string)($_POST['studio'] ?? ''));
    if ($id === null || $id <= 0) crawlBack(['msg' => 'studio', 'result' => 'invalid']);
    recordCrawlTrigger($ip);
    $r = crawlSingleStudio($id);
    if ($r['ok']) crawlBack(['msg' => 'studio', 'result' => 'added', 'id' => $id, 'c' => $r['count'], 't' => mb_substr($r['title'], 0, 60)]);
    crawlBack(['msg' => 'studio', 'result' => $r['reason'], 'id' => $id]);
}

if ($what === 'topic') {
    $id = parseForumTopicInput((string)($_POST['topic'] ?? ''));
    if ($id === null || $id <= 0) crawlBack(['msg' => 'topic', 'result' => 'invalid']);
    if (!FORUM_ENABLED) crawlBack(['msg' => 'ran', 'what' => 'topics', 'off' => 1]);
    recordCrawlTrigger($ip);
    $r = crawlSingleForumTopic($id);
    if ($r['ok']) crawlBack(['msg' => 'topic', 'result' => $r['new'] ? 'added' : 'updated', 'id' => $id, 'c' => $r['replies'], 't' => mb_substr($r['title'], 0, 60), 'f' => mb_substr($r['forum'], 0, 60)]);
    crawlBack(['msg' => 'topic', 'result' => $r['reason'], 'id' => $id]);
}

if ($what === 'studios') {
    recordCrawlTrigger($ip);
    $st = crawlStudiosBatch(PUBLIC_STUDIO_CRAWL_SEC);
    crawlBack(['msg' => 'ran', 'what' => 'studios', 'n' => $st['studios_fetched'], 'r' => $st['studios_refreshed'], 'rl' => $st['rate_limited'] ? 1 : 0]);
}

if ($what === 'topics' || $what === 'posts') {
    recordCrawlTrigger($ip);
    $st = crawlForumsPublic($what);
    if ($st['disabled']) crawlBack(['msg' => 'ran', 'what' => $what, 'off' => 1]);
    crawlBack($what === 'topics'
        ? ['msg' => 'ran', 'what' => 'topics', 'n' => $st['topics_seen'], 'p' => $st['list_pages'], 'rl' => $st['rate_limited'] ? 1 : 0]
        : ['msg' => 'ran', 'what' => 'posts', 'n' => $st['posts_stored'], 'p' => $st['post_pages'], 'rl' => $st['rate_limited'] ? 1 : 0]);
}

crawlBack([]);
