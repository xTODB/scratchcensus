<?php
require_once __DIR__ . '/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Changelog - ScratchCensus</title>
<meta name="description" content="What's changed in ScratchCensus over time.">
<style>
body { font-family: -apple-system, Helvetica, Arial, sans-serif; max-width: 700px; margin: 2rem auto; padding: 0 1rem; background: #17191c; color: #eee; }
h1 { margin-bottom: 0.2rem; }
p.sub { color: #999; margin-top: 0; }
a { color: #ffaa33; }
ul { margin: 0.3rem 0 1rem; padding-left: 1.3rem; }
li { margin: 0.15rem 0; }
</style>
</head>
<body>
    <h1>Changelog</h1>
    <p class="sub"><a href="/s/census/">&larr; Back to ScratchCensus</a></p>
    <p>[Sep30] v3.0 - The biggest update yet! Search is now nearly instant (it used to take over 10 seconds), search operators can be combined (like f&gt;=12 f&lt;=15 or f&lt;=300 a), and the crawler is faster than ever.</p>
    <p>[Sep29] v2.1 - Way faster crawler! Parallel fetching, smarter queuing, and already bigger than ScratchViews's list.</p>
    <p>[Sep28] v2.0 - Search, a better way to crawl users, FAQ and a new UI!</p>
    <p>[Sep27] v1.0 - ScratchCensus launches! A comprehensive, community-crawled list of every Scratcher, by followers.</p>
    <?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
