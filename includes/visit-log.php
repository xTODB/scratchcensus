<?php
// Page-view logging into the main ScratchNews visitor log (its `visits` and `daily_unique_visitors`
// tables, same rows its own logVisit() writes), so ScratchCensus shows up in the admin Visitor Log
// and in the site-wide unique visitor counts.
//
// ScratchCensus has its own database, so this opens a second, short-lived connection to the main
// one. Credentials, in order:
//   1. SN_DB_HOST / SN_DB_USER / SN_DB_PASS / SN_DB_NAME defined in this site's config.php, or
//   2. read from the main site's config.php (SN_MAIN_CONFIG, default: three folders up),
//      without including it, so its constants never clash with this site's.
// Anything going wrong is swallowed: logging must never break or slow a page.
defined('VISIT_LOG_ENABLED') || define('VISIT_LOG_ENABLED', true);

function snMainDbCreds(): ?array {
    if (defined('SN_DB_HOST') && defined('SN_DB_USER') && defined('SN_DB_PASS') && defined('SN_DB_NAME')) {
        return [SN_DB_HOST, SN_DB_USER, SN_DB_PASS, SN_DB_NAME];
    }
    $file = defined('SN_MAIN_CONFIG') ? SN_MAIN_CONFIG : dirname(__DIR__, 3) . '/config.php';
    $src = @file_get_contents($file);
    if ($src === false) return null;
    $out = [];
    foreach (['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'] as $k) {
        if (!preg_match('/define\(\s*[\'"]' . $k . '[\'"]\s*,\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")\s*\)/', $src, $m)) return null;
        $out[] = isset($m[2]) && $m[2] !== '' ? stripcslashes($m[2]) : str_replace(['\\\\', "\\'"], ['\\', "'"], $m[1] ?? '');
    }
    return $out;
}

// $page examples: '/s/census', '/s/census/studios'. Null = the request path. Once per request.
function censusLogVisit(?string $page = null): void {
    static $done = false;
    if ($done || !VISIT_LOG_ENABLED) return;
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    if (isset($_GET['warm'])) return; // the cache pre-warm cron is not a visitor
    $done = true;
    if ($page === null) {
        $page = rtrim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/s/census', PHP_URL_PATH), '/');
        if ($page === '') $page = '/s/census';
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; // same source as the main site, so unique visitors match
    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $page = mb_substr($page, 0, 255);

    register_shutdown_function(function () use ($page, $ip, $ua) {
        try {
            // let the visitor have the page first
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            elseif (function_exists('litespeed_finish_request')) @litespeed_finish_request();
            $c = snMainDbCreds();
            if (!$c) return;
            $db = mysqli_init();
            $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
            if (!@$db->real_connect($c[0], $c[1], $c[2], $c[3])) return;
            $db->set_charset('utf8mb4');
            $db->query("SET time_zone = '+00:00'");
            $stmt = $db->prepare("INSERT INTO visits (ip_address, page, user_agent) VALUES (?, ?, ?)");
            $stmt->bind_param('sss', $ip, $page, $ua);
            $stmt->execute();
            $stmt->close();
            $stmt = $db->prepare("INSERT IGNORE INTO daily_unique_visitors (visit_date, ip_address) VALUES (CURDATE(), ?)");
            $stmt->bind_param('s', $ip);
            $stmt->execute();
            $stmt->close();
            // the main site trims on every visit; here only now and then, same windows (3 days / 90 days)
            if (mt_rand(1, 40) === 1) {
                $db->query("DELETE FROM visits WHERE visited_at < DATE_SUB(NOW(), INTERVAL 3 DAY)");
                $db->query("DELETE FROM daily_unique_visitors WHERE visit_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)");
            }
            $db->close();
        } catch (\Throwable $e) {
            // never let logging break a page
        }
    });
}