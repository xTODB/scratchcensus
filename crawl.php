<?php
require_once __DIR__ . '/functions.php';

$pageTitle = 'Crawl - ScratchCensus';
$pageDesc = 'Help ScratchCensus find more Scratchers, studios and forum posts.';
$navActive = 'crawl';

$msg = $_GET['msg'] ?? '';
$showFlash = in_array($msg, ['crawled', 'cooldown', 'user', 'studio', 'ran'], true);
$rateNote = !empty($_GET['rl']) ? ' Scratch asked us to slow down, so it stopped early.' : '';
require __DIR__ . '/includes/layout-top.php';
?>
<h1>Crawl</h1>
<p class="sub">Help ScratchCensus find more of Scratch. Each button works for about 15 seconds, and you can click again after a short wait.</p>

<?php if ($msg === 'crawled'): ?>
    <div class="flash">Crawled <?= (int)($_GET['n'] ?? 0) ?> more Scratcher(s) - refresh the leaderboard in a moment to see them.</div>
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
<?php elseif ($msg === 'studio'):
    $result = $_GET['result'] ?? '';
    $sid = (int)($_GET['id'] ?? 0); ?>
    <?php if ($result === 'added'): ?>
        <div class="flash">Added studio #<?= $sid ?><?= ($_GET['t'] ?? '') !== '' ? ' (' . e($_GET['t']) . ')' : '' ?> - <?= number_format((int)($_GET['c'] ?? 0)) ?> followers!</div>
    <?php elseif ($result === 'notfound'): ?>
        <div class="flash">Couldn't find studio #<?= $sid ?> (deleted, or a wrong id?).</div>
    <?php elseif ($result === 'busy'): ?>
        <div class="flash">Scratch didn't answer for studio #<?= $sid ?>. Try again in a moment.</div>
    <?php else: ?>
        <div class="flash">That doesn't look like a studio id or link.</div>
    <?php endif; ?>
<?php elseif ($msg === 'ran'):
    $what = $_GET['what'] ?? '';
    $n = (int)($_GET['n'] ?? 0);
    $p = (int)($_GET['p'] ?? 0); ?>
    <?php if (!empty($_GET['off'])): ?>
        <div class="flash">That crawler is switched off right now.</div>
    <?php elseif ($what === 'studios'): ?>
        <div class="flash"><?= $n > 0 || (int)($_GET['r'] ?? 0) > 0
            ? 'Fetched ' . number_format($n) . ' new studio(s)' . ((int)($_GET['r'] ?? 0) > 0 ? ' and re-checked ' . number_format((int)$_GET['r']) . ' big one(s)' : '') . '.'
            : 'No studios were waiting right now.' ?><?= e($rateNote) ?></div>
    <?php elseif ($what === 'topics'): ?>
        <div class="flash"><?= $n > 0
            ? 'Read ' . number_format($p) . ' topic list page(s) and saw ' . number_format($n) . ' topic(s).'
            : 'No topic lists were due right now.' ?><?= e($rateNote) ?></div>
    <?php elseif ($what === 'posts'): ?>
        <div class="flash"><?= $n > 0
            ? 'Stored ' . number_format($n) . ' new post(s) from ' . number_format($p) . ' topic page(s).'
            : 'No big topics were waiting for their posts right now.' ?><?= e($rateNote) ?></div>
    <?php endif; ?>
<?php endif; ?>
<?php if ($showFlash): ?>
    <script>
    (function() {
        if (window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            ['msg', 'n', 'wait', 'result', 'u', 'c', 'id', 't', 'what', 'r', 'p', 'rl', 'off'].forEach(function(k) { url.searchParams.delete(k); });
            window.history.replaceState({}, '', url.toString());
        }
    })();
    </script>
<?php endif; ?>

<div class="card">
    <h2>Scratchers</h2>
    <p>Fetch the next batch of Scratchers from the queue, or add one by username.</p>
    <form method="get" action="/s/census/crawl-now.php">
        <button type="submit" class="primary">Crawl Users</button>
    </form>
    <form method="post" action="/s/census/crawl-user.php" style="margin-top: 0.6rem;">
        <input type="text" name="username" placeholder="username..." maxlength="50" required>
        <button type="submit" class="primary">Crawl User</button>
    </form>
</div>

<div class="card">
    <h2>Studios</h2>
    <p>Fetch the next studios in the queue and re-check the biggest ones that are due, or add one by id or link.</p>
    <form method="post" action="/s/census/crawl-run.php">
        <input type="hidden" name="what" value="studios">
        <button type="submit" class="primary">Crawl Studios</button>
    </form>
    <form method="post" action="/s/census/crawl-run.php" style="margin-top: 0.6rem;">
        <input type="hidden" name="what" value="studio">
        <input type="text" name="studio" placeholder="studio id or link..." maxlength="120" required>
        <button type="submit" class="primary">Crawl Studio</button>
    </form>
</div>

<div class="card">
    <h2>Forums</h2>
    <p>Topics reads the next pages of forum topic lists, which keeps views and replies fresh. Posts stores the text of the next big topics (50 or more replies) so the Posts search can find them.</p>
    <form method="post" action="/s/census/crawl-run.php">
        <input type="hidden" name="what" value="topics">
        <button type="submit" class="primary">Crawl Forum Topics</button>
    </form>
    <form method="post" action="/s/census/crawl-run.php" style="margin-top: 0.6rem;">
        <input type="hidden" name="what" value="posts">
        <button type="submit" class="primary">Crawl Forum Posts</button>
    </form>
</div>
<?php require __DIR__ . '/includes/layout-bottom.php'; ?>