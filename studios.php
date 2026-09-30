<?php
require_once __DIR__ . '/studios-functions.php';

$perPage = 100;
$page = max(1, (int)($_GET['page'] ?? 1));
$q = trim($_GET['q'] ?? '');
$parsed = $q !== '' ? parseStudioSearch($q) : null;

$t0 = microtime(true);
$total = getStudioCount();
if ($parsed !== null) {
    $searchTotalRows = null;
    $totalForPages = getStudiosPage($parsed, 1, 1)['total'];
    $totalPages = max(1, (int)ceil($totalForPages / $perPage));
    $page = min($page, $totalPages);
    $res = getStudiosPage($parsed, $page, $perPage);
    $searchTime = microtime(true) - $t0;
} else {
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $res = getStudiosPage(null, $page, $perPage);
    $searchTime = null;
}
$studios = $res['rows'];
$found = $res['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Studios - ScratchCensus</title>
<?php require __DIR__ . '/includes/favicon.php'; ?>
<meta name="description" content="Scratch studios ranked by followers, with whether they are open to all.">
<style>
body { font-family: -apple-system, Helvetica, Arial, sans-serif; max-width: 760px; margin: 2rem auto; padding: 0 1rem; background: #17191c; color: #eee; }
h1 { margin-bottom: 0.2rem; }
p.sub { color: #999; margin-top: 0; }
table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; }
th, td { text-align: left; padding: 0.5rem 0.7rem; border-bottom: 1px solid #333; }
th { color: #999; font-size: 0.85rem; }
.rank { color: #999; width: 3rem; }
.count { text-align: right; }
a { color: #ffaa33; }
.tabs { display: flex; gap: 0.5rem; margin-top: 1rem; }
.tabs a { padding: 0.4rem 1rem; border-radius: 20px; border: 1px solid #444; text-decoration: none; color: #eee; }
.tabs a.on { background: #ffaa33; color: #17191c; border-color: #ffaa33; font-weight: bold; }
.control-row { display: flex; gap: 0.5rem; margin-top: 1rem; flex-wrap: wrap; }
.control-row input[type="text"] { flex: 1; min-width: 10rem; padding: 0.5rem 0.7rem; border-radius: 6px; border: 1px solid #444; background: #1e2023; color: #eee; }
.control-row button { padding: 0.5rem 1rem; border-radius: 6px; border: none; background: #ffaa33; color: #17191c; font-weight: bold; font-size: 1rem; cursor: pointer; }
.search-meta { color: #888; font-size: 0.85rem; margin: 0.6rem 0 0; }
.tag { display: inline-block; padding: 0.1rem 0.5rem; border-radius: 10px; font-size: 0.75rem; border: 1px solid #444; }
.tag.open { color: #6fdc8c; border-color: #2f6b40; }
.tag.closed { color: #999; }
.muted { color: #888; font-size: 0.85rem; }
.page-jump { display: flex; align-items: center; gap: 0.6rem; margin-top: 1.5rem; flex-wrap: wrap; }
.page-jump a { padding: 0.4rem 0.9rem; border-radius: 20px; border: 1px solid #444; text-decoration: none; }
.page-jump a.disabled { color: #555; border-color: #333; pointer-events: none; }
.page-jump input[type="number"] { width: 3.5rem; text-align: center; padding: 0.3rem; border-radius: 6px; border: 1px solid #444; background: #1e2023; color: #eee; }
</style>
</head>
<body>
    <h1>ScratchCensus</h1>
    <p class="sub">Scratch studios, by followers. <?= number_format($total) ?> tracked so far.</p>

    <div class="tabs">
        <a href="/s/census/">Users</a>
        <a class="on" href="/s/census/studios">Studios</a>
    </div>

    <form class="control-row" method="get">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search studio title... (open, closed, id:56, f&gt;=100)" maxlength="80">
        <button type="submit">Search</button>
    </form>

    <?php if ($q !== ''): ?>
        <p class="search-meta">
            <?= number_format($found) ?> result<?= $found === 1 ? '' : 's' ?> found in <?= number_format($searchTime, 2) ?> seconds
            &middot; <a href="/s/census/studios">clear search</a>
        </p>
    <?php endif; ?>

    <?php if (!$studios): ?>
        <p><?= $q !== '' ? 'No matches.' : "No studios yet - the studio crawler hasn't produced results." ?></p>
    <?php else: ?>
    <table>
        <thead>
            <tr><th class="rank">#</th><th>Studio</th><th>Host</th><th>Access</th><th class="count">Projects</th><th class="count">Followers</th></tr>
        </thead>
        <tbody>
            <?php foreach ($studios as $s): ?>
            <tr>
                <td class="rank">#<?= (int)$s['rank'] ?></td>
                <td><a href="https://scratch.mit.edu/studios/<?= (int)$s['id'] ?>/" target="_blank" rel="noopener"><?= e($s['title'] !== null && $s['title'] !== '' ? $s['title'] : 'Studio ' . $s['id']) ?></a> <span class="muted">#<?= (int)$s['id'] ?></span></td>
                <td><?php if (!empty($s['host_username'])): ?><a href="https://scratch.mit.edu/users/<?= e($s['host_username']) ?>/" target="_blank" rel="noopener"><?= e($s['host_username']) ?></a><?php else: ?><span class="muted">-</span><?php endif; ?></td>
                <td><span class="tag <?= $s['open_to_all'] ? 'open' : 'closed' ?>"><?= $s['open_to_all'] ? 'Open' : 'Closed' ?></span></td>
                <td class="count"><?= (int)$s['project_count'] >= 100 ? '100+' : number_format((int)$s['project_count']) ?></td>
                <td class="count"><?= number_format((int)$s['follower_count']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
    <form class="page-jump" method="get">
        <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
        <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="?<?= http_build_query(array_filter(['q' => $q, 'page' => $page - 1])) ?>">&larr; Prev</a>
        <span>Page <input type="number" name="page" min="1" max="<?= $totalPages ?>" value="<?= $page ?>" onchange="this.value = Math.max(1, Math.min(<?= $totalPages ?>, this.value || 1)); this.form.submit()"> out of <?= $totalPages ?></span>
        <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="?<?= http_build_query(array_filter(['q' => $q, 'page' => $page + 1])) ?>">Next &rarr;</a>
    </form>
    <?php endif; ?>

    <?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>