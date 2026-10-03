<?php
// Shared page shell: top bar, left sidebar, and the opening of the content panel.
// Set these before including it:  $pageTitle, $pageDesc, $navActive ('crawl'|'changelog'|'faq'|'').
$pageTitle = $pageTitle ?? 'ScratchCensus - a ScratchNews Site';
$pageDesc = $pageDesc ?? 'Track everything Scratch: Scratchers, studios and forums.';
$navActive = $navActive ?? '';
$cssVersion = (int)@filemtime(__DIR__ . '/../assets/census.css');
$navItems = [
    'home' => ['/s/census/', 'Home', '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>'],
    'crawl' => ['/s/census/crawl', 'Crawl', '<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/>'],
];
$navItems2 = [
    'changelog' => ['/s/census/changelog', 'Changelog', '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>'],
    'faq' => ['/s/census/faq', 'FAQ', '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>'],
];
$renderNav = function (array $items) use ($navActive) {
    foreach ($items as $key => [$href, $label, $icon]) {
        echo '<a class="nav' . ($navActive === $key ? ' on' : '') . '" href="' . $href . '"><svg viewBox="0 0 24 24" aria-hidden="true">' . $icon . '</svg>' . $label . '</a>' . "\n";
    }
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
<?php require __DIR__ . '/favicon.php'; ?>
<meta name="description" content="<?= htmlspecialchars($pageDesc, ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="/s/census/assets/census.css?v=<?= $cssVersion ?>">
</head>
<body>
<div class="shell">
    <header class="topbar">
        <button class="menu-btn" type="button" aria-label="Menu" onclick="document.body.classList.toggle('nav-open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></button>
        <div class="brand"><?php require __DIR__ . '/logo.php'; ?></div>
        <p class="tagline">Track everything Scratch.</p>
    </header>
    <div class="scrim" onclick="document.body.classList.remove('nav-open')"></div>
    <nav class="side" aria-label="Main">
        <?php $renderNav($navItems); ?>
        <hr>
        <a class="news" href="https://scratchnews.net/" aria-label="ScratchNews"><?php require __DIR__ . '/news-logo.php'; ?></a>
        <?php $renderNav($navItems2); ?>
        <a class="nav" href="https://ko-fi.com/scratchnews" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>Donate</a>
    </nav>
    <main class="main">
