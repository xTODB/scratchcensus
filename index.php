<?php
require_once __DIR__ . '/functions.php';

$perPage = 100;
$page = max(1, (int)($_GET['page'] ?? 1));
$total = getScratcherCount();

$q = trim($_GET['q'] ?? '');
$isExact = false;
$isFollowers = false;
$exactMissing = null; // set if exact: search finds no fetched row, for the "crawl it?" prompt
$searchTime = null;
$searchTotal = null;

$parsed = $q !== '' ? parseSearchQuery($q) : null;

if ($parsed !== null && $parsed['exact'] !== null) {
    // exact:username, optionally with f<op> / text parts that it must also pass
    $isExact = true;
    $isFollowers = (bool)$parsed['conds'];
    $exactUsername = $parsed['exact'];
    $t0 = microtime(true);
    $exactRow = getExactScratcher($exactUsername);
    $searchTime = microtime(true) - $t0;
    if ($exactRow && rowMatchesSearch($exactRow, $parsed['conds'], $parsed['text'])) {
        $scratchers = [$exactRow];
    } else {
        $scratchers = [];
        if (!$exactRow) $exactMissing = $exactUsername;
    }
    $searchTotal = count($scratchers);
    $totalPages = 1;
    $page = 1;
} elseif ($parsed !== null) {
    $isFollowers = (bool)$parsed['conds'];
    $t0 = microtime(true);
    $result = searchScratchersAdvanced($parsed['conds'], $parsed['text'], $page, $perPage);
    $searchTime = microtime(true) - $t0;
    $scratchers = $result['rows'];
    $searchTotal = $result['total'];
    $totalPages = max(1, (int)ceil($searchTotal / $perPage));
    $page = min($page, $totalPages);
} else {
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $scratchers = getScratchersPage($page, $perPage);
    // Browse mode is already in strict global order with no gaps, so the
    // rank is just its position - no need for the per-row rank subquery.
    foreach ($scratchers as $i => &$row) {
        $row['rank'] = (($page - 1) * $perPage) + $i + 1;
    }
    unset($row);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ScratchCensus - a ScratchNews Site</title>
<meta name="description" content="A comprehensive list of every Scratcher, by followers.">
<style>
body { font-family: -apple-system, Helvetica, Arial, sans-serif; max-width: 700px; margin: 2rem auto; padding: 0 1rem; background: #17191c; color: #eee; }
h1 { margin-bottom: 0.2rem; }
p.sub { color: #999; margin-top: 0; }
table { width: 100%; border-collapse: collapse; margin-top: 1.5rem; }
th, td { text-align: left; padding: 0.5rem 0.7rem; border-bottom: 1px solid #333; }
th { color: #999; font-size: 0.85rem; }
.rank { color: #999; width: 3rem; }
.count { text-align: right; }
a { color: #ffaa33; }
.flash { margin-top: 1rem; padding: 0.6rem 0.9rem; border-radius: 6px; background: #2a2d31; border: 1px solid #444; }
.control-row { display: flex; gap: 0.5rem; margin-top: 1rem; flex-wrap: wrap; }
.control-row input[type="text"] { flex: 1; min-width: 10rem; padding: 0.5rem 0.7rem; border-radius: 6px; border: 1px solid #444; background: #1e2023; color: #eee; }
.control-row button, .control-row a.btn { padding: 0.5rem 1rem; border-radius: 6px; border: none; background: #ffaa33; color: #17191c; font-weight: bold; font-size: 1rem; cursor: pointer; text-decoration: none; white-space: nowrap; }
.search-meta { color: #888; font-size: 0.85rem; margin: 0.6rem 0 0; }
.page-jump { display: flex; align-items: center; gap: 0.6rem; margin-top: 1.5rem; flex-wrap: wrap; }
.page-jump a { padding: 0.4rem 0.9rem; border-radius: 20px; border: 1px solid #444; text-decoration: none; }
.page-jump a.disabled { color: #555; border-color: #333; pointer-events: none; }
.page-jump input[type="number"] { width: 3.5rem; text-align: center; padding: 0.3rem; border-radius: 6px; border: 1px solid #444; background: #1e2023; color: #eee; }
</style>
</head>
<body>
    <h1>ScratchCensus</h1>
    <p class="sub">A comprehensive list of every Scratcher, by followers. <?= number_format($total) ?> tracked so far.</p>

    <?php
    $msg = $_GET['msg'] ?? '';
    $showFlash = in_array($msg, ['crawled', 'cooldown', 'user'], true);
    ?>
    <?php if ($msg === 'crawled'): ?>
        <div class="flash">Crawled <?= (int)($_GET['n'] ?? 0) ?> more Scratcher(s) - refresh in a moment to see them.</div>
    <?php elseif ($msg === 'cooldown'): ?>
        <div class="flash">Thanks for helping - please wait <?= (int)($_GET['wait'] ?? CRAWL_TRIGGER_COOLDOWN_SEC) ?>s before crawling again.</div>
    <?php elseif ($msg === 'user'):
        $result = $_GET['result'] ?? '';
        $u = e($_GET['u'] ?? ''); ?>
        <?php if ($result === 'added'): ?>
            <div class="flash">Added <?= $u ?> - <?= number_format((int)($_GET['c'] ?? 0)) ?> followers!</div>
        <?php elseif ($result === 'notfound'): ?>
            <div class="flash">Couldn't find <?= $u ?> (deleted, banned, or a typo?).</div>
        <?php else: ?>
            <div class="flash">That doesn't look like a valid Scratch username.</div>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($showFlash): ?>
        <script>
        (function() {
            if (window.history && window.history.replaceState) {
                var url = new URL(window.location.href);
                ['msg', 'n', 'wait', 'result', 'u', 'c'].forEach(function(k) { url.searchParams.delete(k); });
                window.history.replaceState({}, '', url.toString());
            }
        })();
        </script>
    <?php endif; ?>

    <div class="control-row">
        <a class="btn" href="/s/census/crawl-now.php">Crawl Users</a>
        <form method="post" action="/s/census/crawl-user.php" style="display: contents;">
            <input type="text" name="username" placeholder="username..." maxlength="50" required>
            <button type="submit">Crawl User</button>
        </form>
    </div>

    <form class="control-row" method="get">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search username..." maxlength="60">
        <button type="submit">Search</button>
    </form>

    <?php if ($q !== ''): ?>
        <p class="search-meta">
            <?php if ($isExact && $exactMissing !== null): ?>
                No fetched entry for <?= e($exactMissing) ?> yet (<?= number_format($searchTime * 1000) ?>ms) - <a href="#" onclick="document.getElementById('quick-crawl-form').submit(); return false;">crawl it now</a>?
            <?php else: ?>
                <?= number_format($searchTotal) ?> result<?= $searchTotal === 1 ? '' : 's' ?> found in <?= number_format($searchTime, 2) ?> seconds
                <?php if (!$isExact && !$isFollowers): ?> - <a href="?q=<?= urlencode('exact:' . $q) ?>">search exact:<?= e($q) ?> instead</a><?php endif; ?>
            <?php endif; ?>
            &middot; <a href="?">clear search</a>
        </p>
        <?php if ($isExact && $exactMissing !== null): ?>
        <form id="quick-crawl-form" method="post" action="/s/census/crawl-user.php" style="display: none;">
            <input type="hidden" name="username" value="<?= e($exactMissing) ?>">
        </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php if (!$scratchers): ?>
        <p><?= $q !== '' ? 'No matches.' : "No data yet - the crawl hasn't produced results for this page." ?></p>
    <?php else: ?>
    <table>
        <thead>
            <tr><th class="rank">#</th><th>Username</th><th class="count">Followers</th></tr>
        </thead>
        <tbody>
            <?php foreach ($scratchers as $s): ?>
            <tr>
                <td class="rank">#<?= (int)$s['rank'] ?></td>
                <td><a href="https://scratch.mit.edu/users/<?= e($s['username']) ?>/" target="_blank" rel="noopener"><?= e($s['username']) ?></a></td>
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
