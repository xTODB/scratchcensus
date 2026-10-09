<?php
require_once __DIR__ . '/functions.php';
$pageTitle = 'Changelog - ScratchCensus';
$pageDesc = "What's changed in ScratchCensus over time.";
$navActive = 'changelog';
require __DIR__ . '/includes/layout-top.php';
?>
<div class="prose">
<h1>Changelog</h1>
    <p>[Oct9] v6.3 - Faster studio search! Searching studio titles no longer scans every studio twice per page, word searches use a title index, and the follower tables behind the studio list are refreshed in the background so nobody waits for them. Title search now matches the start of words (add finds add, address, adding) instead of any piece of a word.</p>
    <p>[Oct4] v6.2 - Scratcher profile pages! Click any name to see their rank, country rank, a follower graph, daily growth bars and a 30 day projection. ScratchCensus now saves one follower count per day for the top Scratchers, so graphs fill in as days pass.</p>
    <p>[Oct4] v6.1 - Country search! Pick a country in the Filter window (Users, Static or Dynamic) or type country:Moldova in the search box, and the list shows only Scratchers from there, with country shown next to names. The API got a country parameter and a /countries list. Countries are filled in biggest Scratchers first, so smaller countries and smaller accounts take a while to appear.</p>
    <p>[Oct4] v6.0 - Faster and steadier! The forum crawler fetches more pages at once and pauses by itself when Scratch asks it to slow down, forums can be switched on and off without touching any code, and the busiest pages are now rebuilt in the background before they expire, so they load instantly for everyone.</p>
    <p>[Oct4] v5.7 - New API page in the menu with every endpoint, example links and the rate limits.</p>
    <p>[Oct4] v5.6 - Public API! Read-only JSON for Scratchers, studios, forum topics and growth at /s/census/api (rate limited, cached). Also: picture rows no longer push Followers off screen on phones, and ScratchCensus visits now show up in the ScratchNews visitor log.</p>
    <p>[Oct3] v5.5 - Pictures! Scratcher profile pictures and studio thumbnails now show next to names. Open the new Settings page to turn them off or change their size (saved in your browser only). Picture ids are being filled in biggest users first, so some Scratchers show a grey circle for now.</p>
    <p>[Oct3] v5.4 - Add a forum topic! The Crawl page now has a box where you can paste a forum topic id or link, and ScratchCensus adds it right away with its forum, title and replies.</p>
    <p>[Oct3] v5.3 - Crawl everything! The Crawl page now has buttons for Scratchers, studios, forum topics and forum posts, plus a way to add one studio by id or link. There is also a Home button in the sidebar.</p>
    <p>[Oct3] v5.2 - New look! ScratchCensus now has a sidebar and one simple page: pick Users, Studios or Forums, switch between Static and Dynamic (Dynamic is the old Growth page), and use the Filter button for category-specific filters. Crawling has its own page. Old Studios, Forums and Growth links still work.</p>
    <p>[Oct3] v5.1 - Faster forums! The Topics tab loads quicker, even filtered to one forum, and the crawler does less counting between rounds.</p>
    <p>[Oct3] v5.0 - Forums! ScratchCensus now crawls the Scratch forums. The Topics tab ranks every topic by views or replies, and can be filtered to one forum. The Posts tab searches the text of posts in bigger topics, like Ctrl+F, and links each result to the post on Scratch.</p>
    <p>[Oct2] v4.2 - Growth! A new Growth page shows which Scratchers and studios gained or lost the most followers since their last daily check. Studios now get the same daily re-check and green/red follower changes as users.</p>
    <p>[Oct2] v4.1 - Follower changes! The top 10,000 Scratchers are now re-checked every day, and the leaderboard shows how many followers they gained (green) or lost (red) since the last check. Browsing and searching are much faster too, even on page 5000, and studio searches like open or f&gt;=100 are now nearly instant. The crawler also retries users it gave up on after a temporary error.</p>
    <p>[Oct1] v4.0 - Studios! ScratchCensus now crawls Scratch studios too, ranked by followers and showing whether they are open to all. Search them by title, or with open, closed, id:56 and f&gt;=100. There is a new logo and favicon, discovery now goes back for users it hadn't mined yet, and the crawler stops downloading a profile page as soon as it has the follower count.</p>
    <p>[Sep30] v3.0 - The biggest update yet! Search is now nearly instant (it used to take over 10 seconds), search operators can be combined (like f&gt;=12 f&lt;=15 or f&lt;=300 a), and the crawler is faster than ever.</p>
    <p>[Sep29] v2.1 - Way faster crawler! Parallel fetching, smarter queuing, and already bigger than ScratchViews's list.</p>
    <p>[Sep28] v2.0 - Search, a better way to crawl users, FAQ and a new UI!</p>
    <p>[Sep27] v1.0 - ScratchCensus launches! A comprehensive, community-crawled list of every Scratcher, by followers.</p>
</div>
<?php require __DIR__ . '/includes/layout-bottom.php'; ?>