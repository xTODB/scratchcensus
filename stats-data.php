<?php
// The numbers on the key-gated stats page, kept in a small temp file so the page
// opens instantly instead of counting hundreds of thousands of rows every time.
//   getStatsData()        -> cached copy if it is younger than STATS_CACHE_SEC, else rebuilt
//   getStatsData(true)    -> always rebuilt (stats.php?key=...&fresh=1)
// cron/crawl-forums.php calls statsCacheWarm() after its work, so the page is almost always warm.
// Settings and the discovery on/off lines are NOT cached; stats.php works those out live.
require_once __DIR__ . '/studios-functions.php';
require_once __DIR__ . '/forums-functions.php';

defined('STATS_CACHE_SEC') || define('STATS_CACHE_SEC', 300);

function statsCachePath(): string {
    return sys_get_temp_dir() . '/scratchcensus_stats_' . md5(__DIR__);
}

function statsCacheAge(): ?int {
    $f = statsCachePath();
    return is_file($f) ? time() - (int)@filemtime($f) : null;
}

// One pass per table instead of one scan per number.
function buildStatsData(): array {
    $db = getDB();
    $t0 = microtime(true);
    $d = ['built_at' => time()];

    $minRefresh = max(1, refreshThreshold());
    $row = $db->query("SELECT
            SUM(status = 'pending') AS pending, SUM(status = 'fetched') AS fetched, SUM(status = 'error') AS error,
            SUM(status = 'error' AND retries >= " . (int)CRAWL_MAX_RETRIES . ") AS gaveup,
            SUM(status = 'fetched' AND follower_count >= $minRefresh AND checked_at < DATE_SUB(NOW(), INTERVAL " . (int)REFRESH_INTERVAL_HOURS . " HOUR)) AS refresh_due,
            SUM(status = 'fetched' AND discovered = 0 AND follower_count >= " . (int)DISCOVER_FOLLOWING_MIN . ") AS unmined,
            MAX(checked_at) AS last_crawled
        FROM scratchers")->fetch_assoc();
    $d['counts'] = ['pending' => (int)$row['pending'], 'fetched' => (int)$row['fetched'], 'error' => (int)$row['error']];
    $d['gave_up'] = (int)$row['gaveup'];
    $d['refresh_due'] = (int)$row['refresh_due'];
    $d['unmined'] = (int)$row['unmined'];
    $d['last_crawled'] = $row['last_crawled'];
    $d['top_user'] = $db->query("SELECT username, follower_count FROM scratchers WHERE status = 'fetched' ORDER BY follower_count DESC, username ASC LIMIT 1")->fetch_assoc() ?: null;

    $minStudio = max(1, studioRefreshThreshold());
    $row = $db->query("SELECT
            SUM(status = 'pending') AS pending, SUM(status = 'fetched') AS fetched, SUM(status = 'error') AS error,
            SUM(status = 'fetched' AND open_to_all = 1) AS open_c, SUM(status = 'fetched' AND open_to_all = 0) AS closed_c,
            SUM(status = 'fetched' AND follower_count >= $minStudio AND checked_at < DATE_SUB(NOW(), INTERVAL " . (int)STUDIO_REFRESH_INTERVAL_HOURS . " HOUR)) AS refresh_due,
            MAX(checked_at) AS last_crawled
        FROM studios")->fetch_assoc();
    $d['studio_counts'] = ['pending' => (int)$row['pending'], 'fetched' => (int)$row['fetched'], 'error' => (int)$row['error']];
    $d['studio_open'] = ['o' => (int)$row['open_c'], 'c' => (int)$row['closed_c']];
    $d['studio_refresh_due'] = (int)$row['refresh_due'];
    $d['studio_last'] = $row['last_crawled'];
    $d['top_studio'] = $db->query("SELECT id, title, follower_count FROM studios WHERE status = 'fetched' ORDER BY follower_count DESC, id ASC LIMIT 1")->fetch_assoc() ?: null;

    $row = $db->query("SELECT SUM(status = 'pending') AS pending, SUM(status = 'mined') AS mined, SUM(status = 'error') AS error FROM studio_people")->fetch_assoc();
    $d['people'] = ['pending' => (int)$row['pending'], 'mined' => (int)$row['mined'], 'error' => (int)$row['error']];

    $d['forums'] = getForumAdminStats();
    $d['build_sec'] = round(microtime(true) - $t0, 1);
    return $d;
}

function getStatsData(bool $fresh = false): array {
    $f = statsCachePath();
    if (!$fresh) {
        $age = statsCacheAge();
        if ($age !== null && $age < (int)STATS_CACHE_SEC) {
            $d = json_decode((string)@file_get_contents($f), true);
            if (is_array($d) && isset($d['counts'])) return $d;
        }
    }
    $d = buildStatsData();
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, json_encode($d)) !== false) @rename($tmp, $f); else @unlink($tmp);
    return $d;
}

// For crons: rebuild only when the copy is older than $maxAge seconds. Returns true if it rebuilt.
function statsCacheWarm(int $maxAge = 240): bool {
    $age = statsCacheAge();
    if ($age !== null && $age < $maxAge) return false;
    getStatsData(true);
    return true;
}
