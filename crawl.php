<?php
require_once __DIR__ . '/../functions.php';

// Set up an iFastNet cron job to hit this URL (e.g. every 5-10 minutes):
//   https://scratchnews.net/s/census/cron/crawl.php?key=YOUR_CRON_SECRET
if (!hash_equals(CRON_SECRET, $_GET['key'] ?? '')) {
    http_response_code(404);
    exit;
}

header('Content-Type: text/plain');
$processed = crawlBatch(CRAWL_BATCH_SIZE);
echo "Processed {$processed} scratcher(s).\n";