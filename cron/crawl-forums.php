<?php
require_once __DIR__ . '/../forums-functions.php';
require_once __DIR__ . '/../stats-data.php';

// Forum crawler cron. Same two ways to run it as cron/crawl-studios.php:
//   URL cron: https://scratchnews.net/s/census/cron/crawl-forums.php?key=YOUR_CRON_SECRET
//   CLI cron: /usr/bin/php /home/YOURUSER/public_html/s/census/cron/crawl-forums.php
// Every minute is fine; the run stops itself after FORUM_TIME_BUDGET_SEC.
//
// Extra URL options (need ?key=):
//   &reindex=1   re-read the forum list (/discuss/) right now
//   &probe=4     print the first topics parsed from forum 4's first page,
//                and the posts parsed from &topic=ID (use it to check scraping)
$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    if (!hash_equals(CRON_SECRET, $_GET['key'] ?? '')) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) http_response_code(500);
        echo "FATAL: {$err['message']} in {$err['file']}:{$err['line']}\n";
    }
});

try {
    if (!$isCli && isset($_GET['probe'])) {
        $fid = max(1, (int)$_GET['probe']);
        $urls = ['list' => FORUM_BASE . "/$fid/"];
        if (!empty($_GET['topic'])) $urls['topic'] = FORUM_BASE . '/topic/' . (int)$_GET['topic'] . '/';
        $r = httpMultiGet($urls);
        foreach ($urls as $k => $u) {
            $x = $r['results'][$k] ?? null;
            echo "== $k  $u\nHTTP " . ($x ? $x['code'] : 'not attempted') . "\n";
            if (!$x || $x['code'] !== 200) continue;
            if ($k === 'list') {
                $p = parseForumTopicList($x['body']);
                echo "total_pages={$p['total_pages']} topics=" . count($p['topics']) . "\n";
                foreach (array_slice($p['topics'], 0, 5) as $t) echo json_encode($t) . "\n";
            } else {
                $p = parseTopicPosts($x['body']);
                echo "posts=" . count($p) . "\n";
                foreach (array_slice($p, 0, 3) as $q) { $q['text'] = mb_substr($q['text'], 0, 120); echo json_encode($q) . "\n"; }
            }
        }
        exit;
    }

    $start = microtime(true);
    $st = crawlForumsBatch(!$isCli && !empty($_GET['reindex']));
    $elapsed = microtime(true) - $start;
    $s = getForumStatsCached();

    $rate = $elapsed > 0 ? $st['requests'] / $elapsed : 0;
    echo "Forum run: " . $st['requests'] . " request(s) in " . number_format($elapsed, 1) . "s (" . number_format($rate, 1) . "/s)\n";
    if ($st['forums_indexed']) echo "forum index read: {$st['forums_indexed']} forums\n";
    if (isset($st['index_error'])) echo "forum index FAILED: HTTP {$st['index_error']}\n";
    echo "topic lists: pages={$st['list_pages']} topics_seen={$st['topics_seen']} errors={$st['list_errors']} forums_wrapped={$st['forums_wrapped']}\n";
    echo "topic posts: pages={$st['post_pages']} posts_stored={$st['posts_stored']} errors={$st['post_errors']}\n";
    echo "in DB: forums={$s['forums']} topics={$s['topics']} posts={$s['posts']} big_topics_done={$s['big_done']}/{$s['big_topics']}\n";
    if (!FORUM_ENABLED) echo "forums: OFF (FORUM_ENABLED is false)\n";
    if (!FORUM_POSTS_ENABLED) echo "posts: OFF (FORUM_POSTS_ENABLED is false)\n";
    if ($st['rate_limited']) echo "Scratch returned 429 - stopped early.\n";
    // Keep the stats page's numbers warm (rebuilds at most every ~4 minutes, runs after the crawl work).
    if (statsCacheWarm()) echo "stats cache rebuilt\n";
} catch (\Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}
