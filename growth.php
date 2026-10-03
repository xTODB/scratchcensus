<?php
require_once __DIR__ . '/studios-functions.php';

// Biggest follower changes since each item's latest daily check. Only the top
// REFRESH_TOP_N users / STUDIO_REFRESH_TOP_N studios are re-checked, so only they
// can show up. The change is only kept for 2 days after the check that made it.
$type = ($_GET['type'] ?? '') === 'studios' ? 'studios' : 'users';
$dir = ($_GET['dir'] ?? '') === 'down' ? 'down' : 'up';
$limit = 100;

$db = getDB();
$cmp = $dir === 'up' ? '> 0' : '< 0';
$order = $dir === 'up' ? 'DESC' : 'ASC';
if ($type === 'users') {
    $sql = "SELECT username, follower_count, follower_delta AS delta FROM scratchers
            WHERE status = 'fetched' AND follower_delta $cmp AND checked_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
            ORDER BY follower_delta $order, username ASC LIMIT $limit";
} else {
    $sql = "SELECT id, title, follower_count, follower_delta AS delta FROM studios
            WHERE status = 'fetched' AND follower_delta $cmp AND checked_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)
            ORDER BY follower_delta $order, id ASC LIMIT $limit";
}
$t0 = microtime(true);
$rows = $db->query($sql)->fetch_all(MYSQLI_ASSOC);
$took = microtime(true) - $t0;

function growthUrl(string $type, string $dir): string {
    return '/s/census/growth?' . http_build_query(['type' => $type, 'dir' => $dir]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Growth - ScratchCensus</title>
<?php require __DIR__ . '/includes/favicon.php'; ?>
<meta name="description" content="Scratchers and studios that gained or lost the most followers since their latest daily check.">
<style>
body { font-family: -apple-system, Helvetica, Arial, sans-serif; max-width: 760px; margin: 2rem auto; padding: 0 1rem; background: #17191c; color: #eee; }
h1 { margin-bottom: 0.2rem; }
p.sub { color: #999; margin-top: 0; }
table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; }
th, td { text-align: left; padding: 0.5rem 0.7rem; border-bottom: 1px solid #333; }
th { color: #999; font-size: 0.85rem; }
.rank { color: #999; width: 3rem; }
.count { text-align: right; white-space: nowrap; }
a { color: #ffaa33; }
.tabs { display: flex; gap: 0.5rem; margin-top: 1rem; flex-wrap: wrap; }
.tabs a { padding: 0.4rem 1rem; border-radius: 20px; border: 1px solid #444; text-decoration: none; color: #eee; }
.tabs a.on { background: #ffaa33; color: #17191c; border-color: #ffaa33; font-weight: bold; }
.delta { font-weight: 600; }
.delta.up { color: #4cd964; }
.delta.down { color: #ff5c5c; }
.muted { color: #888; font-size: 0.85rem; }
</style>
</head>
<body>
    <h1>ScratchCensus</h1>
    <p class="sub">Who is gaining and losing followers.</p>

    <div class="tabs">
        <a href="/s/census/">Users</a>
        <a href="/s/census/studios">Studios</a>
        <a href="/s/census/forums">Forums</a>
        <a class="on" href="/s/census/growth">Growth</a>
    </div>
    <div class="tabs">
        <a class="<?= $type === 'users' ? 'on' : '' ?>" href="<?= e(growthUrl('users', $dir)) ?>">Scratchers</a>
        <a class="<?= $type === 'studios' ? 'on' : '' ?>" href="<?= e(growthUrl('studios', $dir)) ?>">Studios</a>
        <a class="<?= $dir === 'up' ? 'on' : '' ?>" href="<?= e(growthUrl($type, 'up')) ?>">Gaining</a>
        <a class="<?= $dir === 'down' ? 'on' : '' ?>" href="<?= e(growthUrl($type, 'down')) ?>">Losing</a>
    </div>
    <p class="muted">Change since each one's latest daily check, top <?= $type === 'users' ? number_format(REFRESH_TOP_N) : number_format(STUDIO_REFRESH_TOP_N) ?> only. Shown for 2 days after the check.</p>

    <?php if (!$rows): ?>
        <p>Nothing here yet. Changes appear once the daily re-checks have run.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr><th class="rank">#</th><th><?= $type === 'users' ? 'Username' : 'Studio' ?></th><th class="count">Change</th><th class="count">%</th><th class="count">Followers</th></tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $i => $r):
                $d = (int)$r['delta'];
                $now = (int)$r['follower_count'];
                $before = $now - $d;
                $pct = $before > 0 ? $d / $before * 100 : null;
            ?>
            <tr>
                <td class="rank">#<?= $i + 1 ?></td>
                <td><?php if ($type === 'users'): ?>
                    <a href="https://scratch.mit.edu/users/<?= e($r['username']) ?>/" target="_blank" rel="noopener"><?= e($r['username']) ?></a>
                <?php else: $full = $r['title'] !== null && $r['title'] !== '' ? $r['title'] : 'Studio ' . $r['id']; ?>
                    <a href="https://scratch.mit.edu/studios/<?= (int)$r['id'] ?>/" target="_blank" rel="noopener" title="<?= e($full) ?>"><?= e(shortTitle($full)) ?></a> <span class="muted">#<?= (int)$r['id'] ?></span>
                <?php endif; ?></td>
                <td class="count"><span class="delta <?= $d > 0 ? 'up' : 'down' ?>"><?= $d < 0 ? '-' : '+' ?><?= number_format(abs($d)) ?></span></td>
                <td class="count muted"><?= $pct === null ? '-' : ($d < 0 ? '-' : '+') . number_format(abs($pct), abs($pct) < 10 ? 1 : 0) . '%' ?></td>
                <td class="count"><?= number_format($now) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
