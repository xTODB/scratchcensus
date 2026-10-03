<?php
require_once __DIR__ . '/functions.php';

$ip = getClientIp();

if (isCrawlTriggerLimited($ip)) {
    header('Location: /s/census/crawl?msg=cooldown&wait=' . CRAWL_TRIGGER_COOLDOWN_SEC);
    exit;
}

$processed = crawlBatch(PUBLIC_CRAWL_BATCH_SIZE);
recordCrawlTrigger($ip);

header('Location: /s/census/crawl?msg=crawled&n=' . $processed);