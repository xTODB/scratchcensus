<?php
require_once __DIR__ . '/../functions.php';

// Set up an iFastNet cron job to hit this URL (e.g. every 5 minutes):
//   https://scratchnews.net/s/census/cron/crawl.php?key=YOUR_CRON_SECRET
// Overlapping runs are safe: rows are claimed atomically.
if (!hash_equals(CRON_SECRET, $_GET['key'] ?? '')) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain');
$start = microtime(true);
$processed = crawlBatch(CRAWL_BATCH_SIZE);
$elapsed = microtime(true) - $start;
$s = crawlStats();

$pending = (int)getDB()->query("SELECT COUNT(*) AS c FROM scratchers WHERE status = 'pending'")->fetch_assoc()['c'];
$rate = $elapsed > 0 ? $processed / $elapsed : 0;

echo "Processed {$processed} scratcher(s) in " . number_format($elapsed, 1) . "s"
    . ($rate > 0 ? " (" . number_format($rate, 2) . "/s)" : "") . ".\n";
echo "fetched={$s['fetched']} errors={$s['errors']} retried={$s['retried']} queued={$s['queued']}\n";
echo "pending in queue: {$pending}\n";
echo "discovery: " . ($s['discovery'] ? "on" : "paused, count-only (queue was " . number_format($s['pending_at_start']) . ", over " . number_format(DISCOVERY_PAUSE_PENDING) . ")") . "\n";
if ($rate > 0 && $pending > 0) {
    // Rough only: assumes this run's rate holds and cron fires back-to-back,
    // which it won't (5 min apart) - just a ballpark for "is this working".
    $etaRuns = $pending / $processed;
    echo "at this rate: ~" . number_format($etaRuns) . " more runs to clear current queue\n";
}
if ($s['rate_limited']) echo "Scratch returned 429 - stopped early.\n";
