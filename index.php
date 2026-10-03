<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/studios-functions.php';
require_once __DIR__ . '/forums-functions.php';
require_once __DIR__ . '/page-cache.php';

// ---- request: which category (c), which mode (m), plus filters ----
$cat = $_GET['c'] ?? 'users';
if (!in_array($cat, ['users', 'studios', 'forums'], true)) $cat = 'users';
$mode = ($_GET['m'] ?? '') === 'dynamic' ? 'dynamic' : 'static';
if ($cat === 'forums') $mode = 'static'; // forums have no follower changes
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$dir = ($_GET['dir'] ?? '') === 'down' ? 'down' : 'up';
$fmin = preg_match('/^\d{1,10}$/', (string)($_GET['fmin'] ?? '')) ? (int)$_GET['fmin'] : null;
$fmax = preg_match('/^\d{1,10}$/', (string)($_GET['fmax'] ?? '')) ? (int)$_GET['fmax'] : null;
$access = in_array($_GET['access'] ?? '', ['open', 'closed'], true) ? $_GET['access'] : '';
$view = ($_GET['view'] ?? '') === 'posts' ? 'posts' : 'topics';
$sort = ($_GET['sort'] ?? '') === 'replies' ? 'replies' : 'views';
$forumId = max(0, (int)($_GET['f'] ?? 0));
if ($cat === 'forums' && $q !== '') $view = 'posts'; // typing a search means searching posts

// The Filter window writes the same search syntax people already type (f>=100, open, closed).
$qEff = $q;
if ($mode === 'static' && $cat !== 'forums') {
    if ($fmin !== null) $qEff .= ' f>=' . $fmin;
    if ($fmax !== null) $qEff .= ' f<=' . $fmax;
    if ($cat === 'studios' && $access !== '') $qEff .= ' ' . $access;
    $qEff = trim($qEff);
}

$activeFilters = 0;
if ($cat === 'forums') {
    $activeFilters = ($view === 'posts' ? 1 : 0) + ($view === 'topics' && $sort === 'replies' ? 1 : 0) + ($forumId > 0 ? 1 : 0);
} elseif ($mode === 'dynamic') {
    $activeFilters = $dir === 'down' ? 1 : 0;
} else {
    $activeFilters = ($fmin !== null ? 1 : 0) + ($fmax !== null ? 1 : 0) + ($cat === 'studios' && $access !== '' ? 1 : 0);
}

// Parameters that matter for the current view, with defaults left out.
function censusParams(array $over = []): array {
    global $cat, $mode, $q, $page, $dir, $fmin, $fmax, $access, $view, $sort, $forumId;
    $p = array_merge([
        'c' => $cat, 'm' => $mode, 'q' => $q, 'page' => $page, 'dir' => $dir, 'fmin' => $fmin, 'fmax' => $fmax,
        'access' => $access, 'view' => $view, 'sort' => $sort, 'f' => $forumId,
    ], $over);
    if ($p['c'] === 'forums') {
        unset($p['m'], $p['dir'], $p['fmin'], $p['fmax'], $p['access']);
        if ($p['view'] === 'posts') unset($p['sort']); else unset($p['q']);
    } else {
        unset($p['view'], $p['sort'], $p['f']);
        if ($p['c'] !== 'studios') unset($p['access']);
        if ($p['m'] === 'dynamic') unset($p['fmin'], $p['fmax'], $p['access'], $p['page']); else unset($p['dir']);
    }
    $defaults = ['c' => 'users', 'm' => 'static', 'page' => 1, 'dir' => 'up', 'view' => 'topics', 'sort' => 'views', 'f' => 0];
    foreach ($p as $k => $v) {
        if ($v === null || $v === '' || (array_key_exists($k, $defaults) && $v == $defaults[$k])) unset($p[$k]);
    }
    return $p;
}
function censusUrl(array $over = []): string {
    $p = censusParams($over);
    return '/s/census/' . ($p ? '?' . http_build_query($p) : '');
}

// Plain browsing looks the same for everyone, so it is served from the page cache.
$cacheKey = null;
if ($cat === 'forums') {
    if (($view === 'topics' || $q === '') && $page <= 100) $cacheKey = 'v2-forums-' . $view . '-' . $sort . '-' . $forumId . '-' . $page;
} elseif ($mode === 'dynamic') {
    if ($q === '') $cacheKey = 'v2-dyn-' . $cat . '-' . $dir;
} elseif ($qEff === '' && $page <= 100) {
    $cacheKey = 'v2-' . $cat . '-' . $page;
}
if ($cacheKey !== null) pageCacheStart($cacheKey);

$perPage = ($cat === 'forums' && $view === 'posts') ? 20 : 100;
$rows = [];
$found = 0;
$tracked = 0;
$totalPages = 1;
$searchTime = null;
$isExact = false;
$exactMissing = null;
$isFollowers = false;
$terms = [];

if ($cat === 'users' && $mode === 'static') {
    $tracked = getScratcherCount();
    $parsed = $qEff !== '' ? parseSearchQuery($qEff) : null;
    if ($parsed !== null && $parsed['exact'] !== null) {
        $isExact = true;
        $isFollowers = (bool)$parsed['conds'];
        $t0 = microtime(true);
        $exactRow = getExactScratcher($parsed['exact']);
        $searchTime = microtime(true) - $t0;
        if ($exactRow && rowMatchesSearch($exactRow, $parsed['conds'], $parsed['text'])) {
            $rows = [$exactRow];
        } elseif (!$exactRow) {
            $exactMissing = $parsed['exact'];
        }
        $found = count($rows);
        $page = 1;
    } elseif ($parsed !== null) {
        $isFollowers = (bool)$parsed['conds'];
        $t0 = microtime(true);
        $result = searchScratchersAdvanced($parsed['conds'], $parsed['text'], $page, $perPage);
        $searchTime = microtime(true) - $t0;
        $rows = $result['rows'];
        $found = $result['total'];
        $totalPages = max(1, (int)ceil($found / $perPage));
        $page = min($page, $totalPages);
    } else {
        $found = $tracked;
        $totalPages = max(1, (int)ceil($tracked / $perPage));
        $page = min($page, $totalPages);
        $rows = getScratchersPage($page, $perPage);
        // Browse mode is already in strict global order with no gaps, so rank is just position.
        foreach ($rows as $i => &$row) $row['rank'] = (($page - 1) * $perPage) + $i + 1;
        unset($row);
    }
} elseif ($cat === 'studios' && $mode === 'static') {
    $parsed = $qEff !== '' ? parseStudioSearch($qEff) : null;
    $t0 = microtime(true);
    $tracked = getStudioCount();
    if ($parsed !== null) {
        $totalForPages = getStudiosPage($parsed, 1, 1)['total'];
        $totalPages = max(1, (int)ceil($totalForPages / $perPage));
        $page = min($page, $totalPages);
        $res = getStudiosPage($parsed, $page, $perPage);
        $searchTime = microtime(true) - $t0;
    } else {
        $totalPages = max(1, (int)ceil($tracked / $perPage));
        $page = min($page, $totalPages);
        $res = getStudiosPage(null, $page, $perPage);
    }
    $rows = $res['rows'];
    $found = $res['total'];
} elseif ($mode === 'dynamic') {
    // Biggest follower changes since each item's latest daily check (kept for 2 days).
    $db = getDB();
    $cmp = $dir === 'up' ? '> 0' : '< 0';
    $order = $dir === 'up' ? 'DESC' : 'ASC';
    $like = $q !== '' ? '%' . likeEscape($q) . '%' : null;
    if ($cat === 'users') {
        $sql = "SELECT username, follower_count, follower_delta AS delta FROM scratchers
                WHERE status = 'fetched' AND follower_delta $cmp AND checked_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)"
             . ($like !== null ? " AND username LIKE ?" : "")
             . " ORDER BY follower_delta $order, username ASC LIMIT 100";
    } else {
        $sql = "SELECT id, title, follower_count, follower_delta AS delta FROM studios
                WHERE status = 'fetched' AND follower_delta $cmp AND checked_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)"
             . ($like !== null ? " AND title LIKE ?" : "")
             . " ORDER BY follower_delta $order, id ASC LIMIT 100";
    }
    $stmt = $db->prepare($sql);
    if ($like !== null) $stmt->bind_param('s', $like);
    $t0 = microtime(true);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if ($like !== null) $searchTime = microtime(true) - $t0;
    $found = count($rows);
    $page = 1;
} else { // forums
    $forumStats = getForumStats();
    $forums = getForumChoices();
    if ($view === 'topics') {
        $res = getForumTopicsPage($sort, $forumId, $page, $perPage);
        $totalPages = max(1, (int)ceil($res['total'] / $perPage));
        if ($page > $totalPages) { $page = $totalPages; $res = getForumTopicsPage($sort, $forumId, $page, $perPage); }
        $rows = $res['rows'];
        $found = $res['total'];
    } elseif ($q !== '') {
        $t0 = microtime(true);
        $res = searchForumPosts($q, $forumId, $page, $perPage);
        $searchTime = microtime(true) - $t0;
        $totalPages = max(1, (int)ceil($res['total'] / $perPage));
        $rows = $res['rows'];
        $found = $res['total'];
        $terms = $res['terms'];
    }
}

// ---- page title and wording ----
$titles = ['users' => 'Scratchers', 'studios' => 'Studios', 'forums' => 'Forums'];
$pageTitle = $cat === 'users' && $mode === 'static' ? 'ScratchCensus - a ScratchNews Site' : $titles[$cat] . ($mode === 'dynamic' ? ' (dynamic)' : '') . ' - ScratchCensus';
$pageDesc = [
    'users' => 'A comprehensive list of every Scratcher, by followers.',
    'studios' => 'Scratch studios ranked by followers, with whether they are open to all.',
    'forums' => 'Scratch forum topics ranked by views and replies, plus a search over forum posts.',
][$cat];
$navActive = 'home';
$searchPlaceholder = $cat === 'forums' ? 'Search posts... (words, or "an exact phrase")'
    : ($cat === 'studios' ? 'Search studio title... (open, closed, id:56, f>=100)' : 'Search username... (exact:name, f>=100)');
if ($mode === 'dynamic') $searchPlaceholder = $cat === 'studios' ? 'Search studio title...' : 'Search username...';

require __DIR__ . '/includes/layout-top.php';
?>
<form id="toolbar" class="toolbar" method="get" action="/s/census/">
    <div class="row">
        <select name="c" aria-label="Category" onchange="censusNav()">
            <option value="users"<?= $cat === 'users' ? ' selected' : '' ?>>Users</option>
            <option value="studios"<?= $cat === 'studios' ? ' selected' : '' ?>>Studios</option>
            <option value="forums"<?= $cat === 'forums' ? ' selected' : '' ?>>Forums</option>
        </select>
        <select name="m" aria-label="Mode" onchange="censusNav()">
            <option value="static"<?= $mode === 'static' ? ' selected' : '' ?>>Static</option>
            <option value="dynamic"<?= $mode === 'dynamic' ? ' selected' : '' ?><?= $cat === 'forums' ? ' disabled' : '' ?>>Dynamic</option>
        </select>
        <div class="filter-wrap">
            <button type="button" id="filter-btn" class="ctl" aria-expanded="false" aria-controls="filter-panel">Filter<?php if ($activeFilters): ?><span class="badge"><?= $activeFilters ?></span><?php endif; ?> &#9662;</button>
            <div id="filter-panel" class="panel" role="dialog" aria-label="Filters">
                <?php if ($cat === 'forums'): ?>
                    <h3>Forum filters</h3>
                    <div class="field">
                        <span class="lbl">Show</span>
                        <div class="pills">
                            <label><input type="radio" name="view" value="topics"<?= $view === 'topics' ? ' checked' : '' ?>><span>Topics</span></label>
                            <label><input type="radio" name="view" value="posts"<?= $view === 'posts' ? ' checked' : '' ?>><span>Posts</span></label>
                        </div>
                    </div>
                    <div class="field">
                        <span class="lbl">Sort topics by</span>
                        <div class="pills">
                            <label><input type="radio" name="sort" value="views"<?= $sort === 'views' ? ' checked' : '' ?>><span>Views</span></label>
                            <label><input type="radio" name="sort" value="replies"<?= $sort === 'replies' ? ' checked' : '' ?>><span>Replies</span></label>
                        </div>
                    </div>
                    <div class="field">
                        <label for="f-forum">Forum</label>
                        <select id="f-forum" name="f">
                            <option value="0">All forums</option>
                            <?php foreach ($forums as $f): ?>
                                <option value="<?= (int)$f['id'] ?>"<?= (int)$f['id'] === $forumId ? ' selected' : '' ?>><?= e($f['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php elseif ($mode === 'dynamic'): ?>
                    <h3>Follower changes</h3>
                    <div class="field">
                        <span class="lbl">Show</span>
                        <div class="pills">
                            <label><input type="radio" name="dir" value="up"<?= $dir === 'up' ? ' checked' : '' ?>><span>Gaining</span></label>
                            <label><input type="radio" name="dir" value="down"<?= $dir === 'down' ? ' checked' : '' ?>><span>Losing</span></label>
                        </div>
                    </div>
                <?php else: ?>
                    <h3><?= $cat === 'studios' ? 'Studio' : 'User' ?> filters</h3>
                    <div class="field">
                        <span class="lbl">Followers</span>
                        <div class="pair">
                            <input type="number" name="fmin" min="0" placeholder="min" value="<?= $fmin !== null ? (int)$fmin : '' ?>" aria-label="Minimum followers">
                            <span class="muted">to</span>
                            <input type="number" name="fmax" min="0" placeholder="max" value="<?= $fmax !== null ? (int)$fmax : '' ?>" aria-label="Maximum followers">
                        </div>
                    </div>
                    <?php if ($cat === 'studios'): ?>
                    <div class="field">
                        <span class="lbl">Access</span>
                        <div class="pills">
                            <label><input type="radio" name="access" value=""<?= $access === '' ? ' checked' : '' ?>><span>Any</span></label>
                            <label><input type="radio" name="access" value="open"<?= $access === 'open' ? ' checked' : '' ?>><span>Open</span></label>
                            <label><input type="radio" name="access" value="closed"<?= $access === 'closed' ? ' checked' : '' ?>><span>Closed</span></label>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="actions">
                    <button type="submit" class="primary">Apply</button>
                    <a class="ghost" href="<?= e(censusUrl(['q' => '', 'page' => 1, 'dir' => 'up', 'fmin' => null, 'fmax' => null, 'access' => '', 'view' => 'topics', 'sort' => 'views', 'f' => 0])) ?>">Reset</a>
                </div>
            </div>
        </div>
    </div>
    <div class="row">
        <input type="text" class="search" name="q" value="<?= e($q) ?>" placeholder="<?= e($searchPlaceholder) ?>" maxlength="120" aria-label="Search">
        <button type="submit" class="primary">Search</button>
    </div>
</form>

<?php if ($cat === 'forums'): ?>
    <p class="summary"><?= number_format($forumStats['topics']) ?> topics and <?= number_format($forumStats['posts']) ?> searchable posts tracked so far.</p>
<?php elseif ($mode === 'dynamic'): ?>
    <p class="summary">Change since each one's latest daily check, top <?= number_format($cat === 'users' ? REFRESH_TOP_N : STUDIO_REFRESH_TOP_N) ?> only. Shown for 2 days after the check.</p>
<?php else: ?>
    <p class="summary"><?= $cat === 'users' ? 'Every Scratcher, by followers.' : 'Scratch studios, by followers.' ?> <?= number_format($tracked) ?> tracked so far.</p>
<?php endif; ?>

<?php if ($q !== '' && $cat !== 'forums' && $mode === 'static'): ?>
    <p class="search-meta">
        <?php if ($isExact && $exactMissing !== null): ?>
            No fetched entry for <?= e($exactMissing) ?> yet (<?= number_format($searchTime * 1000) ?>ms) - <a href="#" onclick="document.getElementById('quick-crawl-form').submit(); return false;">crawl it now</a>?
        <?php else: ?>
            <?= number_format($found) ?> result<?= $found === 1 ? '' : 's' ?> found in <?= number_format((float)$searchTime, 2) ?> seconds
            <?php if ($cat === 'users' && !$isExact && !$isFollowers): ?> - <a href="<?= e(censusUrl(['q' => 'exact:' . $q, 'page' => 1])) ?>">search exact:<?= e($q) ?> instead</a><?php endif; ?>
        <?php endif; ?>
        &middot; <a href="<?= e(censusUrl(['q' => '', 'page' => 1])) ?>">clear search</a>
    </p>
    <?php if ($isExact && $exactMissing !== null): ?>
    <form id="quick-crawl-form" method="post" action="/s/census/crawl-user.php" style="display: none;">
        <input type="hidden" name="username" value="<?= e($exactMissing) ?>">
    </form>
    <?php endif; ?>
<?php elseif ($q !== '' && $mode === 'dynamic'): ?>
    <p class="search-meta"><?= number_format($found) ?> match<?= $found === 1 ? '' : 'es' ?> &middot; <a href="<?= e(censusUrl(['q' => ''])) ?>">clear search</a></p>
<?php elseif ($cat === 'forums' && $view === 'posts' && $q !== ''): ?>
    <p class="search-meta"><?= number_format($found) ?> post<?= $found === 1 ? '' : 's' ?> found in <?= number_format((float)$searchTime, 2) ?> seconds &middot; <a href="<?= e(censusUrl(['q' => '', 'page' => 1])) ?>">clear search</a></p>
<?php endif; ?>

<?php if ($cat === 'forums' && $view === 'posts'): ?>
    <?php if ($q === ''): ?>
        <p class="muted" style="margin-top:1rem">Search the text of forum posts, like Ctrl+F. Every result links to the post on Scratch. Only topics with <?= (int)FORUM_POST_MIN_REPLIES ?> or more replies are searchable, and only their first <?= (int)FORUM_POST_MAX_PAGES * FORUM_POSTS_PER_PAGE ?> posts. Quoted text is not searched.</p>
    <?php elseif (!$rows): ?>
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
<?php elseif (!$rows): ?>
    <?php if ($mode === 'dynamic'): ?>
        <p><?= $q !== '' ? 'No matches.' : 'Nothing here yet. Changes appear once the daily re-checks have run.' ?></p>
    <?php elseif ($cat === 'forums'): ?>
        <p>No topics yet - the forum crawler hasn't produced results.</p>
    <?php elseif ($cat === 'studios'): ?>
        <p><?= $qEff !== '' ? 'No matches.' : "No studios yet - the studio crawler hasn't produced results." ?></p>
    <?php else: ?>
        <p><?= $qEff !== '' ? 'No matches.' : "No data yet - the crawl hasn't produced results for this page." ?></p>
    <?php endif; ?>
<?php else: ?>
<div class="tablewrap">
<table>
    <?php if ($mode === 'dynamic'): ?>
    <thead>
        <tr><th class="rank">#</th><th><?= $cat === 'users' ? 'Username' : 'Studio' ?></th><th class="count">Change</th><th class="count">%</th><th class="count">Followers</th></tr>
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
            <td><?php if ($cat === 'users'): ?>
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
    <?php elseif ($cat === 'users'): ?>
    <thead>
        <tr><th class="rank">#</th><th>Username</th><th class="count">Followers</th></tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $s): ?>
        <tr>
            <td class="rank">#<?= (int)$s['rank'] ?></td>
            <td><a href="https://scratch.mit.edu/users/<?= e($s['username']) ?>/" target="_blank" rel="noopener"><?= e($s['username']) ?></a></td>
            <td class="count"><?php $d = $s['delta'] ?? null; if ($d !== null): $d = (int)$d; ?><span class="delta <?= $d > 0 ? 'up' : ($d < 0 ? 'down' : 'zero') ?>"><?= $d < 0 ? '-' : '+' ?><?= number_format(abs($d)) ?></span> <?php endif; ?><?= number_format((int)$s['follower_count']) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <?php elseif ($cat === 'studios'): ?>
    <thead>
        <tr><th class="rank">#</th><th>Studio</th><th>Host</th><th>Access</th><th class="count">Projects</th><th class="count">Followers</th></tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $s): ?>
        <tr>
            <td class="rank">#<?= (int)$s['rank'] ?></td>
            <td><?php $full = $s['title'] !== null && $s['title'] !== '' ? $s['title'] : 'Studio ' . $s['id']; ?><a href="https://scratch.mit.edu/studios/<?= (int)$s['id'] ?>/" target="_blank" rel="noopener" title="<?= e($full) ?>"><?= e(shortTitle($full)) ?></a> <span class="muted">#<?= (int)$s['id'] ?></span></td>
            <td><?php if (!empty($s['host_username'])): ?><a href="https://scratch.mit.edu/users/<?= e($s['host_username']) ?>/" target="_blank" rel="noopener"><?= e($s['host_username']) ?></a><?php else: ?><span class="muted">-</span><?php endif; ?></td>
            <td><span class="tag <?= $s['open_to_all'] ? 'open' : 'closed' ?>"><?= $s['open_to_all'] ? 'Open' : 'Closed' ?></span></td>
            <td class="count"><?= (int)$s['project_count'] >= 100 ? '100+' : number_format((int)$s['project_count']) ?></td>
            <td class="count"><?php $d = $s['delta'] ?? null; if ($d !== null): $d = (int)$d; ?><span class="delta <?= $d > 0 ? 'up' : ($d < 0 ? 'down' : 'zero') ?>"><?= $d < 0 ? '-' : '+' ?><?= number_format(abs($d)) ?></span> <?php endif; ?><?= number_format((int)$s['follower_count']) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <?php else: ?>
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
    <?php endif; ?>
</table>
</div>
<?php endif; ?>

<?php if ($totalPages > 1): ?>
<form class="page-jump" method="get" action="/s/census/">
    <?php foreach (censusParams(['page' => 1]) as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"><?php endforeach; ?>
    <a class="<?= $page <= 1 ? 'disabled' : '' ?>" href="<?= e(censusUrl(['page' => $page - 1])) ?>">&larr; Prev</a>
    <span>Page <input type="number" name="page" min="1" max="<?= $totalPages ?>" value="<?= $page ?>" onchange="this.value = Math.max(1, Math.min(<?= $totalPages ?>, this.value || 1)); this.form.submit()"> out of <?= number_format($totalPages) ?></span>
    <a class="<?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= e(censusUrl(['page' => $page + 1])) ?>">Next &rarr;</a>
</form>
<?php endif; ?>

<script>
(function () {
    var form = document.getElementById('toolbar');
    var btn = document.getElementById('filter-btn');
    var panel = document.getElementById('filter-panel');
    function setOpen(open) {
        panel.classList.toggle('open', open);
        btn.classList.toggle('on', open);
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    btn.addEventListener('click', function (e) { e.stopPropagation(); setOpen(!panel.classList.contains('open')); });
    panel.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });

    // Keep the address clean: leave out empty and default fields when submitting.
    var defaults = { c: 'users', m: 'static', dir: 'up', access: '', view: 'topics', sort: 'views', f: '0' };
    form.addEventListener('submit', function () {
        Array.prototype.forEach.call(form.elements, function (el) {
            if (!el.name || el.type === 'submit' || el.type === 'button') return;
            if (el.type === 'radio' && !el.checked) { el.disabled = true; return; }
            if (el.value === '' || (el.name in defaults && el.value === defaults[el.name])) el.disabled = true;
        });
    });
    window.addEventListener('pageshow', function () {
        Array.prototype.forEach.call(form.elements, function (el) { el.disabled = false; });
    });

    // Changing category or mode starts a fresh view (filters differ per category), keeping the search text for mode changes.
    window.censusNav = function () {
        var c = form.elements['c'].value, m = form.elements['m'].value;
        var oldC = <?= json_encode($cat) ?>;
        if (c === 'forums') m = 'static';
        var p = [];
        if (c !== 'users') p.push('c=' + encodeURIComponent(c));
        if (m === 'dynamic') p.push('m=dynamic');
        var q = form.elements['q'].value.trim();
        if (q !== '' && c === oldC) p.push('q=' + encodeURIComponent(q));
        location.href = '/s/census/' + (p.length ? '?' + p.join('&') : '');
    };
})();
</script>
<?php require __DIR__ . '/includes/layout-bottom.php'; ?>