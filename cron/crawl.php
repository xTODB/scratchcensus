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

echo "Processed {$processed} scratcher(s) in " . number_format($elapsed, 1) . "s.\n";
echo "fetched={$s['fetched']} errors={$s['errors']} retried={$s['retried']} queued={$s['queued']}\n";
echo "pending in queue: {$pending}\n";
if ($s['rate_limited']) echo "Scratch returned 429 - stopped early.\n";
