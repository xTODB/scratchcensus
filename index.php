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
    <a href="https://scratchnews.net/s/census">
        <svg version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="140.23253" height="35.56185" viewBox="0,0,140.23253,35.56185"><defs><linearGradient x1="192.12676" y1="166.45685" x2="192.12676" y2="181.31752" gradientUnits="userSpaceOnUse" id="color-1"><stop offset="0" stop-color="#1fffc9"/><stop offset="1" stop-color="#3bfffb"/></linearGradient><linearGradient x1="192.40049" y1="182.09485" x2="192.40049" y2="195.58245" gradientUnits="userSpaceOnUse" id="color-2"><stop offset="0" stop-color="#12caff"/><stop offset="1" stop-color="#0064ff"/></linearGradient></defs><g transform="translate(-177.56078,-164.60141)"><g stroke-miterlimit="10"><g><path d="M204.65912,179.40449c1.84204,7.98809 0.72622,11.24685 -5.99653,13.52295c-3.06642,1.03831 -5.77576,2.16796 -6.53085,3.99698c-0.75517,-1.82902 -3.46434,-2.95867 -6.53085,-3.99698c-6.72283,-2.27611 -7.83857,-5.53478 -5.99653,-13.52295c1.51232,-6.55835 1.49305,-8.78689 -0.06661,-13.05668c8.46496,1.86089 16.84847,1.52354 25.18806,0c-1.55966,4.26979 -1.57892,6.49833 -0.06669,13.05668z" fill="#3bfffb" stroke="none" stroke-width="0"/><path d="M180.72708,167.09541c0.06453,-0.0363 0.13141,-0.06353 0.20019,-0.08347c0.25817,-0.11421 0.54381,-0.17764 0.84427,-0.17764c0.31529,0 0.61426,0.06985 0.8823,0.19492c0.02157,-0.00407 0.04301,-0.00872 0.06432,-0.01397c0.44701,-0.11028 0.82343,-0.46952 1.28157,-0.51518c1.97356,-0.19669 4.10876,0.33773 6.08996,0.4084c2.66643,0.09511 5.58314,-0.10855 8.22344,0.23288c1.5537,0.20092 3.42912,-1.13583 4.9465,-0.26876c0.16617,0.09495 0.14953,0.36317 0.28485,0.4985c1.81636,1.81636 -0.23197,3.24689 -0.24307,5.16894c-0.00889,1.53869 -0.01847,2.1217 0.11141,3.29058c0.02435,0.21921 -0.00924,1.2982 0.08978,1.38133c0.21186,0.17787 0.92347,1.26345 1.03733,1.62135c0.20393,0.64102 -0.65268,1.62579 -0.9899,1.9798c-0.24418,0.25633 -1.54155,0.50553 -1.91679,0.50442c-2.3005,-0.00681 -4.50266,-0.26528 -6.73662,-0.27852c-4.29371,-0.02546 -8.60838,0.37264 -12.88127,-0.05046c-2.82728,-0.27996 -2.03957,-2.00512 -1.99849,-3.87672c0.02363,-1.07641 -0.12523,-2.69705 0.15585,-3.82135c0.19204,-0.76816 0.98026,-1.79416 0.53865,-2.56697c-0.61569,-0.36337 -1.02872,-1.03359 -1.02872,-1.80027c0,-0.6798 0.32472,-1.28375 0.82752,-1.66521c0.06431,-0.06226 0.13602,-0.11707 0.21695,-0.1626z" fill="url(#color-1)" stroke="none" stroke-width="0.5"/><path d="M188.19531,193.92204v-14.79347h8.22187l0.10493,14.5836c0,0 -2.74038,3.21442 -4.09878,3.24866c-1.4149,0.03566 -4.22802,-3.03879 -4.22802,-3.03879z" fill="#12caff" stroke="none" stroke-width="0"/><path d="M186.59661,181.42059v-2.30017h2.20229v2.30017z" fill="#12caff" stroke="none" stroke-width="0"/><path d="M187.80379,173.69627c0,-2.40555 1.95009,-4.35564 4.35564,-4.35564c2.40555,0 4.35564,1.95009 4.35564,4.35564c0,2.40555 -1.95009,4.35564 -4.35564,4.35564c-2.40555,0 -4.35564,-1.95009 -4.35564,-4.35564z" fill="#12caff" stroke="none" stroke-width="0"/><path d="M185.42205,191.20853v-11.2072h1.90865v11.2072z" fill="#12caff" stroke="none" stroke-width="0"/><path d="M185.37312,180.41732c0,-0.71626 0.58064,-1.2969 1.2969,-1.2969c0.71626,0 1.29691,0.58064 1.29691,1.2969c0,0.71626 -0.58064,1.29691 -1.29691,1.29691c-0.71626,0 -1.2969,-0.58064 -1.2969,-1.29691z" fill="#12caff" stroke="none" stroke-width="0"/><path d="M195.79608,181.41939v-2.30017h2.20229v2.30017z" fill="#12caff" stroke="none" stroke-width="0"/><path d="M200.79749,169.59573l-2.07903,11.01267l-1.87552,-0.35407l2.07903,-11.01267z" fill="#12caff" stroke="none" stroke-width="0"/><path d="M197.67429,181.41222c-0.71626,0 -1.2969,-0.58064 -1.2969,-1.2969c0,-0.71626 0.58064,-1.2969 1.2969,-1.2969c0.71626,0 1.2969,0.58064 1.2969,1.2969c0,0.71626 -0.58064,1.2969 -1.2969,1.2969z" fill="#12caff" stroke="none" stroke-width="0"/><path d="M204.65912,179.36777c1.84204,7.98809 0.72622,11.24685 -5.99653,13.52295c-3.06642,1.03831 -5.77576,2.16796 -6.53085,3.99698c-0.75517,-1.82902 -3.46434,-2.95867 -6.53085,-3.99698c-6.72283,-2.27611 -7.83857,-5.53478 -5.99653,-13.52295c1.51232,-6.55835 1.49305,-8.78689 -0.06661,-13.05668c8.46496,1.86089 16.84847,1.52354 25.18806,0c-1.55966,4.26979 -1.57892,6.49833 -0.06669,13.05668z" fill="none" stroke="#009cff" stroke-width="2.5"/><path d="M188.23708,193.28384v-11.18899h8.22187l0.10493,11.03025c0,0 -2.74038,2.43121 -4.09878,2.45711c-1.4149,0.02697 -4.22802,-2.29838 -4.22802,-2.29838z" fill="url(#color-2)" stroke="none" stroke-width="0"/><path d="M193.6758,184.67839c-0.76442,0.20661 -1.12133,-0.99548 -1.63232,-1.41044c-0.09424,-0.07653 -0.60433,-0.26425 -0.63317,-0.07265c-0.1287,0.8549 -0.06123,2.9993 1.10977,3.04663c0.53714,0.02171 1.75086,-0.87837 2.00704,-0.30473c0.18796,0.42087 -0.1056,0.66356 -0.24154,1.03252c-0.23926,0.64942 0.07858,1.49061 -0.15593,2.14723c-0.19728,0.55237 -1.22427,0.67298 -1.64618,0.50677c-0.12322,-0.04854 -0.3142,-0.19783 -0.42224,-0.29651c-0.10306,-0.09414 -0.2957,-0.42456 -0.30645,-0.28539c-0.01771,0.22917 0.19976,0.29233 0.21198,0.51234c0.02476,0.44571 -0.61936,0.6328 -0.92498,0.37084c-0.46032,-0.39456 -0.56701,-2.10752 -0.38998,-2.50392c0.41331,-0.92548 1.61855,0.72896 1.88513,0.96756c0.01862,0.01666 0.57076,0.51984 0.64873,0.22853c0.09909,-0.37022 0.09258,-1.08 0.07949,-1.45591c-0.00272,-0.07812 -0.14871,0.04968 -0.22547,0.06447c-0.18655,0.03593 -0.37953,0.02752 -0.56946,0.02312c-0.41877,-0.00969 -1.02999,-0.09367 -1.28343,-0.49193c-0.72089,-1.13282 -1.62229,-3.78868 -0.08121,-4.55922c0.9652,-0.4826 1.43345,0.57216 1.91082,0.57454c0.00056,0 0.00465,-0.01199 0.01115,-0.03145c0.00624,-0.10637 0.0455,-0.20388 0.10769,-0.2824c0.09221,-0.11644 0.23483,-0.19114 0.3949,-0.19114c0.27805,0 0.50346,0.22541 0.50346,0.50346c0,0.04945 -0.00713,0.09723 -0.02041,0.14237c0.29836,0.68815 0.27188,1.60064 -0.33738,1.76532z" fill="#002dff" stroke="none" stroke-width="0.5"/></g><g fill="#ffffff" font-family="Scratch" font-size="40" text-anchor="start"><text transform="translate(215.44336,186.16667) scale(0.5,0.5)" font-size="40" xml:space="preserve" fill="#ffffff" stroke="#047bff" stroke-width="3" font-weight="normal"><tspan x="0" dy="0">ScratchCensus</tspan></text><text transform="translate(215.44336,186.16667) scale(0.5,0.5)" font-size="40" xml:space="preserve" fill="#ffffff" stroke="none" stroke-width="1" font-weight="400"><tspan x="0" dy="0">ScratchCensus</tspan></text></g></g></g></svg>
    </a>
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
        <a class="btn" href="/s/census/studios" style="background: #2a2d31; color: #ffaa33; border: 1px solid #ffaa33;">Studios</a>
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