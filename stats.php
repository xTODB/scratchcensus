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

// Start the top-user refresh over from the beginning: every top user becomes due.
if (isset($_GET['reindex'])) {
    $n = reindexTopUsers();
    header('Location: ?key=' . rawurlencode($_GET['key']) . '&reindexed=' . $n);
    exit;
}

// Discovery settings form. Post/redirect/get so a refresh doesn't resubmit.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $locked = loadAdminSettings();
    $save = getDB()->prepare("INSERT INTO census_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
    foreach (ADMIN_SETTINGS as $k => [$label, $lo, $hi]) {
        if (isset($locked[$k]) || !isset($_POST[$k]) || !preg_match('/^\d{1,7}$/', (string)$_POST[$k])) continue;
        $v = (string)max($lo, min($hi, (int)$_POST[$k]));
        $save->bind_param('ss', $k, $v);
        $save->execute();
    }
    $save->close();
    header('Location: ?key=' . rawurlencode($_GET['key']) . '&saved=1');
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
$gaveUp = (int)$db->query("SELECT COUNT(*) AS c FROM scratchers WHERE status = 'error' AND retries >= " . (int)CRAWL_MAX_RETRIES)->fetch_assoc()['c'];
$refreshDue = (int)$db->query("SELECT COUNT(*) AS c FROM scratchers WHERE status = 'fetched' AND follower_count >= " . max(1, refreshThreshold()) . " AND checked_at < DATE_SUB(NOW(), INTERVAL " . (int)REFRESH_INTERVAL_HOURS . " HOUR)")->fetch_assoc()['c'];
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
input[type="number"] { width: 6rem; padding: 0.3rem; border-radius: 6px; border: 1px solid #444; background: #1e2023; color: #eee; text-align: right; }
input:disabled { opacity: 0.5; }
button { margin-top: 1rem; padding: 0.5rem 1rem; border-radius: 6px; border: none; background: #ffaa33; color: #17191c; font-weight: bold; font-size: 1rem; cursor: pointer; }
.note { color: #888; font-size: 0.85rem; }
</style>
</head>
<body>
    <h1>ScratchCensus Stats</h1>
    <p class="sub"><a href="/s/census/">&larr; Back to ScratchCensus</a></p>
    <?php if (isset($_GET['reindexed'])): ?><p class="note">Reindex started: <?= number_format((int)$_GET['reindexed']) ?> users are now due for a refresh.</p><?php endif; ?>
    <table>
        <tr><th>Total rows</th><td class="num"><?= number_format($total) ?></td></tr>
        <tr><th>Fetched</th><td class="num"><?= number_format($counts['fetched']) ?></td></tr>
        <tr><th>Pending</th><td class="num"><?= number_format($counts['pending']) ?></td></tr>
        <tr><th>Error (account gone)</th><td class="num"><?= number_format($counts['error'] - $gaveUp) ?></td></tr>
        <tr><th>Error (gave up after retries)</th><td class="num"><?= number_format($gaveUp) ?></td></tr>
        <tr><th>Refresh due (top <?= number_format(REFRESH_TOP_N) ?>)</th><td class="num"><?= number_format($refreshDue) ?><br><a href="?key=<?= e(rawurlencode($_GET['key'])) ?>&amp;reindex=1">reindex from 0</a></td></tr>
        <tr><th>Not yet mined</th><td class="num"><?= number_format($unmined) ?></td></tr>
        <tr><th>Discovery</th><td class="num"><?= !DISCOVERY_ENABLED ? 'OFF (DISCOVERY_ENABLED is false)' : ($discoveryOn ? 'on' : 'paused (queue over ' . number_format(DISCOVERY_PAUSE_PENDING) . ')') ?></td></tr>
        <tr><th>Last crawled</th><td class="num"><?= $lastCrawled ? e($lastCrawled) : 'never' ?></td></tr>
        <?php if ($topRow): ?>
        <tr><th>Top user</th><td class="num"><?= e($topRow['username']) ?> (<?= number_format((int)$topRow['follower_count']) ?>)</td></tr>
        <?php endif; ?>
    </table>

    <h2>Discovery settings</h2>
    <form method="post" action="?key=<?= e(rawurlencode($_GET['key'])) ?>">
        <table>
            <?php $locked = loadAdminSettings(); foreach (ADMIN_SETTINGS as $k => [$label, $lo, $hi]): ?>
            <tr>
                <th><label for="<?= e($k) ?>"><?= e($label) ?></label></th>
                <td class="num"><input type="number" id="<?= e($k) ?>" name="<?= e($k) ?>" value="<?= (int)constant($k) ?>" min="<?= $lo ?>" max="<?= $hi ?>"<?= isset($locked[$k]) ? ' disabled' : '' ?>><?= isset($locked[$k]) ? '<br><span class="note">set in config.php</span>' : '' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <button type="submit">Save</button> <?= isset($_GET['saved']) ? 'Saved.' : '' ?>
        <p class="note">Applies from the next cron run. Users already mined are not re-mined with new values.</p>
    </form>

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
