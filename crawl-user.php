<?php
require_once __DIR__ . '/functions.php';

$ip = getClientIp();
$username = trim($_POST['username'] ?? $_GET['username'] ?? '');

if (isCrawlTriggerLimited($ip)) {
    header('Location: /s/census/?msg=cooldown&wait=' . CRAWL_TRIGGER_COOLDOWN_SEC);
    exit;
}

if ($username === '' || !isValidScratchUsername($username)) {
    header('Location: /s/census/?msg=user&result=invalid');
    exit;
}

$result = crawlSingleUsername($username);
recordCrawlTrigger($ip);

if ($result['ok']) {
    header('Location: /s/census/?msg=user&result=added&u=' . urlencode($username) . '&c=' . (int)$result['count']);
} else {
    header('Location: /s/census/?msg=user&result=notfound&u=' . urlencode($username));
}
