<?php
require_once __DIR__ . '/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Changelog - ScratchCensus</title>
<?php require __DIR__ . '/includes/favicon.php'; ?>
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
    <p>[Oct3] v5.1 - Faster forums! The Topics tab loads quicker, even filtered to one forum, and the crawler does less counting between rounds.</p>
    <p>[Oct3] v5.0 - Forums! ScratchCensus now crawls the Scratch forums. The Topics tab ranks every topic by views or replies, and can be filtered to one forum. The Posts tab searches the text of posts in bigger topics, like Ctrl+F, and links each result to the post on Scratch.</p>
    <p>[Oct2] v4.2 - Growth! A new Growth page shows which Scratchers and studios gained or lost the most followers since their last daily check. Studios now get the same daily re-check and green/red follower changes as users.</p>
    <p>[Oct2] v4.1 - Follower changes! The top 10,000 Scratchers are now re-checked every day, and the leaderboard shows how many followers they gained (green) or lost (red) since the last check. Browsing and searching are much faster too, even on page 5000, and studio searches like open or f&gt;=100 are now nearly instant. The crawler also retries users it gave up on after a temporary error.</p>
    <p>[Oct1] v4.0 - Studios! ScratchCensus now crawls Scratch studios too, ranked by followers and showing whether they are open to all. Search them by title, or with open, closed, id:56 and f&gt;=100. There is a new logo and favicon, discovery now goes back for users it hadn't mined yet, and the crawler stops downloading a profile page as soon as it has the follower count.</p>
    <p>[Sep30] v3.0 - The biggest update yet! Search is now nearly instant (it used to take over 10 seconds), search operators can be combined (like f&gt;=12 f&lt;=15 or f&lt;=300 a), and the crawler is faster than ever.</p>
    <p>[Sep29] v2.1 - Way faster crawler! Parallel fetching, smarter queuing, and already bigger than ScratchViews's list.</p>
    <p>[Sep28] v2.0 - Search, a better way to crawl users, FAQ and a new UI!</p>
    <p>[Sep27] v1.0 - ScratchCensus launches! A comprehensive, community-crawled list of every Scratcher, by followers.</p>
    <?php require __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
