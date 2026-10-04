<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/profile-functions.php';

$name = trim((string)($_GET['u'] ?? ''));
$u = isValidScratchUsername($name) ? getProfileUser($name) : null;

$pageTitle = ($u ? $u['username'] . ' - ' : '') . 'Scratcher - ScratchCensus';
$pageDesc = $u ? 'Follower rank, growth and projection for ' . $u['username'] . ' on ScratchCensus.' : 'Scratcher not found on ScratchCensus.';
$navActive = 'home';
if (!$u) http_response_code(404);
require __DIR__ . '/includes/layout-top.php';
?>
<div class="prose prof">
<?php if (!$u): ?>
    <h1>Scratcher not found</h1>
    <p class="muted">ScratchCensus has no follower count for "<?= e($name) ?>" yet. You can add them on the <a href="/s/census/crawl">Crawl page</a>.</p>
    <p><a href="/s/census/">Back to the leaderboard</a></p>
<?php else:
    $followers = (int)$u['follower_count'];
    $hist = $u['id'] ? getUserHistory((int)$u['id']) : [];
    $proj = profileProjection($hist, $followers);
    $countryRank = !empty($u['country']) ? getCountryRank($u['country'], $followers, $u['username']) : null;
    $total = getScratcherCount();
    $delta = $u['delta'] === null ? null : (int)$u['delta'];
?>
    <div class="prof-head">
        <?= userPicHtml(isset($u['scratch_id']) ? (int)$u['scratch_id'] : null) ?>
        <div>
            <h1><?= e($u['username']) ?></h1>
            <p class="muted"><?php if (!empty($u['country'])): ?><?= e($u['country']) ?> &middot; <?php endif; ?><a href="https://scratch.mit.edu/users/<?= e($u['username']) ?>/" target="_blank" rel="noopener">View on Scratch</a></p>
        </div>
    </div>

    <div class="prof-stats">
        <div class="prof-stat"><span class="k">Followers</span><span class="v"><?= number_format($followers) ?><?php if ($delta !== null): ?> <span class="delta <?= $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'zero') ?>"><?= $delta < 0 ? '-' : '+' ?><?= number_format(abs($delta)) ?></span><?php endif; ?></span></div>
        <div class="prof-stat"><span class="k">Rank</span><span class="v">#<?= number_format((int)$u['rank']) ?></span><span class="muted">of <?= number_format($total) ?></span></div>
        <?php if ($countryRank !== null): ?>
        <div class="prof-stat"><span class="k">In <?= e($u['country']) ?></span><span class="v">#<?= number_format($countryRank) ?></span></div>
        <?php endif; ?>
        <?php if ($proj): ?>
        <div class="prof-stat"><span class="k">Per day</span><span class="v"><?= ($proj['per_day'] >= 0 ? '+' : '-') . number_format(abs($proj['per_day']), abs($proj['per_day']) < 10 ? 1 : 0) ?></span><span class="muted">over <?= (int)$proj['span'] ?> day<?= $proj['span'] === 1 ? '' : 's' ?></span></div>
        <?php endif; ?>
    </div>

    <?php if (count($hist) >= 2): ?>
    <div class="card prof-card">
        <h2>Followers over time</h2>
        <?= profileGraphSvg($hist) ?>
    </div>
    <div class="card prof-card">
        <h2>Daily change</h2>
        <?= profileBarsSvg($hist) ?>
    </div>
    <?php if ($proj): ?>
    <div class="card prof-card">
        <h2>Projection</h2>
        <p>If the last <?= (int)$proj['span'] ?> days continue, <?= e($u['username']) ?> would have about <strong><?= number_format($proj['in30']) ?></strong> followers in 30 days.<?php if ($proj['milestone'] !== null): ?> The next milestone, <?= number_format($proj['milestone']) ?>, would come in about <?= number_format($proj['days']) ?> day<?= $proj['days'] === 1 ? '' : 's' ?>.<?php endif; ?></p>
        <p class="muted">A straight line from the first to the latest check. Real growth is rarely that steady.</p>
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div class="card prof-card">
        <h2>Growth history</h2>
        <p>No history yet. ScratchCensus saves one follower count per day for the top <?= number_format(REFRESH_TOP_N) ?> Scratchers, so graphs appear after the second daily check.</p>
    </div>
    <?php endif; ?>

    <p class="muted">Last checked <?= e((string)$u['checked_at']) ?>. ScratchCensus is not affiliated with the Scratch Team.</p>
    <p><a href="/s/census/">Back to the leaderboard</a></p>
<?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
