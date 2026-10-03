<?php
require_once __DIR__ . '/functions.php';

$pageTitle = 'Crawl - ScratchCensus';
$pageDesc = 'Help ScratchCensus find more Scratchers.';
$navActive = 'crawl';

$msg = $_GET['msg'] ?? '';
$showFlash = in_array($msg, ['crawled', 'cooldown', 'user'], true);
require __DIR__ . '/includes/layout-top.php';
?>
<h1>Crawl</h1>
<p class="sub">Help ScratchCensus find more of Scratch.</p>

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

<div class="card">
    <h2>Crawl more Scratchers</h2>
    <p>Fetches the next batch of Scratchers from the queue.</p>
    <a class="primary" href="/s/census/crawl-now.php">Crawl Users</a>
</div>
<div class="card">
    <h2>Crawl a specific user</h2>
    <p>Add or refresh one Scratcher by username.</p>
    <form method="post" action="/s/census/crawl-user.php">
        <input type="text" name="username" placeholder="username..." maxlength="50" required>
        <button type="submit" class="primary">Crawl User</button>
    </form>
</div>
<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
