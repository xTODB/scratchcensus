<?php
require_once __DIR__ . '/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>FAQ - ScratchCensus</title>
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
    <p>You can also search by follower count: f=100 for exactly 100 followers, f&lt;100 for less than 100, f&lt;=100 for 100 or fewer, f&gt;100 for more than 100, and f&gt;=100 for 100 or more.</p>
    <?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>