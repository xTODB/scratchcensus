<?php
require_once __DIR__ . '/functions.php';

// No accounts on ScratchCensus, so this is gated the same way seed.php and
// cron/crawl.php already are: ?key=YOUR_CRON_SECRET from config.php. Bookmark
// this with the key in the URL. Wrong or missing key looks like a 404, same
// as those other two, so it doesn't advertise that this page even exists.
if (!hash_equals(CRON_SECRET, $_GET['key'] ?? '')) {
    http_response_code(404);
    exit;
}

$db = getDB();

$counts = ['pending' => 0, 'fetched' => 0, 'error' => 0];
$res = $db->query("SELECT status, COUNT(*) AS c FROM scratchers GROUP BY status");
while ($row = $res->fetch_assoc()) {
    $counts[$row['status']] = (int)$row['c'];
}
$total = array_sum($counts);

$discoveryOn = $counts['pending'] < DISCOVERY_PAUSE_PENDING;
$lastCrawled = $db->query("SELECT MAX(checked_at) AS t FROM scratchers")->fetch_assoc()['t'];
$topRow = $db->query("SELECT username, follower_count FROM scratchers WHERE status = 'fetched' ORDER BY follower_count DESC, username ASC LIMIT 1")->fetch_assoc();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stats - ScratchCensus</title>
<meta name="robots" content="noindex,nofollow">
<style>
body { font-family: -apple-system, Helvetica, Arial, sans-serif; max-width: 500px; margin: 2rem auto; padding: 0 1rem; background: #17191c; color: #eee; }
h1 { margin-bottom: 0.2rem; }
p.sub { color: #999; margin-top: 0; }
table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; }
th, td { text-align: left; padding: 0.5rem 0.7rem; border-bottom: 1px solid #333; }
th { color: #999; font-weight: normal; }
td.num { text-align: right; }
a { color: #ffaa33; }
</style>
</head>
<body>
    <h1>ScratchCensus Stats</h1>
    <p class="sub"><a href="/s/census/">&larr; Back to ScratchCensus</a></p>
    <table>
        <tr><th>Total rows</th><td class="num"><?= number_format($total) ?></td></tr>
        <tr><th>Fetched</th><td class="num"><?= number_format($counts['fetched']) ?></td></tr>
        <tr><th>Pending</th><td class="num"><?= number_format($counts['pending']) ?></td></tr>
        <tr><th>Error</th><td class="num"><?= number_format($counts['error']) ?></td></tr>
        <tr><th>Discovery</th><td class="num"><?= $discoveryOn ? 'on' : 'paused (queue over ' . number_format(DISCOVERY_PAUSE_PENDING) . ')' ?></td></tr>
        <tr><th>Last crawled</th><td class="num"><?= $lastCrawled ? e($lastCrawled) : 'never' ?></td></tr>
        <?php if ($topRow): ?>
        <tr><th>Top user</th><td class="num"><?= e($topRow['username']) ?> (<?= number_format((int)$topRow['follower_count']) ?>)</td></tr>
        <?php endif; ?>
    </table>
    <?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>