<?php
require_once __DIR__ . '/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FAQ - ScratchCensus</title>
<?php require __DIR__ . '/includes/favicon.php'; ?>
<meta name="description" content="What ScratchCensus is and how to use it.">
<style>
body { font-family: -apple-system, Helvetica, Arial, sans-serif; max-width: 700px; margin: 2rem auto; padding: 0 1rem; background: #17191c; color: #eee; }
h1 { margin-bottom: 0.2rem; }
p.sub { color: #999; margin-top: 0; }
a { color: #ffaa33; }
</style>
</head>
<body>
    <h1>FAQ</h1>
    <p class="sub"><a href="/s/census/">&larr; Back to ScratchCensus</a></p>
    <h2>What is ScratchCensus?</h2>
    <p>ScratchCensus is a Scratch website where you can see the fullest picture of every single Scratcher, by followers. It is free and <a href="https://github.com/xTODB/scratchcensus/">open-source.</a></p>
    <h2>How does ScratchCensus work?</h2>
    <p>When you crawl for Scratchers, ScratchCensus requests usernames, follower and following lists from the entire database of Scratch users registered on ScratchCensus. Then the data is neatly put into this website for anyone to use.</p>
    <h2>How to search more efficiently?</h2>
    <p>If you want to search on ScratchCensus better, you can use our search operators! By default, your query is being searched as a part from every username ScratchCensus has. You can search for exact:username to give you only that exact username.</p>
    <p>You can also search by follower count: f=100 for exactly 100 followers, f&lt;100 for less than 100, f&lt;=100 for 100 or fewer, f&gt;100 for more than 100, and f&gt;=100 for 100 or more. Operators can be combined in one search, for example <b>f&gt;=12 f&lt;=15</b> (12 to 15 followers), or <b>f&lt;=300 a</b> (300 or fewer followers and "a" in the username).</p>
    <h2>Does ScratchCensus have studios?</h2>
    <p>Yes! <a href="/s/census/studios">Studios</a> are ranked by followers, with their host and whether anyone can add projects (open to all). Search by title, or use <b>open</b>, <b>closed</b>, <b>id:56</b> and follower operators like <b>f&gt;=100</b>. Combine them, for example <b>open f&gt;=100 art</b>.</p>
    <h2>Why does a studio show 100+ projects?</h2>
    <p>Scratch only reports up to 100 projects per studio, so 100+ means 100 or more. The studio page on Scratch shows the real number.</p>
    <h2>A Scratcher or studio is missing. Why?</h2>
    <p>ScratchCensus finds new users and studios by following the people and studios it already knows, so brand new or very quiet ones can take a while to appear.</p>
    <?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
