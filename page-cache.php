<?php
// Whole-page cache for the heavy list pages (users, studios, growth, forums).
// A cached page is served straight from a file: no database, no template work,
// and a gzip copy is sent to browsers that accept it (about a quarter of the data).
//
//   pageCacheStart('users-1');   // call BEFORE any queries; serves the cache and exits when it can
//
// Behaviour:
//   - younger than PAGE_CACHE_TTL_SEC            -> served from the file, nothing else runs
//   - older, but younger than PAGE_CACHE_STALE_SEC -> the old copy is served instantly and ONE
//     visitor's request rebuilds it in the background (the rest keep getting the old copy)
//   - no copy / too old                          -> built normally, then saved
// Only call it for pages that look the same for everybody (no search text, no flash messages).
// Override the knobs in config.php. PAGE_CACHE_ENABLED = false turns it all off.
defined('PAGE_CACHE_ENABLED')   || define('PAGE_CACHE_ENABLED', true);
defined('PAGE_CACHE_TTL_SEC')   || define('PAGE_CACHE_TTL_SEC', 120);
defined('PAGE_CACHE_STALE_SEC') || define('PAGE_CACHE_STALE_SEC', 3600);

function pageCachePath(string $key): string {
    return sys_get_temp_dir() . '/scratchcensus_pc_' . md5(__DIR__ . '|' . $key);
}

function pageCacheServe(string $base, string $state): void {
    $gz = stripos($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') !== false && is_file($base . '.gz');
    $file = $gz ? $base . '.gz' : $base . '.html';
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=30');
    header('Vary: Accept-Encoding');
    header('X-ScratchCensus-Cache: ' . $state);
    if ($gz) header('Content-Encoding: gzip');
    header('Content-Length: ' . (int)@filesize($file));
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') readfile($file);
}

function pageCacheFinishResponse(): bool {
    while (ob_get_level() > 0) @ob_end_flush();
    flush();
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); return true; }
    if (function_exists('litespeed_finish_request')) { litespeed_finish_request(); return true; }
    return false;
}

function pageCacheStart(string $key, ?int $ttl = null): void {
    if (!PAGE_CACHE_ENABLED || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'HEAD') return;
    $ttl = $ttl ?? (int)PAGE_CACHE_TTL_SEC;
    $base = pageCachePath($key);
    $html = $base . '.html';
    $age = is_file($html) ? time() - (int)@filemtime($html) : null;

    if ($age !== null && $age < $ttl) {
        pageCacheServe($base, 'hit');
        exit;
    }
    $state = 'miss';
    if ($age !== null && $age < (int)PAGE_CACHE_STALE_SEC) {
        $lock = @fopen($base . '.lock', 'c');
        if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
            // We are the one who rebuilds. If we can hang up on the visitor first, give them the old copy
            // and rebuild afterwards; if not, they simply wait for the fresh page.
            if (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request')) {
                pageCacheServe($base, 'stale');
                pageCacheFinishResponse();
                $state = 'refresh';
            }
            $GLOBALS['__page_cache_lock'] = $lock; // released when the script ends
        } else {
            pageCacheServe($base, 'stale');
            exit;
        }
    }
    if ($state === 'miss') header('X-ScratchCensus-Cache: miss');
    ob_start(function (string $buf) use ($base, $html) {
        if (http_response_code() === 200 && strlen($buf) > 500) {
            $tmp = $base . '.' . bin2hex(random_bytes(4)) . '.tmp';
            if (@file_put_contents($tmp, $buf) !== false) @rename($tmp, $html); else @unlink($tmp);
            $tmpGz = $base . '.' . bin2hex(random_bytes(4)) . '.gz.tmp';
            if (@file_put_contents($tmpGz, gzencode($buf, 6)) !== false) @rename($tmpGz, $base . '.gz'); else @unlink($tmpGz);
            // now and then, sweep copies nobody has asked for in an hour
            if (mt_rand(1, 50) === 1) {
                foreach (glob(sys_get_temp_dir() . '/scratchcensus_pc_*') ?: [] as $f) {
                    if (time() - (int)@filemtime($f) > 3600) @unlink($f);
                }
            }
        }
        return $buf;
    });
}
