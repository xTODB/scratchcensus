<?php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/includes/api-support.php';
$pageTitle = 'API - ScratchCensus';
$pageDesc = 'A free, read-only JSON API for ScratchCensus.';
$navActive = 'api';
$base = '/s/census/api';
require __DIR__ . '/includes/layout-top.php';

$endpoints = [
    ['/users', 'The follower leaderboard, or search it.', 'page, limit (max ' . (int)API_MAX_LIMIT . '), q, fmin, fmax', $base . '/users?limit=5&pretty=1'],
    ['/users/{username}', 'One Scratcher with their rank.', '', $base . '/users/griffpatch?pretty=1'],
    ['/studios', 'The studio leaderboard, or search it.', 'page, limit, q, fmin, fmax, access=open|closed', $base . '/studios?limit=5&pretty=1'],
    ['/studios/{id}', 'One studio with its rank.', '', $base . '/studios/56?pretty=1'],
    ['/topics', 'Forum topics by views or replies.', 'page, limit, sort=views|replies, forum={id}', $base . '/topics?sort=replies&limit=5&pretty=1'],
    ['/forums', 'The forums ScratchCensus follows.', '', $base . '/forums?pretty=1'],
    ['/growth', 'Biggest gainers or losers in the last 2 days.', 'type=users|studios, dir=up|down, limit', $base . '/growth?type=users&dir=up&limit=5&pretty=1'],
    ['/stats', 'Totals: users, studios, forum topics.', '', $base . '/stats?pretty=1'],
];
?>
<div class="prose">
<h1>API</h1>
<p>ScratchCensus has a free, read-only JSON API. No key or sign-up needed. Everything is <code>GET</code>, and any website can call it from the browser (CORS is open).</p>

<div class="card">
    <h2>Base URL</h2>
    <p><code>https://scratchnews.net/s/census/api</code></p>
    <p class="setnote">Add <code>?pretty=1</code> to any call for readable JSON.</p>
</div>

<h2>Endpoints</h2>
<?php foreach ($endpoints as [$path, $what, $params, $try]): ?>
<div class="card">
    <h2><code><?= e($path) ?></code></h2>
    <p><?= e($what) ?></p>
    <?php if ($params !== ''): ?><p class="muted">Parameters: <?= e($params) ?></p><?php endif; ?>
    <p><a href="<?= e($try) ?>" target="_blank" rel="noopener">Try it</a></p>
</div>
<?php endforeach; ?>

<h2>Searching</h2>
<p>For <code>q</code> you can use the same search as the site: a name or title, <code>exact:name</code>, <code>f&gt;=1000</code> style follower filters, and for studios <code>open</code>, <code>closed</code> or <code>id:56</code>. <code>fmin</code> and <code>fmax</code> do the same as the follower filters.</p>

<h2>Example</h2>
<div class="card">
<pre style="margin:0; overflow-x:auto;"><code>GET <?= e($base) ?>/users/griffpatch

{"user":{"rank":1,"username":"griffpatch","followers":787134,
 "change":12,"checked_at":"2026-10-03 10:00:00",
 "profile_url":"https://scratch.mit.edu/users/griffpatch/",
 "picture":"https://uploads.scratch.mit.edu/get_image/user/1882674_90x90.png"}}</code></pre>
</div>
<p class="muted">Example values only. <code>picture</code> is null until a Scratcher's picture id is known, and <code>change</code> is null when there is no recent change.</p>

<h2>Limits</h2>
<ul>
    <li>Each IP gets <?= (int)API_RATE_LIMIT ?> units every <?= (int)API_RATE_WINDOW_SEC ?> seconds. A normal call costs 1 unit and a search costs 3.</li>
    <li>Past the limit you get HTTP 429 and a <code>Retry-After</code> header. <code>X-RateLimit-Limit</code>, <code>X-RateLimit-Remaining</code> and <code>X-RateLimit-Reset</code> are sent with every answer.</li>
    <li>Answers are cached for <?= (int)API_CACHE_TTL_SEC ?> seconds, so counts can be up to a minute behind. <code>ETag</code> is supported.</li>
    <li>Lists return at most <?= (int)API_MAX_LIMIT ?> rows per call. Use <code>page</code> for more.</li>
    <li>Please be polite with your request rate, and credit ScratchCensus if you show the data.</li>
</ul>
<p>Forum post text is not available through the API. ScratchCensus is not affiliated with the Scratch Team.</p>
</div>
<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
