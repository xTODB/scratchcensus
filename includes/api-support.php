<?php
// Shared pieces for the public API (api.php): JSON output, per-IP rate limiting, response cache.
// Everything is file based in the system temp folder, so a rate-limit check or a cache hit never
// touches the database. Override any of these in config.php.
defined('API_ENABLED')          || define('API_ENABLED', true);
defined('API_RATE_LIMIT')       || define('API_RATE_LIMIT', 60);   // cost units per IP per window (a plain request costs 1, a search 3)
defined('API_RATE_WINDOW_SEC')  || define('API_RATE_WINDOW_SEC', 60);
defined('API_CACHE_TTL_SEC')    || define('API_CACHE_TTL_SEC', 60);
defined('API_MAX_LIMIT')        || define('API_MAX_LIMIT', 100);   // most rows per request

function apiSend(int $code, array $body, array $headers = []): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    foreach (array_merge($GLOBALS['api_headers'] ?? [], $headers) as $k => $v) header("$k: $v");
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | (isset($_GET['pretty']) ? JSON_PRETTY_PRINT : 0)) . "\n";
    exit;
}

function apiError(int $code, string $msg, array $headers = []): void {
    apiSend($code, ['error' => $msg], $headers);
}

function apiClientIp(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; // same source the visit log uses
}

// Fixed window counter per IP. Returns [allowed, remaining, resetInSeconds].
function apiRateCheck(int $cost): array {
    $limit = (int)API_RATE_LIMIT;
    $win = max(1, (int)API_RATE_WINDOW_SEC);
    $slot = (int)floor(time() / $win);
    $dir = sys_get_temp_dir() . '/scratchcensus_rl';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $file = $dir . '/' . md5(apiClientIp() . '|' . __DIR__) . '-' . $slot;
    $used = 0;
    $fh = @fopen($file, 'c+');
    if ($fh) {
        if (flock($fh, LOCK_EX)) {
            $used = (int)stream_get_contents($fh);
            if ($used + $cost <= $limit) {
                $used += $cost;
                ftruncate($fh, 0);
                rewind($fh);
                fwrite($fh, (string)$used);
            } else {
                $used = $limit + 1; // over
            }
            flock($fh, LOCK_UN);
        }
        fclose($fh);
    }
    if (mt_rand(1, 100) === 1) { // sweep old windows now and then
        foreach (glob($dir . '/*') ?: [] as $f) if (time() - (int)@filemtime($f) > $win * 3) @unlink($f);
    }
    $reset = ($slot + 1) * $win - time();
    return [$used <= $limit, max(0, $limit - min($used, $limit)), $reset];
}

function apiCachePath(string $key): string {
    return sys_get_temp_dir() . '/scratchcensus_api_' . md5(__DIR__ . '|' . $key);
}

// Returns the cached JSON text for $key, or null.
function apiCacheGet(string $key): ?string {
    $f = apiCachePath($key);
    if (is_file($f) && time() - (int)@filemtime($f) < (int)API_CACHE_TTL_SEC) {
        $s = @file_get_contents($f);
        return $s === false ? null : $s;
    }
    return null;
}

function apiCachePut(string $key, string $json): void {
    $f = apiCachePath($key);
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $json) !== false) @rename($tmp, $f); else @unlink($tmp);
    if (mt_rand(1, 60) === 1) {
        foreach (glob(sys_get_temp_dir() . '/scratchcensus_api_*') ?: [] as $old) {
            if (time() - (int)@filemtime($old) > 600) @unlink($old);
        }
    }
}

function apiInt($v, int $default, int $min, int $max): int {
    if ($v === null || $v === '' || !preg_match('/^-?\d{1,10}$/', (string)$v)) return $default;
    return max($min, min($max, (int)$v));
}
