<?php
require_once __DIR__ . '/forums-functions.php';

$view = ($_GET['view'] ?? 'topics') === 'posts' ? 'posts' : 'topics';
$sort = ($_GET['sort'] ?? 'views') === 'replies' ? 'replies' : 'views';
$forumId = max(0, (int)($_GET['f'] ?? 0));
$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = $view === 'posts' ? 20 : 100;

$stats = getForumStats();
$forums = getForumChoices();
$rows = [];
$total = 0;
$terms = [];
$searchTime = null;
if ($view === 'topics') {
    $res = getForumTopicsPage($sort, $forumId, $page, $perPage);
    $totalPages = max(1, (int)ceil($res['total'] / $perPage));
    if ($page > $totalPages) { $page = $totalPages; $res = getForumTopicsPage($sort, $forumId, $page, $perPage); }
    $rows = $res['rows'];
    $total = $res['total'];
} elseif ($q !== '') {
    $t0 = microtime(true);
    $res = searchForumPosts($q, $forumId, $page, $perPage);
    $searchTime = microtime(true) - $t0;
    $totalPages = max(1, (int)ceil($res['total'] / $perPage));
    $rows = $res['rows'];
    $total = $res['total'];
    $terms = $res['terms'];
} else {
    $totalPages = 1;
}

// Keeps the current view/filters when building a link.
function forumsUrl(array $over = []): string {
    global $view, $sort, $forumId, $q, $page;
    $p = array_merge(['view' => $view, 'sort' => $sort, 'f' => $forumId, 'q' => $q, 'page' => $page], $over);
    if ($p['view'] === 'posts') unset($p['sort']); else unset($p['q']);
    if (empty($p['f'])) unset($p['f']);
    if (isset($p['q']) && $p['q'] === '') unset($p['q']);
    if (($p['page'] ?? 1) <= 1) unset($p['page']);
    if (($p['view'] ?? '') === 'topics') unset($p['view']);
    if (($p['sort'] ?? '') === 'views') unset($p['sort']);
    return '/s/census/forums' . ($p ? '?' . http_build_query($p) : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forums - ScratchCensus</title>
<?php require __DIR__ . '/includes/favicon.php'; ?>
<meta name="description" content="Scratch forum topics ranked by views and replies, plus a search over forum posts.">
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
.control-row { display: flex; gap: 0.5rem; margin-top: 1rem; flex-wrap: wrap; }
.control-row input[type="text"], .control-row select { padding: 0.5rem 0.7rem; border-radius: 6px; border: 1px solid #444; background: #1e2023; color: #eee; }
.control-row input[type="text"] { flex: 1; min-width: 10rem; }
.control-row select { max-width: 14rem; }
.control-row button { padding: 0.5rem 1rem; border-radius: 6px; border: none; background: #ffaa33; color: #17191c; font-weight: bold; font-size: 1rem; cursor: pointer; }
.search-meta { color: #888; font-size: 0.85rem; margin: 0.6rem 0 0; }
.tag { display: inline-block; padding: 0.1rem 0.5rem; border-radius: 10px; font-size: 0.75rem; border: 1px solid #444; color: #999; }
.muted { color: #888; font-size: 0.85rem; }
.post { border-bottom: 1px solid #333; padding: 0.9rem 0; }
.post .meta { color: #999; font-size: 0.85rem; margin-bottom: 0.3rem; }
.post .body { color: #ddd; line-height: 1.4; word-break: break-word; }
mark { background: #ffaa33; color: #17191c; border-radius: 2px; padding: 0 0.1rem; }
.page-jump { display: flex; align-items: center; gap: 0.6rem; margin-top: 1.5rem; flex-wrap: wrap; }
.page-jump a { padding: 0.4rem 0.9rem; border-radius: 20px; border: 1px solid #444; text-decoration: none; }
.page-jump a.disabled { color: #555; border-color: #333; pointer-events: none; }
</style>
</head>
<body>
    <h1>ScratchCensus</h1>
    <p class="sub">Scratch forums. <?= number_format($stats['topics']) ?> topics and <?= number_format($stats['posts']) ?> searchable posts tracked so far.</p>

    <div class="tabs">
        <a href="/s/census/">Users</a>
        <a href="/s/census/studios">Studios</a>
        <a class="on" href="/s/census/forums">Forums</a>
        <a href="/s/census/growth">Growth</a>
    </div>

    <div class="tabs">
        <a class="<?= $view === 'topics' ? 'on' : '' ?>" href="<?= e(forumsUrl(['view' => 'topics', 'page' => 1])) ?>">Topics</a>
        <a class="<?= $view === 'posts' ? 'on' : '' ?>" href="<?= e(forumsUrl(['view' => 'posts', 'page' => 1])) ?>">Posts</a>
        <?php if ($view === 'topics'): ?>
            <a class="<?= $sort === 'views' ? 'on' : '' ?>" href="<?= e(forumsUrl(['sort' => 'views', 'page' => 1])) ?>">Views</a>
            <a class="<?= $sort === 'replies' ? 'on' : '' ?>" href="<?= e(forumsUrl(['sort' => 'replies', 'page' => 1])) ?>">Replies</a>
        <?php endif; ?>
    </div>

    <form class="control-row" method="get">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <?php if ($view === 'topics' && $sort !== 'views'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
        <?php if ($view === 'posts'): ?>
            <input type="text" name="q" value="<?= e($q) ?>" placeholder='Search posts... (words, or "an exact phrase")' maxlength="120">
        <?php endif; ?>
        <select name="f" onchange="this.form.submit()">
            <option value="0">All forums</option>
            <?php foreach ($forums as $f): ?>
                <option value="<?= (int)$f['id'] ?>"<?= (int)$f['id'] === $forumId ? ' selected' : '' ?>><?= e($f['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit"><?= $view === 'posts' ? 'Search' : 'Filter' ?></button>
    </form>

    <?php if ($view === 'topics'): ?>
        <?php if (!$rows): ?>
            <p>No topics yet - the forum crawler hasn't produced results.</p>
        <?php else: ?>
        <table>
            <thead>
                <tr><th class="rank">#</th><th>Topic</th><th>Forum</th><th class="count">Replies</th><th class="count">Views</th></tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $t): ?>
                <tr>
                    <td class="rank">#<?= (int)$t['rank'] ?></td>
                    <td>
                        <a href="https://scratch.mit.edu/discuss/topic/<?= (int)$t['id'] ?>/" target="_blank" rel="noopener" title="<?= e($t['title']) ?>"><?= e(mb_strlen($t['title']) > 70 ? mb_substr($t['title'], 0, 69) . '…' : $t['title']) ?></a>
                        <?php if ($t['sticky']): ?><span class="tag">Sticky</span><?php endif; ?>
                        <?php if ($t['closed']): ?><span class="tag">Closed</span><?php endif; ?>
                        <?php if ($t['author'] !== ''): ?><br><span class="muted">by <a href="https://scratch.mit.edu/users/<?= e($t['author']) ?>/" target="_blank" rel="noopener"><?= e($t['author']) ?></a></span><?php endif; ?>
                    </td>
                    <td class="muted"><?= e((string)$t['forum_name']) ?></td>
                    <td class="count"><?= number_format((int)$t['replies']) ?></td>
                    <td class="count"><?= number_format((int)$t['views']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($q === ''): ?>
            <p class="muted">Search the text of forum posts, like Ctrl+F. Every result links to the post on Scratch. Only topics with <?= (int)FORUM_POST_MIN_REPLIES ?> or more replies are searchable, and only their first <?= (int)FORUM_POST_MAX_PAGES * FORUM_POSTS_PER_PAGE ?> posts. Quoted text is not searched.</p>
        <?php else: ?>
            <p class="search-meta"><?= number_format($total) ?> post<?= $total === 1 ? '' : 's' ?> found in <?= number_format((float)$searchTime, 2) ?> seconds &middot; <a href="<?= e(forumsUrl(['q' => '', 'page' => 1])) ?>">clear search</a></p>
            <?php if (!$rows): ?>
                <p>No matches.</p>
            <?php endif; ?>
            <?php foreach ($rows as $p): ?>
            <div class="post">
                <div class="meta">
                    <a href="https://scratch.mit.edu/discuss/post/<?= (int)$p['id'] ?>/" target="_blank" rel="noopener">Post #<?= (int)$p['pos'] ?> in <?= e((string)($p['topic_title'] ?? 'Topic ' . $p['topic_id'])) ?></a>
                    &middot; by <?= e($p['author']) ?> &middot; <?= e((string)$p['forum_name']) ?>
                </div>
                <div class="body"><?= forumPreview($p['body_text'], $terms) ?></div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($totalPages > 1): ?>
    <form class="page-jump" method="get">
        <input type="hidden" name="view" value="<?= e($view) ?>">
        <?php if ($view === 'topics' && $sort !== 'views'): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
        <?php if ($view === 'posts' && $q !== ''): ?><input type="hidden" name="q" value="<?= e($q) ?>"><?php endif; ?>
        <?php if ($forumId): ?><input type="hidden" name="f" value="<?= (int)$forumId ?>"><?php endif; ?>
        <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(forumsUrl(['page' => $page - 1])) ?>">&larr; Prev</a>
        <span>Page <input type="number" name="page" min="1" max="<?= $totalPages ?>" value="<?= $page ?>" style="width:3.5rem;text-align:center;padding:0.3rem;border-radius:6px;border:1px solid #444;background:#1e2023;color:#eee" onchange="this.value = Math.max(1, Math.min(<?= $totalPages ?>, this.value || 1)); this.form.submit()"> out of <?= number_format($totalPages) ?></span>
        <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e(forumsUrl(['page' => $page + 1])) ?>">Next &rarr;</a>
    </form>
    <?php endif; ?>

    <?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
