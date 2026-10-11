<?php
// Adds (or removes) a project in a Scratch studio using the account in config.php.
// Usage: script.php?key=CRON_SECRET&studio=111,222,333&project=987654321[&action=remove]
//    or: script.php?key=CRON_SECRET&project=987654321&action=removeall
//    or: script.php?key=CRON_SECRET&project=987654321&action=queue
//    or: script.php?key=CRON_SECRET&action=testmail   (sends a test email to EMAIL_REPORT_TO and says what happened)
// queue adds the project to IDs from the top of scratch-session-queue.txt for RUN_BUDGET_SEC seconds (filled by the
// "Add this page to queue" button in candidates.php) and deletes each ID from the file once it was tried.
// An ID stays queued only if the run was rate limited (429) or Scratch/the network failed (5xx).
// removeall removes the project from every studio the log (scratch-session-log.txt) says it is still in.
// Run it again until it says nothing is left.
// Cron: run the queue action on a schedule. A lock file makes a run stop at once while the previous one is still
// going, and after a 429 the queue action does nothing until Scratch's Retry-After (else 30 minutes) is over.
// Add &force=1 to run the queue anyway during that wait.
// Add &manual=1 to run the queue even while it is paused (the Pause button in candidates.php) for a manual run or test.
// A manual run logs in with the config.php account (SCRATCH_USER), not the acc.csv rotation, and does not touch the rotation,
// the counters or the shared 429 wait.
// All bookkeeping (rotation, counters, 429 waits, saved logins) lives in scratch-session-state.json. Besides it only the log,
// the queue, the run lock and the pause flag exist as scratch-session-* files.
// studio takes one ID or a comma/space separated list. At most MAX_PER_RUN studios per add/remove request,
// with PAUSE_SEC between them. The leftover IDs are printed so you can paste them into the next run.

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

if (($_GET['key'] ?? '') !== CRON_SECRET) {
    http_response_code(404);
    exit;
}

$T0 = microtime(true);

const SCRATCH_UA = 'Mozilla/5.0 (compatible; ScratchCensus/1.0)';
const SESSION_MAX_AGE = 43200; // reuse a login for 12 hours
const MAX_PER_RUN = 100;        // studios per add/remove request (the queue action is limited by time instead)
const RUN_BUDGET_SEC = 50;      // a queue run starts new requests for this many seconds, then finishes the ones in flight
const PAUSE_SEC = 0.2;          // gap between request STARTS in seconds (decimals are fine): the speed limit is 1 / PAUSE_SEC per second
const MAX_INFLIGHT = 4;         // queue only: requests allowed to be on the way at once (about request time / PAUSE_SEC + 1)
const ACCOUNT_CYCLE_STUDIOS = 300; // switch accounts after this many successful adds on one account
const EMAIL_EVERY_SWITCHES = 5;   // send email report after this many account switches

// A configured curl handle for one Scratch call. $shareConn reuses the TLS connection between plain calls.
function scratch_handle(string $method, string $url, string $jar, array $headers = [], ?string $body = null, bool $shareConn = true) {
    static $share = null;
    if ($shareConn && $share === null && function_exists('curl_share_init')) {
        $share = curl_share_init();
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
    }
    $ch = curl_init($url);
    if ($shareConn && $share) {
        curl_setopt($ch, CURLOPT_SHARE, $share);
    }
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_USERAGENT => SCRATCH_UA,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => array_merge(['Referer: https://scratch.mit.edu/'], $headers),
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    } elseif ($method === 'POST' || $method === 'PUT') {
        $opts[CURLOPT_POSTFIELDS] = '';
    }
    curl_setopt_array($ch, $opts);
    return $ch;
}

// One HTTP call. Returns [status code, raw headers, body].
function scratch_http(string $method, string $url, string $jar, array $headers = [], ?string $body = null): array {
    $ch = scratch_handle($method, $url, $jar, $headers, $body);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
    unset($ch);
    if ($raw === false) {
        throw new Exception("cURL error: $err");
    }
    return [$code, substr($raw, 0, $hsize), substr($raw, $hsize)];
}

// Newest scratchcsrftoken value found in the cookie jar file.
function csrf_from_jar(string $jar): ?string {
    $found = null;
    if (is_file($jar)) {
        foreach (file($jar) as $line) {
            if (strpos($line, 'scratchcsrftoken') !== false) {
                $parts = preg_split('/\s+/', trim($line));
                $val = end($parts);
                if ($val !== '' && $val !== '""') {
                    $found = $val;
                }
            }
        }
    }
    return $found;
}

// scratchcsrftoken from Set-Cookie headers, falling back to the jar.
function csrf_from_response(string $headers, string $jar): ?string {
    if (preg_match_all('/scratchcsrftoken=([^;\s"]+)/i', $headers, $m) && !empty($m[1])) {
        return $m[1][count($m[1]) - 1];
    }
    return csrf_from_jar($jar);
}

function scratch_login(string $user, string $pass, string $jar): array {
    @unlink($jar);

    $csrf = null;
    $info = '';
    for ($i = 0; $i < 3 && !$csrf; $i++) {
        if ($i > 0) {
            usleep(800000);
        }
        [$code, $headers] = scratch_http('GET', 'https://scratch.mit.edu/csrf_token/', $jar);
        $csrf = csrf_from_response($headers, $jar);
        preg_match_all('/Set-Cookie:\s*([^=;\s]+)=/i', $headers, $names);
        $info = "HTTP $code, cookies received: " . (implode(',', $names[1]) ?: 'none');
    }
    if (!$csrf) {
        throw new Exception("No CSRF token after 3 tries ($info). Scratch may be blocking this server.");
    }

    [$code, , $body] = scratch_http('POST', 'https://scratch.mit.edu/login/', $jar, [
        'Content-Type: application/json',
        'X-CSRFToken: ' . $csrf,
        'X-Requested-With: XMLHttpRequest',
    ], json_encode([
        'username' => $user,
        'password' => $pass,
        'useMessages' => true,
    ]));

    $res = json_decode($body, true);
    if (empty($res[0]['token'])) {
        $snippet = substr(trim(strip_tags($body)), 0, 200);
        throw new Exception("Login failed (HTTP $code). Response: $snippet");
    }

    // Django rotates the CSRF token on login, so take the new one from the jar.
    @chmod($jar, 0600);
    return [
        'token' => $res[0]['token'],
        'csrf' => csrf_from_jar($jar) ?: $csrf,
        'saved' => time(),
    ];
}

// Load accounts from acc.csv (format: username,password per line)
function load_accounts(): array {
    $file = __DIR__ . '/acc.csv';
    if (!is_file($file)) {
        return [];
    }
    $accounts = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line && strpos($line, ',') !== false) {
            [$user, $pass] = explode(',', $line, 2);
            $accounts[] = ['user' => trim($user), 'pass' => trim($pass)];
        }
    }
    return $accounts;
}

const STATE_FILE = __DIR__ . '/scratch-session-state.json';

// Everything the script has to remember between runs lives in one JSON file: rotation position, counters,
// 429 waits, last report time and the saved logins. Read-modify-write under a lock, so overlapping requests cannot clobber it.
function state_update(callable $fn) {
    $fh = @fopen(STATE_FILE, 'c+');
    if (!$fh) {
        return null;
    }
    flock($fh, LOCK_EX);
    $st = json_decode((string)stream_get_contents($fh), true);
    if (!is_array($st)) {
        $st = [];
    }
    $res = $fn($st);
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($st));
    fflush($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    @chmod(STATE_FILE, 0600);
    return $res;
}

function state_get(): array {
    $fh = @fopen(STATE_FILE, 'r');
    if (!$fh) {
        return [];
    }
    flock($fh, LOCK_SH);
    $st = json_decode((string)stream_get_contents($fh), true);
    flock($fh, LOCK_UN);
    fclose($fh);
    return is_array($st) ? $st : [];
}

// One-time cleanup: fold the old scratch-session-* helper files into the state file, then delete them.
function migrate_legacy_files(): void {
    $dir = __DIR__ . '/';
    $small = [];
    foreach (['account-idx.txt', 'cycle-count.txt', 'switch-count.txt', 'cooldown.txt', 'last-mail.txt', 'limits.json'] as $n) {
        if (is_file($dir . 'scratch-session-' . $n)) {
            $small[$n] = $dir . 'scratch-session-' . $n;
        }
    }
    $sessFiles = [];
    foreach (glob($dir . 'scratch-session-*') ?: [] as $f) {
        if (preg_match('/scratch-session-[0-9a-f]{32}\.(cookies|json)$/', $f)) {
            $sessFiles[] = $f;
        }
    }
    if (!$small && !$sessFiles) {
        return;
    }

    // keep logins that are still fresh, so no account has to log in again
    $users = array_column(load_accounts(), 'user');
    $users[] = SCRATCH_USER;
    $sessions = [];
    foreach (array_unique($users) as $u) {
        $base = $dir . 'scratch-session-' . md5(CRON_SECRET . $u);
        if (is_file($base . '.cookies') && is_file($base . '.json')) {
            $j = json_decode((string)@file_get_contents($base . '.json'), true);
            if (is_array($j) && !empty($j['token']) && time() - (int)($j['saved'] ?? 0) <= SESSION_MAX_AGE) {
                $sessions[$u] = [
                    'token' => $j['token'],
                    'csrf' => $j['csrf'] ?? '',
                    'saved' => (int)$j['saved'],
                    'cookies' => (string)@file_get_contents($base . '.cookies'),
                ];
            }
        }
    }
    $read = function (string $n) use ($small) {
        return isset($small[$n]) ? trim((string)@file_get_contents($small[$n])) : '';
    };
    state_update(function (array &$st) use ($read, $sessions) {
        $map = ['idx' => 'account-idx.txt', 'cycle' => 'cycle-count.txt', 'switches' => 'switch-count.txt',
                'cooldown_until' => 'cooldown.txt', 'last_mail' => 'last-mail.txt'];
        foreach ($map as $key => $n) {
            $v = $read($n);
            if ($v !== '' && !isset($st[$key])) {
                $st[$key] = (int)$v;
            }
        }
        $lim = json_decode($read('limits.json'), true);
        if (is_array($lim) && !isset($st['limits'])) {
            $st['limits'] = $lim;
        }
        foreach ($sessions as $u => $sess) {
            if (!isset($st['sessions'][$u])) {
                $st['sessions'][$u] = $sess;
            }
        }
    });
    foreach (array_merge(array_values($small), $sessFiles) as $f) {
        @unlink($f);
    }
    out('Cleanup: moved ' . (count($small) + count($sessFiles)) . " old scratch-session files into scratch-session-state.json and deleted them.\n");
}

// Get the current account from the rotation state
function get_current_account_idx(): int {
    $idx = (int)(state_get()['idx'] ?? 0);
    return ($idx >= 0 && $idx < count(load_accounts())) ? $idx : 0;
}

// Set the current account index
function set_account_idx(int $idx): void {
    if ($idx >= count(load_accounts())) {
        $idx = 0;
    }
    state_update(function (array &$st) use ($idx) {
        $st['idx'] = $idx;
    });
}

function set_cycle_counter(int $n): void {
    state_update(function (array &$st) use ($n) {
        $st['cycle'] = $n;
    });
}

function increment_switch_counter(): int {
    return (int)state_update(function (array &$st) {
        $st['switches'] = (int)($st['switches'] ?? 0) + 1;
        return $st['switches'];
    });
}

// Collect activity log entries since last report
function get_activity_since(int $lines_back = 500): array {
    $file = __DIR__ . '/scratch-session-log.txt';
    if (!is_file($file)) {
        return [];
    }
    $all = file($file, FILE_IGNORE_NEW_LINES);
    return array_slice($all, -$lines_back);
}

// Send one email to EMAIL_REPORT_TO. Never throws. Returns [accepted, text saying what happened].
function mail_send(string $subject, string $body): array {
    if (!defined('EMAIL_REPORT_TO') || !filter_var(EMAIL_REPORT_TO, FILTER_VALIDATE_EMAIL)) {
        return [false, 'EMAIL_REPORT_TO is missing or not a valid address in config.php'];
    }
    $from = defined('EMAIL_REPORT_FROM') ? EMAIL_REPORT_FROM : 'noreply@scratchnews.net';
    $headers = "From: ScratchCensus <$from>\r\n";
    $headers .= "Content-Type: text/plain; charset=utf-8\r\n";
    error_clear_last();
    $sent = @mail(EMAIL_REPORT_TO, $subject, $body, $headers);
    if ($sent) {
        return [true, 'handed to the mail system (to ' . EMAIL_REPORT_TO . ", from $from)"];
    }
    $err = error_get_last()['message'] ?? 'mail() returned false';
    return [false, "FAILED: $err (to " . EMAIL_REPORT_TO . ", from $from)"];
}

// Email report of account switches and activity. Sends at most one per EMAIL_MIN_GAP_SEC and logs the result.
const EMAIL_MIN_GAP_SEC = 900;
function send_activity_report(array $recent_lines, int $switch_count): void {
    $last = (int)(state_get()['last_mail'] ?? 0);
    if (time() - $last < EMAIL_MIN_GAP_SEC) {
        out("Email report skipped: one was sent less than " . (int)(EMAIL_MIN_GAP_SEC / 60) . " minutes ago.\n");
        return;
    }
    state_update(function (array &$st) {
        $st['last_mail'] = time();
    });
    $body = "Account switch #$switch_count.\n\nRecent activity (last " . count($recent_lines) . " lines):\n\n" . implode("\n", $recent_lines);
    [, $info] = mail_send("ScratchCensus: account switch #$switch_count", $body);
    out("Email report $info\n");
}

// Record an account switch: save the index, restart the run counter, log it, send the periodic report.
function do_switch(int $idx, int $total, string $why): void {
    set_account_idx($idx);
    set_cycle_counter(0);
    $n = increment_switch_counter();
    out('ACCOUNT_SWITCH: Switched to account ' . ($idx + 1) . " of $total (switch #$n$why)\n");
    if ($n % EMAIL_EVERY_SWITCHES === 0) {
        send_activity_report(get_activity_since(100), $n);
    }
}

// A login is kept inside the state file. While a run is going its cookies sit in a temp jar (curl needs a file for them).
function session_jar(string $user): string {
    return sys_get_temp_dir() . '/sc-' . md5(CRON_SECRET . $user) . '.jar';
}

function session_save(string $user, array $auth, string $jar): void {
    $cookies = is_file($jar) ? (string)file_get_contents($jar) : '';
    state_update(function (array &$st) use ($user, $auth, $cookies) {
        $st['sessions'][$user] = [
            'token' => $auth['token'],
            'csrf' => $auth['csrf'],
            'saved' => $auth['saved'] ?? time(),
            'cookies' => $cookies,
        ];
    });
}

// Put the (possibly rotated) cookies back into the state file and remove the temp jar.
function session_persist(string $user, string $jar): void {
    if ($user === '' || $jar === '' || !is_file($jar)) {
        return;
    }
    $cookies = (string)file_get_contents($jar);
    state_update(function (array &$st) use ($user, $cookies) {
        if (isset($st['sessions'][$user])) {
            $st['sessions'][$user]['cookies'] = $cookies;
        }
    });
    @unlink($jar);
}

function session_drop(string $user, string $jar): void {
    @unlink($jar);
    state_update(function (array &$st) use ($user) {
        unset($st['sessions'][$user]);
    });
}

// Reuse the saved login for one account, or log in. Returns [auth, jar file, freshly logged in].
function open_session(string $user, string $pass): array {
    $jar = session_jar($user);
    $s = state_get()['sessions'][$user] ?? null;
    if ($s && !empty($s['token']) && !empty($s['cookies']) && time() - (int)($s['saved'] ?? 0) <= SESSION_MAX_AGE) {
        file_put_contents($jar, $s['cookies']);
        @chmod($jar, 0600);
        $auth = ['token' => $s['token'], 'csrf' => csrf_from_jar($jar) ?: ($s['csrf'] ?? ''), 'saved' => (int)$s['saved']];
        return [$auth, $jar, false];
    }
    $auth = scratch_login($user, $pass, $jar);
    session_save($user, $auth, $jar);
    return [$auth, $jar, true];
}

function studio_url(int $studio, int $project): string {
    return "https://api.scratch.mit.edu/studios/$studio/project/$project";
}

function studio_headers(array $auth, string $jar): array {
    return [
        'X-Token: ' . $auth['token'],
        'X-CSRFToken: ' . (csrf_from_jar($jar) ?: $auth['csrf']),
        'X-Requested-With: XMLHttpRequest',
        'Origin: https://scratch.mit.edu',
    ];
}

function studio_request(string $method, int $studio, int $project, array $auth, string $jar): array {
    return scratch_http($method, studio_url($studio, $project), $jar, studio_headers($auth, $jar));
}

function hkey($ch): int {
    return is_object($ch) ? spl_object_id($ch) : (int)$ch;
}

// Finish the batch even if the browser tab is closed or backgrounded.
ignore_user_abort(true);
set_time_limit(180);

// Print and also append to a log you can open later (matches the scratch-session-* gitignore rule).
function out(string $line): void {
    echo $line;
    @file_put_contents(__DIR__ . '/scratch-session-log.txt', date('Y-m-d H:i:s') . ' ' . $line, FILE_APPEND);
    if (function_exists('ob_flush')) { @ob_flush(); }
    flush();
}

// Studios the log says the project is currently in: the last ADD/DEL line per studio decides.
// Old log lines without a tag count as an ADD only when their JSON names this project.
function logged_studios(int $project): array {
    $file = __DIR__ . '/scratch-session-log.txt';
    if (!is_file($file)) {
        return [];
    }
    $in = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/ (ADD|DEL) (\d+) (\d+): HTTP 2\d\d/', $line, $m)) {
            if ((int)$m[2] === $project) {
                if ($m[1] === 'ADD') { $in[(int)$m[3]] = true; } else { unset($in[(int)$m[3]]); }
            }
        } elseif (preg_match('/ (\d+): HTTP 2\d\d .*"projectId":"' . $project . '"/', $line, $m)) {
            $in[(int)$m[1]] = true;
        }
    }
    return array_keys($in);
}

const QUEUE_FILE = __DIR__ . '/scratch-session-queue.txt';
const COOLDOWN_DEFAULT_SEC = 1800; // wait this long after a 429 when Scratch does not say how long

// Seconds to hold an account back after a 429: Scratch's Retry-After (60 s to 24 h), else the default above.
function retry_after_sec(string $headers): int {
    if (preg_match('/^Retry-After:\s*(\d+)/mi', $headers, $m)) {
        return max(60, min(86400, (int)$m[1]));
    }
    return COOLDOWN_DEFAULT_SEC;
}

// username => unix time until which that account is rate limited (expired entries are dropped).
function limits_load(): array {
    $now = time();
    $res = [];
    foreach ((state_get()['limits'] ?? []) as $u => $t) {
        if ((int)$t > $now) {
            $res[(string)$u] = (int)$t;
        }
    }
    return $res;
}

function limit_set(string $user, int $sec): void {
    state_update(function (array &$st) use ($user, $sec) {
        $now = time();
        $l = [];
        foreach (($st['limits'] ?? []) as $u => $t) {
            if ((int)$t > $now) {
                $l[(string)$u] = (int)$t;
            }
        }
        $l[$user] = $now + $sec;
        $st['limits'] = $l;
    });
}

// Next account after $from that is not rate limited, or null when every other account is.
function next_free_account(array $accounts, int $from, array $limits): ?int {
    $c = count($accounts);
    for ($i = 1; $i < $c; $i++) {
        $idx = ($from + $i) % $c;
        if (!isset($limits[$accounts[$idx]['user']])) {
            return $idx;
        }
    }
    return null;
}

// Seconds until the first rate limited account is free again (at least 60).
function limits_min_left(array $accounts, array $limits): int {
    $now = time();
    $min = null;
    foreach ($accounts as $a) {
        $left = ($limits[$a['user']] ?? $now) - $now;
        if ($min === null || $left < $min) {
            $min = $left;
        }
    }
    return max(60, (int)$min);
}

// Shared wait: set only when every account is rate limited (or the single config.php account is). Queue runs do nothing until it ends.
function cooldown_set(int $sec): void {
    state_update(function (array &$st) use ($sec) {
        $st['cooldown_until'] = time() + $sec;
    });
}

function cooldown_clear(): void {
    if (isset(state_get()['cooldown_until'])) {
        state_update(function (array &$st) {
            unset($st['cooldown_until']);
        });
    }
}

function cooldown_start(string $headers): int {
    $sec = retry_after_sec($headers);
    cooldown_set($sec);
    return $sec;
}

// Seconds the queue action still has to wait (0 = go ahead).
function cooldown_left(): int {
    return max(0, (int)(state_get()['cooldown_until'] ?? 0) - time());
}

function queue_ids(): array {
    $lines = is_file(QUEUE_FILE) ? file(QUEUE_FILE, FILE_IGNORE_NEW_LINES) : [];
    return array_values(array_unique(array_filter(array_map('intval', $lines), fn($v) => $v > 0)));
}

// Apply a whole run's results to the queue file in one locked pass: $done IDs are dropped, $toEnd IDs go to the end.
function queue_apply(array $done, array $toEnd): void {
    $fh = @fopen(QUEUE_FILE, 'c+');
    if (!$fh || !flock($fh, LOCK_EX)) {
        return;
    }
    $drop = array_flip(array_merge($done, $toEnd));
    $keep = [];
    while (($l = fgets($fh)) !== false) {
        $v = (int)$l;
        if ($v > 0 && !isset($drop[$v])) {
            $keep[] = $v;
        }
    }
    foreach ($toEnd as $v) {
        $keep[] = $v;
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, $keep ? implode("\n", $keep) . "\n" : '');
    flock($fh, LOCK_UN);
    fclose($fh);
}

// Results waiting to be written to the queue file. Flushed after the loop, and by the shutdown hook if the run dies.
$pendDone = [];
$pendEnd = [];
function queue_flush(): void {
    global $pendDone, $pendEnd;
    if ($pendDone || $pendEnd) {
        queue_apply($pendDone, $pendEnd);
        $pendDone = [];
        $pendEnd = [];
    }
}
register_shutdown_function('queue_flush');

try {
    migrate_legacy_files();
    $studios = array_values(array_unique(array_filter(
        array_map('intval', preg_split('/[\s,;]+/', (string)($_GET['studio'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)),
        fn($n) => $n > 0
    )));
    $project = (int)($_GET['project'] ?? 0);
    $action  = (string)($_GET['action'] ?? 'add');
    $method  = ($action === 'remove' || $action === 'removeall') ? 'DELETE' : 'POST';
    $tag     = ($method === 'DELETE') ? 'DEL' : 'ADD';
    $manual  = ($action === 'queue' && !empty($_GET['manual']));

    if ($action === 'testmail') {
        [$mailOk, $mailInfo] = mail_send('ScratchCensus test email', 'Test email from script.php sent ' . date('Y-m-d H:i:s') . ".\nIf you can read this, report emails work.\n");
        out('Test email ' . $mailInfo . "\n");
        if ($mailOk) {
            echo "Accepted by the server. Now check your inbox and spam folder. If nothing arrives in a few minutes, the host or your mail provider is dropping it: try an EMAIL_REPORT_FROM address on your own domain in config.php.\n";
        }
        exit;
    }

    if ($action === 'removeall' && $project > 0) {
        $studios = logged_studios($project);
        if (!$studios) {
            echo "Nothing left to remove for project $project according to the log.\n";
            exit;
        }
        echo count($studios) . " studios still logged for project $project.\n";
    }

    if ($action === 'queue' && $project > 0) {
        $left = $manual ? 0 : cooldown_left(); // a manual run uses the config.php account, so the shared wait does not apply
        if ($left > 0 && empty($_GET['force'])) {
            echo 'Rate limited earlier. Waiting ' . (int)ceil($left / 60) . " more minutes before the next queue run (add &force=1 to run anyway).\n";
            exit;
        }
        // one queue run at a time: a cron tick that starts while the last run is still going just stops
        $lock = @fopen(__DIR__ . '/scratch-session-queue.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            echo "Another queue run is still going.\n";
            exit;
        }
        $studios = queue_ids();
        if (!$studios) {
            echo "The queue is empty.\n";
            exit;
        }
        echo count($studios) . " IDs in the queue.\n";

        
        // Check if paused
        $pauseFile = __DIR__ . '/scratch-session-paused.txt';
        if (is_file($pauseFile) && empty($_GET['manual'])) {
            $pausedAt = (int)@file_get_contents($pauseFile);
            $pausedFor = $pausedAt > 0 ? 'since ' . date('Y-m-d H:i:s', $pausedAt) : '';
            out("Queue is paused $pausedFor. Exiting.\n");
            flock($lock, LOCK_UN);
            fclose($lock);
            exit;
        }
    }

    if (!$studios || $project <= 0) {
        throw new Exception('Missing studio or project parameter.');
    }

    $perRun = ($action === 'queue') ? count($studios) : MAX_PER_RUN; // the queue is limited by RUN_BUDGET_SEC, not by a count
    $todo = array_slice($studios, 0, $perRun);
    $rest = array_slice($studios, $perRun);

    // Handle account rotation in queue mode
    $currentUser = SCRATCH_USER;
    $currentPass = SCRATCH_PASS;
    $accounts = [];
    $accountIdx = 0;
    $cycleBase = 0; // successful adds the current account already did before this run

    if ($manual) {
        $lim = limits_load();
        if (empty($_GET['force']) && isset($lim[SCRATCH_USER])) {
            out('Manual run: the config.php account ' . SCRATCH_USER . ' is rate limited for about ' . (int)ceil(($lim[SCRATCH_USER] - time()) / 60) . " more minutes (add &force=1 to run anyway).\n");
            exit;
        }
        out('Manual run: using the config.php account ' . SCRATCH_USER . ", no rotation.\n");
    }
    if ($action === 'queue' && !$manual) {
        $accounts = load_accounts();
        if (!empty($accounts)) {
            $accountIdx = get_current_account_idx();
            $cycleBase = (int)(state_get()['cycle'] ?? 0);

            // This account has done its share: use the next one
            if ($cycleBase >= ACCOUNT_CYCLE_STUDIOS) {
                $accountIdx = ($accountIdx + 1) % count($accounts);
                do_switch($accountIdx, count($accounts), '');
                $cycleBase = 0;
            }

            // Skip an account that is still waiting out a 429
            $limits = limits_load();
            if (empty($_GET['force']) && isset($limits[$accounts[$accountIdx]['user']])) {
                $free = next_free_account($accounts, $accountIdx, $limits);
                if ($free === null) {
                    $wait = limits_min_left($accounts, $limits);
                    cooldown_set($wait);
                    out('All ' . count($accounts) . ' accounts are rate limited. Queue runs wait ' . (int)ceil($wait / 60) . " minutes (add &force=1 to run anyway).\n");
                    exit;
                }
                $accountIdx = $free;
                do_switch($accountIdx, count($accounts), ', previous account rate limited');
                $cycleBase = 0;
            }

            $currentUser = $accounts[$accountIdx]['user'];
            $currentPass = $accounts[$accountIdx]['pass'];
        }
    }

    [$auth, $jar, $fresh] = open_session($currentUser, $currentPass);
    // the logins live in the state file: hand the cookies back and remove the temp jar even if the run dies
    register_shutdown_function(function () {
        global $currentUser, $jar;
        session_persist((string)($currentUser ?? ''), (string)($jar ?? ''));
    });

    $ok = 0;          // successful calls this run
    $tried = 0;       // requests started
    $acctOk = 0;      // successful adds by the current account this run
    $cdCleared = false;
    $gen = 0;         // counts logins, so answers to requests sent with an old login can be told apart
    $inflightMax = ($action === 'queue') ? MAX_INFLIGHT : 1;
    $deadline = $T0 + (($action === 'queue') ? RUN_BUDGET_SEC : 170);
    $queueIdx = 0;
    $retry = [];            // studios to send again (stale login)
    $retryAfterSwitch = []; // studios that got a 429, sent again with the next account
    $inflight = [];
    $stop = false;          // no new requests
    $needSwitch = '';       // '' or the reason to switch account once nothing is in flight
    $halt = false;
    $hit419 = false;
    $limited429 = false;    // single account (or manual) got a 429
    $hdr429 = '';
    $netErrs = 0;
    $lastStart = 0.0;
    $mh = curl_multi_init();

    // Move to the next free account (not rate limited). False when there is none.
    $switchAccount = function (string $why) use (&$accounts, &$accountIdx, &$currentUser, &$currentPass, &$auth, &$jar, &$fresh, &$gen, &$cycleBase, &$acctOk): bool {
        $free = next_free_account($accounts, $accountIdx, limits_load());
        while ($free !== null) {
            session_persist($currentUser, $jar);
            $accountIdx = $free;
            do_switch($accountIdx, count($accounts), ', ' . $why);
            $currentUser = $accounts[$accountIdx]['user'];
            $currentPass = $accounts[$accountIdx]['pass'];
            try {
                [$auth, $jar, $fresh] = open_session($currentUser, $currentPass);
                $gen++;
                $cycleBase = 0;
                $acctOk = 0;
                return true;
            } catch (Throwable $e) {
                out('Login failed for account ' . ($accountIdx + 1) . ': ' . $e->getMessage() . "\n");
                limit_set($currentUser, 3600);
                $free = next_free_account($accounts, $accountIdx, limits_load());
            }
        }
        return false;
    };

    while (!$halt) {
        $more = $retry || $queueIdx < count($todo);
        $now = microtime(true);
        $canLaunch = !$stop && $more && $now < $deadline && count($inflight) < $inflightMax;

        // Start the next request when PAUSE_SEC has passed since the last start. It does not wait for the answer.
        if ($canLaunch && $now - $lastStart >= PAUSE_SEC) {
            $studio = $retry ? array_shift($retry) : $todo[$queueIdx++];
            $lastStart = microtime(true);
            $tried++;
            $ch = scratch_handle($method, studio_url($studio, $project), $jar, studio_headers($auth, $jar), null, false);
            curl_multi_add_handle($mh, $ch);
            $inflight[hkey($ch)] = ['studio' => $studio, 'ch' => $ch, 'gen' => $gen];
            unset($ch);
        }

        if (!$inflight) {
            if ($needSwitch !== '') {
                $why = $needSwitch;
                $needSwitch = '';
                if ($switchAccount($why)) {
                    $stop = false;
                    $retry = array_merge($retryAfterSwitch, $retry);
                    $retryAfterSwitch = [];
                    continue;
                }
                if ($why === 'account limit') {
                    // everyone else is rate limited: carry on with this account
                    $stop = false;
                    $cycleBase = 0;
                    $acctOk = 0;
                    continue;
                }
                $wait = limits_min_left($accounts, limits_load());
                cooldown_set($wait);
                out('Stopped: all ' . count($accounts) . ' accounts are rate limited. Queue runs wait ' . (int)ceil($wait / 60) . " minutes (add &force=1 to run anyway).\n");
                $retry = array_merge($retryAfterSwitch, $retry);
                $retryAfterSwitch = [];
                break;
            }
            if (!$canLaunch) {
                break; // finished, out of time, or stopped
            }
            usleep(5000);
            continue;
        }

        // Let the requests in flight progress, wake up when the next one may start.
        curl_multi_exec($mh, $active);
        $wait = $canLaunch ? max(0.001, PAUSE_SEC - (microtime(true) - $lastStart)) : 0.25;
        if (curl_multi_select($mh, $wait) === -1) {
            usleep(5000);
        }
        curl_multi_exec($mh, $active);

        // Collect finished requests.
        $done = [];
        while ($info = curl_multi_info_read($mh)) {
            $ch = $info['handle'];
            $key = hkey($ch);
            $raw = (string)curl_multi_getcontent($ch);
            $dCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $dHsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $dErr = ($info['result'] === CURLE_OK) ? '' : curl_error($ch);
            curl_multi_remove_handle($mh, $ch);
            $meta = ['studio' => $inflight[$key]['studio'] ?? 0, 'gen' => $inflight[$key]['gen'] ?? $gen];
            if (PHP_VERSION_ID < 80000) {
                curl_close($ch);
            }
            unset($inflight[$key], $ch, $info); // dropping the last reference makes curl write the cookie jar
            $done[] = [$meta['studio'], $dErr !== '' ? 0 : $dCode, substr($raw, 0, $dHsize), substr($raw, $dHsize), $meta['gen'], $dErr];
        }

        foreach ($done as [$studio, $code, $hdrs, $body, $g, $err]) {
            if ($code === 0) {
                out("$tag $project $studio: network error $err\n");
                if (++$netErrs >= 5) {
                    out("Stopped: 5 network errors in a row.\n");
                    $halt = true;
                }
                continue;
            }
            $netErrs = 0;

            // Sent with a login that has since been replaced: just send it again.
            if ($g < $gen && in_array($code, [401, 403, 419], true)) {
                array_unshift($retry, $studio);
                continue;
            }

            // Queue run: a 419 means this account's session is bad. Stop, the next queue run uses the next account.
            if ($code === 419 && $action === 'queue') {
                if (!$hit419) {
                    $hit419 = true;
                    $stop = true;
                    out("$tag $project $studio: HTTP 419. " . ($manual ? 'The config.php account was rejected, run again to log in fresh.' : 'Account ' . ($accountIdx + 1) . ' failed, the next queue run switches account.') . "\n");
                }
                continue; // the studio stays queued
            }
            if ($code === 419) {
                // DELETE rotates the CSRF token: retry once with the fresh one from the jar.
                [$code, $hdrs, $body] = studio_request($method, $studio, $project, $auth, $jar);
            }

            // A saved session can go stale: log in again once and retry.
            if (!$fresh && in_array($code, [401, 403, 419], true)) {
                $auth = scratch_login($currentUser, $currentPass, $jar);
                session_save($currentUser, $auth, $jar);
                $fresh = true;
                $gen++;
                [$code, $hdrs, $body] = studio_request($method, $studio, $project, $auth, $jar);
            }

            out("$tag $project $studio: HTTP $code " . substr(trim(preg_replace('/\s+/', ' ', $body)), 0, 150) . "\n");
            if ($code >= 200 && $code < 300) {
                $ok++;
                $acctOk++;
            }
            if ($action === 'queue') {
                if ($code > 0 && $code < 500 && $code !== 429) {
                    $pendDone[] = $studio;
                } elseif ($code >= 500) {
                    $pendEnd[] = $studio;
                }
                if ($code >= 200 && $code < 300 && !$manual && !$cdCleared) { // adds work again, so any earlier shared wait is over
                    cooldown_clear();
                    $cdCleared = true;
                }
            }

            // Rate limited
            if ($code === 429) {
                if ($action === 'queue' && count($accounts) > 1) {
                    // Hold this account back and carry on with the next free one once the requests in flight are done.
                    if ($needSwitch !== 'after 429') {
                        limit_set($currentUser, retry_after_sec((string)$hdrs));
                    }
                    $retryAfterSwitch[] = $studio;
                    $stop = true;
                    $needSwitch = 'after 429';
                } else {
                    if (!$limited429) {
                        $limited429 = true;
                        $hdr429 = (string)$hdrs;
                    }
                    $stop = true;
                }
                continue;
            }

            // This account has done its share of adds: switch once the requests in flight are done.
            if ($action === 'queue' && count($accounts) > 1 && $needSwitch === '' && !$stop && $cycleBase + $acctOk >= ACCOUNT_CYCLE_STUDIOS) {
                $stop = true;
                $needSwitch = 'account limit';
            }
        }
    }
    curl_multi_close($mh);

    if ($hit419) {
        session_drop($currentUser, $jar);
    }
    if ($action === 'queue' && !$manual && $accounts) {
        set_cycle_counter($hit419 ? ACCOUNT_CYCLE_STUDIOS : $cycleBase + $acctOk); // after a 419 the next run switches account
    }
    if ($limited429) {
        if ($manual) {
            $wait = retry_after_sec($hdr429);
            limit_set($currentUser, $wait);
            out('Stopped: the config.php account is rate limited. Manual runs wait ' . (int)ceil($wait / 60) . " minutes (add &force=1 to run anyway).\n");
        } elseif ($action === 'queue') {
            $wait = cooldown_start($hdr429);
            out('Stopped: rate limited. Queue runs wait ' . (int)ceil($wait / 60) . " minutes (add &force=1 to run anyway).\n");
        } else {
            out("Stopped: rate limited. Wait a while before continuing.\n");
        }
    }
    $rest = array_merge($retry, array_slice($todo, $queueIdx), $rest);

    session_persist($currentUser, $jar);
    queue_flush();
    $secs = microtime(true) - $T0;
    out("\nDone: $ok ok of $tried tried in " . number_format($secs, 2) . 's (' . number_format($secs > 0 ? $tried / $secs : 0, 2) . " studios/s).\n");
    if ($action === 'queue') {
        out('Left in queue: ' . count(queue_ids()) . "\n");
    } elseif ($rest) {
        out('Remaining (' . count($rest) . '): ' . implode(',', $rest) . "\n");
    }
} catch (Throwable $e) {
    out('Error: ' . $e->getMessage() . "\n");
}
