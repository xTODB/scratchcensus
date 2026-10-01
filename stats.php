<?php
require_once __DIR__ . '/studios-functions.php';

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

$discoveryOn = discoveryAllowed($counts['pending']);
$unmined = (int)$db->query("SELECT COUNT(*) AS c FROM scratchers WHERE status = 'fetched' AND discovered = 0 AND follower_count >= " . (int)DISCOVER_FOLLOWING_MIN)->fetch_assoc()['c'];
$lastCrawled = $db->query("SELECT MAX(checked_at) AS t FROM scratchers")->fetch_assoc()['t'];
$topRow = $db->query("SELECT username, follower_count FROM scratchers WHERE status = 'fetched' ORDER BY follower_count DESC, username ASC LIMIT 1")->fetch_assoc();

$sc = ['pending' => 0, 'fetched' => 0, 'error' => 0];
$res = $db->query("SELECT status, COUNT(*) AS c FROM studios GROUP BY status");
while ($row = $res->fetch_assoc()) {
    $sc[$row['status']] = (int)$row['c'];
}
$studioTotal = array_sum($sc);
$pc = ['pending' => 0, 'mined' => 0, 'error' => 0];
$res = $db->query("SELECT status, COUNT(*) AS c FROM studio_people GROUP BY status");
while ($row = $res->fetch_assoc()) {
    $pc[$row['status']] = (int)$row['c'];
}
$openRow = $db->query("SELECT SUM(open_to_all = 1) AS o, SUM(open_to_all = 0) AS c FROM studios WHERE status = 'fetched'")->fetch_assoc();
$studioLast = $db->query("SELECT MAX(checked_at) AS t FROM studios")->fetch_assoc()['t'];
$topStudio = $db->query("SELECT id, title, follower_count FROM studios WHERE status = 'fetched' ORDER BY follower_count DESC, id ASC LIMIT 1")->fetch_assoc();
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
h2 { margin: 2rem 0 0; font-size: 1.2rem; }
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
        <tr><th>Not yet mined</th><td class="num"><?= number_format($unmined) ?></td></tr>
        <tr><th>Discovery</th><td class="num"><?= $discoveryOn ? 'on' : 'paused (queue over ' . number_format(DISCOVERY_PAUSE_PENDING) . ')' ?></td></tr>
        <tr><th>Last crawled</th><td class="num"><?= $lastCrawled ? e($lastCrawled) : 'never' ?></td></tr>
        <?php if ($topRow): ?>
        <tr><th>Top user</th><td class="num"><?= e($topRow['username']) ?> (<?= number_format((int)$topRow['follower_count']) ?>)</td></tr>
        <?php endif; ?>
    </table>

    <h2>Studios</h2>
    <table>
        <tr><th>Total studios</th><td class="num"><?= number_format($studioTotal) ?></td></tr>
        <tr><th>Fetched</th><td class="num"><?= number_format($sc['fetched']) ?></td></tr>
        <tr><th>Pending</th><td class="num"><?= number_format($sc['pending']) ?></td></tr>
        <tr><th>Error</th><td class="num"><?= number_format($sc['error']) ?></td></tr>
        <tr><th>Open to all</th><td class="num"><?= number_format((int)$openRow['o']) ?></td></tr>
        <tr><th>Closed</th><td class="num"><?= number_format((int)$openRow['c']) ?></td></tr>
        <tr><th>People mined</th><td class="num"><?= number_format($pc['mined']) ?></td></tr>
        <tr><th>People pending</th><td class="num"><?= number_format($pc['pending']) ?></td></tr>
        <tr><th>People error</th><td class="num"><?= number_format($pc['error']) ?></td></tr>
        <tr><th>Discovery</th><td class="num"><?= !STUDIO_DISCOVERY_ENABLED ? 'off' : (studioDiscoveryAllowed($sc['pending']) ? 'on' : 'paused (queue over ' . number_format(STUDIO_DISCOVERY_PAUSE_PENDING) . ')') ?></td></tr>
        <tr><th>Last crawled</th><td class="num"><?= $studioLast ? e($studioLast) : 'never' ?></td></tr>
        <?php if ($topStudio): ?>
        <tr><th>Top studio</th><td class="num"><?= e(shortTitle((string)$topStudio['title'], 40)) ?> (<?= number_format((int)$topStudio['follower_count']) ?>)</td></tr>
        <?php endif; ?>
    </table>
    <?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
