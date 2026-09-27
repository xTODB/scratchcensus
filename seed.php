<?php
require_once __DIR__ . '/functions.php';

// Bootstraps the BFS - the crawler discovers new usernames from each seed's
// followers, so it only needs a small, well-connected starting set. Safe to
// re-run any time (queueUsername is INSERT IGNORE); also accepts extra
// one-off usernames via ?usernames=a,b,c if you want to seed more later.
// Guarded the same way as cron/crawl.php - visit with ?key=YOUR_CRON_SECRET.
if (!hash_equals(CRON_SECRET, $_GET['key'] ?? '')) {
    http_response_code(404);
    exit;
}

$seeds = [
    'griffpatch', 'ScratchCat', 'griffpatch_tutor', 'Will_Wam', 'Scratchteam',
];

if (!empty($_GET['usernames'])) {
    foreach (explode(',', $_GET['usernames']) as $u) {
        $u = trim($u);
        if ($u !== '') $seeds[] = $u;
    }
}

header('Content-Type: text/plain');
foreach ($seeds as $u) {
    queueUsername($u, null);
    echo "Queued: {$u}\n";
}