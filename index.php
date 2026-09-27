<?php
require_once __DIR__ . '/functions.php';

$perPage = 100;
$page = max(1, (int)($_GET['page'] ?? 1));
$total = getScratcherCount();
$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);
$scratchers = getScratchersPage($page, $perPage);
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
.pagination { margin-top: 1.5rem; display: flex; gap: 1rem; align-items: center; }
.crawl-btn { display: inline-block; margin-top: 0.8rem; padding: 0.5rem 1rem; background: #ffaa33; color: #17191c; font-weight: bold; text-decoration: none; border-radius: 6px; }
.crawl-note { color: #888; font-size: 0.85rem; margin: 0.4rem 0 0; }
.flash { margin-top: 1rem; padding: 0.6rem 0.9rem; border-radius: 6px; background: #2a2d31; border: 1px solid #444; }
</style>
</head>
<body>
    <h1>ScratchCensus</h1>
    <p class="sub">A comprehensive list of every Scratcher, by followers. <?= number_format($total) ?> tracked so far.</p>

    <?php if (($_GET['msg'] ?? '') === 'crawled'): ?>
        <div class="flash">Crawled <?= (int)($_GET['n'] ?? 0) ?> more Scratcher(s) - refresh in a moment to see them.</div>
    <?php elseif (($_GET['msg'] ?? '') === 'cooldown'): ?>
        <div class="flash">Thanks for helping - please wait <?= (int)($_GET['wait'] ?? CRAWL_TRIGGER_COOLDOWN_SEC) ?>s before crawling again.</div>
    <?php endif; ?>

    <a class="crawl-btn" href="/s/census/crawl-now.php">Crawl now</a>
    <p class="crawl-note">Triggers a small batch immediately instead of waiting for the next scheduled crawl. Limited to once every <?= CRAWL_TRIGGER_COOLDOWN_SEC ?>s per visitor.</p>

    <?php if (!$scratchers): ?>
        <p>No data yet - the crawl hasn't produced results for this page.</p>
    <?php else: ?>
    <table>
        <thead>
            <tr><th class="rank">#</th><th>Username</th><th class="count">Followers</th></tr>
        </thead>
        <tbody>
            <?php foreach ($scratchers as $i => $s): ?>
            <tr>
                <td class="rank"><?= (($page - 1) * $perPage) + $i + 1 ?></td>
                <td><a href="https://scratch.mit.edu/users/<?= e($s['username']) ?>/" target="_blank" rel="noopener"><?= e($s['username']) ?></a></td>
                <td class="count"><?= number_format((int)$s['follower_count']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <div class="pagination">
        <?php if ($page > 1): ?><a href="?page=<?= $page - 1 ?>">&larr; Prev</a><?php endif; ?>
        <span>Page <?= $page ?> of <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?><a href="?page=<?= $page + 1 ?>">Next &rarr;</a><?php endif; ?>
    </div>
</body>
</html>