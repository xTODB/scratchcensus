<?php
require_once __DIR__ . '/functions.php';
$pageTitle = 'FAQ - ScratchCensus';
$pageDesc = 'What ScratchCensus is and how to use it.';
$navActive = 'faq';
require __DIR__ . '/includes/layout-top.php';
?>
<div class="prose">
<h1>FAQ</h1>
    <h2>What is ScratchCensus?</h2>
    <p>ScratchCensus is a Scratch website where you can see the fullest picture of every single Scratcher, by followers. It is free and <a href="https://github.com/xTODB/scratchcensus/">open-source.</a></p>
    <h2>How does ScratchCensus work?</h2>
    <p>When you crawl for Scratchers, ScratchCensus requests usernames, follower and following lists from the entire database of Scratch users registered on ScratchCensus. Then the data is neatly put into this website for anyone to use.</p>
    <h2>How to search more efficiently?</h2>
    <p>If you want to search on ScratchCensus better, you can use our search operators! By default, your query is being searched as a part from every username ScratchCensus has. You can search for exact:username to give you only that exact username.</p>
    <p>You can also search by follower count: f=100 for exactly 100 followers, f&lt;100 for less than 100, f&lt;=100 for 100 or fewer, f&gt;100 for more than 100, and f&gt;=100 for 100 or more. Operators can be combined in one search, for example <b>f&gt;=12 f&lt;=15</b> (12 to 15 followers), or <b>f&lt;=300 a</b> (300 or fewer followers and "a" in the username).</p>
    <h2>Does ScratchCensus have studios?</h2>
    <p>Yes! <a href="/s/census/?c=studios">Studios</a> are ranked by followers, with their host and whether anyone can add projects (open to all). Search by title, or use <b>open</b>, <b>closed</b>, <b>id:56</b> and follower operators like <b>f&gt;=100</b>. The Filter button sets follower ranges and open or closed access for you. Combine them, for example <b>open f&gt;=100 art</b>.</p>
    <h2>Does ScratchCensus have forums?</h2>
    <p>Yes! Pick <a href="/s/census/?c=forums">Forums</a> in the first dropdown. It ranks every crawled forum topic by views or replies, and the Filter button switches between Topics and Posts, changes the sort and narrows to one forum. Posts is a search over post text, like Ctrl+F, with a preview that links to the post on Scratch. Only topics with 50 or more replies are searchable, and only their first 500 posts.</p>
    <h2>Why does a studio show 100+ projects?</h2>
    <p>Scratch only reports up to 100 projects per studio, so 100+ means 100 or more. The studio page on Scratch shows the real number.</p>
    <h2>What do the green and red numbers next to follower counts mean?</h2>
    <p>The top 10,000 Scratchers are checked again every day. The number beside a count is the change since their previous check: green for gained followers, red for lost, gray +0 for no change. It stays visible for two days after the check. Scratchers outside the top 10,000 are only checked once, so they show no change.</p>
    <h2>What is Dynamic mode?</h2>
    <p>Switch the second dropdown from Static to Dynamic to see the 100 Scratchers or studios that gained (or lost) the most followers since their latest daily check, with the percentage change next to it. Use Filter to switch between gaining and losing. Only the top 10,000 Scratchers and top 10,000 studios are re-checked, so only they can appear there. It used to be called Growth.</p>
    <h2>Why are some deleted accounts still counted as errors?</h2>
    <p>If Scratch says an account no longer exists, ScratchCensus leaves it alone instead of retrying forever. Accounts that only failed because of a temporary Scratch error are tried again automatically.</p>
    <h2>A Scratcher or studio is missing. Why?</h2>
    <p>ScratchCensus finds new users and studios by following the people and studios it already knows, so brand new or very quiet ones can take a while to appear.</p>
</div>
<?php require __DIR__ . '/includes/layout-bottom.php'; ?>