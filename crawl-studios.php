<?php
require_once __DIR__ . '/../studios-functions.php';

// Studio crawler cron. Same two ways to run it as cron/crawl.php:
//   URL cron: https://scratchnews.net/s/census/cron/crawl-studios.php?key=YOUR_CRON_SECRET
//   CLI cron: /usr/bin/php /home/YOURUSER/public_html/s/census/cron/crawl-studios.php
// Every minute is fine; the run stops itself after STUDIO_TIME_BUDGET_SEC.
//
// Extra URL options (all need ?key=):
//   &probe=56            print the raw API answers for studio 56 (studio,
//                        managers, curators, activity, and the curate list of
//                        &u=USERNAME). Use it to check the endpoints work.
//   &seed=56,123,456     queue extra studio ids by hand.
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
        $id = max(1, (int)$_GET['probe']);
        $u = trim((string)($_GET['u'] ?? 'ScratchCat'));
        $urls = [
            'studio'    => STUDIO_API . "/studios/$id",
            'managers'  => STUDIO_API . "/studios/$id/managers?limit=2",
            'curators'  => STUDIO_API . "/studios/$id/curators?limit=2",
            'activity'  => STUDIO_API . "/studios/$id/activity?limit=2",
            'curate'    => STUDIO_API . '/users/' . rawurlencode($u) . '/studios/curate?limit=2',
        ];
        $r = httpMultiGet($urls);
        foreach ($urls as $k => $url) {
            $x = $r['results'][$k] ?? null;
            echo "== $k  $url\n";
            echo $x ? ("HTTP {$x['code']}\n" . substr((string)$x['body'], 0, 700) . "\n\n") : "(not attempted)\n\n";
        }
        exit;
    }

    if (!$isCli && !empty($_GET['seed'])) {
        $items = [];
        foreach (explode(',', $_GET['seed']) as $s) {
            if ((int)$s > 0) $items[] = [(int)$s, 'manual', 2000000000];
        }
        echo "Queued " . queueStudios($items) . " new studio id(s) from seed.\n";
    }

    $start = microtime(true);
    $st = crawlStudiosBatch();
    $elapsed = microtime(true) - $start;

    $db = getDB();
    $sc = ['pending' => 0, 'fetched' => 0, 'error' => 0];
    foreach ($db->query("SELECT status, COUNT(*) AS c FROM studios GROUP BY status") as $row) $sc[$row['status']] = (int)$row['c'];
    $pc = ['pending' => 0, 'mined' => 0, 'error' => 0];
    foreach ($db->query("SELECT status, COUNT(*) AS c FROM studio_people GROUP BY status") as $row) $pc[$row['status']] = (int)$row['c'];

    $rate = $elapsed > 0 ? $st['requests'] / $elapsed : 0;
    echo "Studio run: " . $st['requests'] . " request(s) in " . number_format($elapsed, 1) . "s (" . number_format($rate, 1) . "/s)\n";
    echo "studios fetched={$st['studios_fetched']} errors={$st['studios_errors']} retried={$st['studios_retried']} new_studios_queued={$st['studios_queued']}\n";
    echo "people mined={$st['people_mined']} errors={$st['people_errors']} new_people_queued={$st['people_queued']}\n";
    echo "studios in DB: fetched={$sc['fetched']} pending={$sc['pending']} error={$sc['error']}\n";
    echo "people in DB: mined={$pc['mined']} pending={$pc['pending']} error={$pc['error']}\n";
    echo "discovery: " . (STUDIO_DISCOVERY_ENABLED ? "on" : "OFF (STUDIO_DISCOVERY_ENABLED is false)") . "\n";
    if ($st['rate_limited']) echo "Scratch returned 429 - stopped early.\n";

    $top = $db->query("SELECT id, title, follower_count, open_to_all FROM studios WHERE status = 'fetched' ORDER BY follower_count DESC LIMIT 5");
    echo "top studios by followers:\n";
    foreach ($top as $t) {
        echo "  #{$t['id']} " . ($t['title'] ?? '') . " - " . number_format((int)$t['follower_count']) . " followers, " . ($t['open_to_all'] ? 'open' : 'closed') . "\n";
    }
} catch (\Throwable $e) {
    if (!headers_sent()) http_response_code(500);
    echo "FATAL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}
