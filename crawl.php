<?php
require_once __DIR__ . '/../functions.php';

// Two ways to run this:
//  - Web/URL cron (iFastNet's default "wget"/"curl the URL" cron type):
//      https://scratchnews.net/s/census/cron/crawl.php?key=YOUR_CRON_SECRET
//    This goes through the web server, so it's capped by ITS request
//    timeout (commonly 30-90s on shared hosting), NOT just CRAWL_TIME_BUDGET_SEC.
//    That's almost certainly what a 500 at CRAWL_TIME_BUDGET_SEC=120 means:
//    the web server killed the request before PHP finished. Raise the
//    budget gradually (e.g. try 60, then 75...) and watch for the same 500.
//  - CLI cron (if iFastNet's cron UI offers "PHP" or lets you enter a
//    command instead of a URL): use a command like
//      /usr/bin/php /home/YOURUSER/public_html/s/census/cron/crawl.php
//    CLI has no such request timeout, so this is the real fix if it's
//    available - it removes the ceiling entirely instead of guessing where
//    it is. No ?key= needed in CLI mode; it's trusted since it isn't reachable
//    over the web this way. Check cPanel's Cron Jobs page for what iFastNet
//    supports before assuming it's URL-only.
$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    if (!hash_equals(CRON_SECRET, $_GET['key'] ?? '')) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: text/plain');
}

// A bare 500 with no message (like the one that prompted this comment) is
// undiagnosable. Surface the real error instead, whatever it turns out to be
// (execution time limit, memory limit, a DB error) - both to the browser
// and, since cron output is normally sent by iFastNet, to that "cron output"
// email so it's visible even for the every-5-minutes runs nobody watches live.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) http_response_code(500);
        echo "FATAL: {$err['message']} in {$err['file']}:{$err['line']}\n";
    }
});

try {
    $start = microtime(true);
    $ids = backfillScratchIds(); // picture ids, biggest users first (about 2-3s, one parallel round)
    $start = microtime(true);
    $processed = crawlBatch(CRAWL_BATCH_SIZE);
    $elapsed = microtime(true) - $start;
    $s = crawlStats();

    $pending = (int)getDB()->query("SELECT COUNT(*) AS c FROM scratchers WHERE status = 'pending'")->fetch_assoc()['c'];
    $rate = $elapsed > 0 ? $processed / $elapsed : 0;

    echo "Processed {$processed} scratcher(s) in " . number_format($elapsed, 1) . "s"
        . ($rate > 0 ? " (" . number_format($rate, 2) . "/s)" : "") . ".\n";
    echo "fetched={$s['fetched']} errors={$s['errors']} retried={$s['retried']} queued={$s['queued']} remined={$s['remined']} refreshed={$s['refreshed']} requeued={$s['requeued']}\n";
    echo "pending in queue: {$pending}\n";
    echo "picture ids: filled={$ids['filled']} missing={$ids['missing']}" . ($ids['rate_limited'] ? " (429)" : "") . "\n";
    $hv = httpVersionSeen();
    $hvName = $hv === 0 ? 'n/a' : (defined('CURL_HTTP_VERSION_2_0') && $hv === CURL_HTTP_VERSION_2_0 ? 'HTTP/2' : 'HTTP/1.x');
    echo "count fetch: early abort " . (COUNT_EARLY_ABORT ? "on" : "off") . ", {$hvName}\n";
    if (!DISCOVERY_ENABLED) {
        echo "discovery: OFF (DISCOVERY_ENABLED is false), count-only\n";
    } else {
        echo "discovery: " . ($s['discovery'] ? "on" : "paused, count-only (queue was " . number_format($s['pending_at_start']) . ", at or over " . number_format(DISCOVERY_PAUSE_PENDING) . ")") . "\n";
    }
    if ($rate > 0 && $pending > 0) {
        // Rough only: assumes this run's rate holds and cron fires back-to-back,
        // which it won't (5 min apart) - just a ballpark for "is this working".
        $etaRuns = $pending / $processed;
        echo "at this rate: ~" . number_format($etaRuns) . " more runs to clear current queue\n";
    }
    if ($s['rate_limited']) echo "Scratch returned 429 - stopped early.\n";
} catch (\Throwable $e) {
    if (!headers_sent()) http_response_code(500);
    echo "FATAL: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\n";
}
