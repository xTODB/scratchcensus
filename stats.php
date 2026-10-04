<?php
require_once __DIR__ . '/stats-data.php';

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

// Adds any missing database index (users, studios, forums). Safe to run again.
if (isset($_GET['indexes'])) {
    @set_time_limit(0);
    ignore_user_abort(true);
    header('Content-Type: text/plain; charset=utf-8');
    foreach (ensureSpeedIndexes() as $line) { echo $line, "\n"; flush(); }
    echo "done\n";
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

// The heavy numbers come from a short-lived cache (see stats-data.php); ?fresh=1 rebuilds them now.
$d = getStatsData(isset($_GET['fresh']));
$counts = $d['counts'];
$total = array_sum($counts);
$discoveryOn = discoveryAllowed($counts['pending']);
$gaveUp = $d['gave_up'];
$refreshDue = $d['refresh_due'];
$unmined = $d['unmined'];
$lastCrawled = $d['last_crawled'];
$topRow = $d['top_user'];
$sc = $d['studio_counts'];
$studioTotal = array_sum($sc);
$studioRefreshDue = $d['studio_refresh_due'];
$pc = $d['people'];
$openRow = ['o' => $d['studio_open']['o'], 'c' => $d['studio_open']['c']];
$studioLast = $d['studio_last'];
$topStudio = $d['top_studio'];
$fs = $d['forums'];
$topicPct = $fs['scratch_topics'] > 0 ? min(100, 100 * $fs['topics'] / $fs['scratch_topics']) : 0;
$postPct = $fs['big_topics'] > 0 ? 100 * $fs['big_done'] / $fs['big_topics'] : 0;
$cacheAge = max(0, time() - (int)$d['built_at']);
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
    <h1><?php require __DIR__ . '/includes/logo.php'; ?> <span style="font-size: 1.2rem; color: #999; vertical-align: middle;">Stats</span></h1>
    <p class="sub"><a href="/s/census/">&larr; Back to ScratchCensus</a></p>
    <?php if (isset($_GET['reindexed'])): ?><p class="note">Reindex started: <?= number_format((int)$_GET['reindexed']) ?> users are now due for a refresh.</p><?php endif; ?>
    <p class="note">Numbers are <?= $cacheAge < 5 ? 'fresh' : number_format($cacheAge) . 's old' ?> (counted in <?= e((string)$d['build_sec']) ?>s, kept for <?= (int)STATS_CACHE_SEC ?>s). <a href="?key=<?= e(rawurlencode($_GET['key'])) ?>&amp;fresh=1">Refresh now</a></p>
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

    <h2>Crawler settings</h2>
    <form method="post" action="?key=<?= e(rawurlencode($_GET['key'])) ?>">
        <table>
            <?php $locked = loadAdminSettings(); foreach (ADMIN_SETTINGS as $k => [$label, $lo, $hi]): ?>
            <tr>
                <th><label for="<?= e($k) ?>"><?= e($label) ?></label></th>
                <td class="num"><?php if ($lo === 0 && $hi === 1): ?><select id="<?= e($k) ?>" name="<?= e($k) ?>"<?= isset($locked[$k]) ? ' disabled' : '' ?>><option value="1"<?= (int)constant($k) === 1 ? ' selected' : '' ?>>On</option><option value="0"<?= (int)constant($k) === 0 ? ' selected' : '' ?>>Off</option></select><?php else: ?><input type="number" id="<?= e($k) ?>" name="<?= e($k) ?>" value="<?= (int)constant($k) ?>" min="<?= $lo ?>" max="<?= $hi ?>"<?= isset($locked[$k]) ? ' disabled' : '' ?>><?php endif; ?><?= isset($locked[$k]) ? '<br><span class="note">set in config.php</span>' : '' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <button type="submit">Save</button> <?= isset($_GET['saved']) ? 'Saved.' : '' ?>
        <p class="note">Applies from the next cron run. Users already mined are not re-mined with new values. If Scratch answers 429 to the forum crawler, it pauses by itself for a minute or two.</p>
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
        <tr><th>Refresh due (top <?= number_format(STUDIO_REFRESH_TOP_N) ?>)</th><td class="num"><?= number_format($studioRefreshDue) ?></td></tr>
        <tr><th>Last crawled</th><td class="num"><?= $studioLast ? e($studioLast) : 'never' ?></td></tr>
        <?php if ($topStudio): ?>
        <tr><th>Top studio</th><td class="num"><?= e(shortTitle((string)$topStudio['title'], 40)) ?> (<?= number_format((int)$topStudio['follower_count']) ?>)</td></tr>
        <?php endif; ?>
    </table>

    <h2>Forums</h2>
    <table>
        <tr><th>Forums tracked</th><td class="num"><?= number_format($fs['forums']) ?><?= $fs['skipped'] ? '<br><span class="note">' . number_format($fs['skipped']) . ' skipped</span>' : '' ?></td></tr>
        <tr><th>Topics stored</th><td class="num"><?= number_format($fs['topics']) ?><br><span class="note"><?= number_format($topicPct, 1) ?>% of Scratch's <?= number_format($fs['scratch_topics']) ?></span></td></tr>
        <tr><th>Posts stored (search)</th><td class="num"><?= number_format($fs['posts']) ?><br><span class="note">Scratch has <?= number_format($fs['scratch_posts']) ?> in total</span></td></tr>
        <tr><th>Big topics (<?= number_format(FORUM_POST_MIN_REPLIES) ?>+ replies)</th><td class="num"><?= number_format($fs['big_topics']) ?></td></tr>
        <tr><th>Big topics done</th><td class="num"><?= number_format($fs['big_done']) ?><br><span class="note"><?= number_format($postPct, 1) ?>%, <?= number_format($fs['big_topics'] - $fs['big_done']) ?> to go</span></td></tr>
        <tr><th>Sticky topics</th><td class="num"><?= number_format($fs['rows_sticky']) ?></td></tr>
        <tr><th>Claimed right now</th><td class="num"><?= number_format($fs['claimed']) ?></td></tr>
        <tr><th>Crawl</th><td class="num"><?= !FORUM_ENABLED ? 'OFF' : (forumCooldownLeft() > 0 ? 'paused (429), ' . forumCooldownLeft() . 's left' : 'on') ?><?= FORUM_ENABLED && !FORUM_POSTS_ENABLED ? ', posts off' : '' ?></td></tr>
        <tr><th>Forum list read</th><td class="num"><?= $fs['index_at'] ? e($fs['index_at']) : 'never' ?></td></tr>
        <tr><th>Last topic list crawled</th><td class="num"><?= $fs['last_list'] ? e($fs['last_list']) : 'never' ?></td></tr>
        <?php if ($fs['top_topic']): ?>
        <tr><th>Most viewed topic</th><td class="num"><?= e(shortTitle((string)$fs['top_topic']['title'], 40)) ?> (<?= number_format((int)$fs['top_topic']['views']) ?>)</td></tr>
        <?php endif; ?>
    </table>

    <h2>Speed</h2>
    <p class="note"><a href="?key=<?= e(rawurlencode($_GET['key'])) ?>&amp;indexes=1">Add missing database indexes</a> (users, studios, forums). Safe to run again; it skips any index that already exists.</p>
    <?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>