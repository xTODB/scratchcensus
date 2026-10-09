<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../studios-functions.php';

// Page cache pre-warm cron. Keeps the busiest pages cached so visitors always get an instant copy.
// Two ways to run it, same as the other crons (every minute is right):
//   URL cron: https://scratchnews.net/s/census/cron/warm-cache.php?key=YOUR_CRON_SECRET
//   CLI cron: /usr/bin/php /home/YOURUSER/public_html/s/census/cron/warm-cache.php
//
// It visits each page with ?warm=CRON_SECRET. A page whose copy is younger than PAGE_CACHE_WARM_AGE_SEC
// answers "fresh" straight away; an older one is rebuilt (and the visit is not logged as a visitor).
// Override any of these in config.php:
defined('WARM_BASE_URL')          || define('WARM_BASE_URL', 'https://scratchnews.net/s/census/'); // where the site lives (ends with /)
defined('WARM_PAGES_PER_LIST')    || define('WARM_PAGES_PER_LIST', 3);    // pages 1..N of the Users and Studios lists
defined('WARM_TIME_BUDGET_SEC')   || define('WARM_TIME_BUDGET_SEC', 40);  // stop starting new pages after this long
defined('WARM_ENABLED')           || define('WARM_ENABLED', true);

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
    if (!WARM_ENABLED) { echo "warm: OFF (WARM_ENABLED is false)\n"; exit; }
    if (defined('PAGE_CACHE_ENABLED') && !PAGE_CACHE_ENABLED) { echo "warm: nothing to do, the page cache is off\n"; exit; }

    // Same pages the home screen links to first. Query strings match what index.php builds its cache keys from.
    $pages = [];
    $n = max(1, min(20, (int)WARM_PAGES_PER_LIST));
    for ($p = 1; $p <= $n; $p++) $pages['users-' . $p] = $p > 1 ? ['page' => $p] : [];
    for ($p = 1; $p <= $n; $p++) $pages['studios-' . $p] = ['c' => 'studios'] + ($p > 1 ? ['page' => $p] : []);
    foreach (['users', 'studios'] as $c) {
        $pages["growth-$c-up"]   = ['c' => $c, 'm' => 'dynamic'];
        $pages["growth-$c-down"] = ['c' => $c, 'm' => 'dynamic', 'dir' => 'down'];
    }
    $pages['forums-views']   = ['c' => 'forums'];
    $pages['forums-replies'] = ['c' => 'forums', 'sort' => 'replies'];

    // The studio follower histograms (used by every studio list and search) are cached in temp files;
    // refresh them here so no visitor has to wait for the rebuild.
    try { echo studioWarmHistograms() . "\n"; } catch (\Throwable $e) { echo "studio histograms: " . $e->getMessage() . "\n"; }

    $start = microtime(true);
    $warmed = $fresh = $busy = $failed = 0;
    $skipped = 0;
    foreach ($pages as $name => $params) {
        if (microtime(true) - $start > WARM_TIME_BUDGET_SEC) { $skipped++; continue; }
        $url = rtrim(WARM_BASE_URL, '/') . '/?' . http_build_query($params + ['warm' => CRON_SECRET]);
        $state = ''; $code = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'ScratchCensus-warm/1.0',
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$state) {
                if (stripos($line, 'X-ScratchCensus-Cache:') === 0) $state = strtolower(trim(substr($line, 22)));
                return strlen($line);
            },
        ]);
        $t0 = microtime(true);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $took = number_format(microtime(true) - $t0, 2);
        if ($body === false || $code !== 200) { $failed++; echo "$name: FAILED (" . ($err !== '' ? $err : "HTTP $code") . ")\n"; continue; }
        if ($state === 'fresh') { $fresh++; continue; }
        if ($state === 'busy')  { $busy++; echo "$name: busy (being rebuilt already)\n"; continue; }
        if (trim((string)$body) === 'warmed') { $warmed++; echo "$name: rebuilt in {$took}s\n"; continue; }
        $failed++;
        echo "$name: not cached (" . substr(trim((string)$body), 0, 80) . ")\n";
    }
    echo "warm: " . count($pages) . " pages, rebuilt=$warmed fresh=$fresh busy=$busy failed=$failed"
        . ($skipped ? " skipped=$skipped (time budget)" : '') . ", " . number_format(microtime(true) - $start, 1) . "s\n";
} catch (\Throwable $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}