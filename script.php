<?php
// Adds (or removes) a project in a Scratch studio using the account in config.php.
// Usage: script.php?key=CRON_SECRET&studio=111,222,333&project=987654321[&action=remove]
//    or: script.php?key=CRON_SECRET&project=987654321&action=removeall
//    or: script.php?key=CRON_SECRET&project=987654321&action=queue
// queue adds the project to the top MAX_PER_RUN IDs of scratch-session-queue.txt (filled by the
// "Add this page to queue" button in candidates.php) and deletes each ID from the file once it was tried.
// An ID stays queued only if the run was rate limited (429) or Scratch/the network failed (5xx).
// removeall removes the project from every studio the log (scratch-session-log.txt) says it is still in.
// Run it again until it says nothing is left.
// Cron: run the queue action on a schedule. A lock file makes a run stop at once while the previous one is still
// going, and after a 429 the queue action does nothing until Scratch's Retry-After (else 30 minutes) is over.
// Add &force=1 to run the queue anyway during that wait.
// Add &manual=1 to run the queue even while it is paused (the Pause button in candidates.php) for a manual run or test.
// studio takes one ID or a comma/space separated list. At most MAX_PER_RUN studios per request,
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
const MAX_PER_RUN = 100;        // studios handled per request
const PAUSE_SEC = 0.35;           // pause between studios, in seconds (decimals are fine)
const ACCOUNT_CYCLE_RUNS = 6;     // switch accounts after this many queue runs (6 runs * 50 studios = 300)
const EMAIL_EVERY_SWITCHES = 5;   // send email report after this many account switches

// One HTTP call. Returns [status code, raw headers, body].
function scratch_http(string $method, string $url, string $jar, array $headers = [], ?string $body = null): array {
    static $share = null;
    if ($share === null && function_exists('curl_share_init')) {
        $share = curl_share_init();
        curl_share_setopt($share, CURLSHOPT_SHARE, CURL_LOCK_DATA_CONNECT);
    }
    $ch = curl_init($url);
    if ($share) {
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
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hsize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
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

function scratch_login(string $user, string $pass, string $jar, string $stateFile): array {
    @unlink($jar);
    @unlink($stateFile);

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
    $auth = [
        'token' => $res[0]['token'],
        'csrf' => csrf_from_jar($jar) ?: $csrf,
        'saved' => time(),
    ];
    file_put_contents($stateFile, json_encode($auth));
    return $auth;
}

function load_session(string $jar, string $stateFile): ?array {
    if (!is_file($jar) || !is_file($stateFile)) {
        return null;
    }
    $s = json_decode((string)file_get_contents($stateFile), true);
    if (!$s || empty($s['token']) || time() - ($s['saved'] ?? 0) > SESSION_MAX_AGE) {
        return null;
    }
    $s['csrf'] = csrf_from_jar($jar) ?: ($s['csrf'] ?? '');
    return $s;
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

// Get the current account from the rotation file
function get_current_account_idx(): int {
    $file = __DIR__ . '/scratch-session-account-idx.txt';
    if (is_file($file)) {
        $idx = (int)file_get_contents($file);
        $accounts = load_accounts();
        if ($idx >= 0 && $idx < count($accounts)) {
            return $idx;
        }
    }
    return 0;
}

// Set the current account index
function set_account_idx(int $idx): void {
    $accounts = load_accounts();
    if ($idx >= count($accounts)) {
        $idx = 0;
    }
    @file_put_contents(__DIR__ . '/scratch-session-account-idx.txt', (string)$idx);
}

// Increment the account cycle counter
function increment_cycle_counter(): int {
    $file = __DIR__ . '/scratch-session-cycle-count.txt';
    $count = is_file($file) ? (int)file_get_contents($file) : 0;
    $count++;
    @file_put_contents($file, (string)$count);
    return $count;
}

// Get and reset the switch counter for email reports
function get_switch_counter(): int {
    $file = __DIR__ . '/scratch-session-switch-count.txt';
    $count = is_file($file) ? (int)file_get_contents($file) : 0;
    return $count;
}

function increment_switch_counter(): int {
    $file = __DIR__ . '/scratch-session-switch-count.txt';
    $count = is_file($file) ? (int)file_get_contents($file) : 0;
    $count++;
    @file_put_contents($file, (string)$count);
    return $count;
}

function reset_switch_counter(): void {
    @file_put_contents(__DIR__ . '/scratch-session-switch-count.txt', '0');
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

// Send email report of account switches and activity
function send_activity_report(string $email, array $recent_lines, int $switch_count): void {
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    
    $activity = implode("\n", $recent_lines);
    $subject = "ScratchCensus: Account switch #$switch_count completed";
    $body = "Account switched for the {$switch_count}th time.\n\n";
    $body .= "Recent activity (last " . count($recent_lines) . " lines):\n\n";
    $body .= $activity;
    
    $headers = "From: noreply@scratchcensus.local\n";
    $headers .= "Content-Type: text/plain; charset=utf-8\n";
    
    @mail($email, $subject, $body, $headers);
}

function studio_request(string $method, int $studio, int $project, array $auth, string $jar): array {
    return scratch_http($method, "https://api.scratch.mit.edu/studios/$studio/project/$project", $jar, [
        'X-Token: ' . $auth['token'],
        'X-CSRFToken: ' . (csrf_from_jar($jar) ?: $auth['csrf']),
        'X-Requested-With: XMLHttpRequest',
        'Origin: https://scratch.mit.edu',
    ]);
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
const COOLDOWN_FILE = __DIR__ . '/scratch-session-cooldown.txt';
const COOLDOWN_DEFAULT_SEC = 1800; // wait this long after a 429 when Scratch does not say how long

// After a 429 the queue action waits: Scratch's Retry-After if it sent one (60 s to 24 h), else the default above.
function cooldown_start(string $headers): int {
    $sec = COOLDOWN_DEFAULT_SEC;
    if (preg_match('/^Retry-After:\s*(\d+)/mi', $headers, $m)) {
        $sec = max(60, min(86400, (int)$m[1]));
    }
    @file_put_contents(COOLDOWN_FILE, (string)(time() + $sec));
    return $sec;
}

// Seconds the queue action still has to wait (0 = go ahead).
function cooldown_left(): int {
    clearstatcache(true, COOLDOWN_FILE);
    $until = is_file(COOLDOWN_FILE) ? (int)@file_get_contents(COOLDOWN_FILE) : 0;
    return max(0, $until - time());
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
    $studios = array_values(array_unique(array_filter(
        array_map('intval', preg_split('/[\s,;]+/', (string)($_GET['studio'] ?? ''), -1, PREG_SPLIT_NO_EMPTY)),
        fn($n) => $n > 0
    )));
    $project = (int)($_GET['project'] ?? 0);
    $action  = (string)($_GET['action'] ?? 'add');
    $method  = ($action === 'remove' || $action === 'removeall') ? 'DELETE' : 'POST';
    $tag     = ($method === 'DELETE') ? 'DEL' : 'ADD';

    if ($action === 'removeall' && $project > 0) {
        $studios = logged_studios($project);
        if (!$studios) {
            echo "Nothing left to remove for project $project according to the log.\n";
            exit;
        }
        echo count($studios) . " studios still logged for project $project.\n";
    }

    if ($action === 'queue' && $project > 0) {
        $left = cooldown_left();
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

    $todo = array_slice($studios, 0, MAX_PER_RUN);
    $rest = array_slice($studios, MAX_PER_RUN);

    // Handle account rotation in queue mode
    $currentUser = SCRATCH_USER;
    $currentPass = SCRATCH_PASS;
    
    if ($action === 'queue') {
        $accounts = load_accounts();
        if (!empty($accounts)) {
            // Increment cycle counter
            $cycle = increment_cycle_counter();
            $accountIdx = get_current_account_idx();
            
            // Switch account every ACCOUNT_CYCLE_RUNS
            if ($cycle >= ACCOUNT_CYCLE_RUNS) {
                $accountIdx++;
                if ($accountIdx >= count($accounts)) {
                    $accountIdx = 0;
                }
                set_account_idx($accountIdx);
                $switchCount = increment_switch_counter();
                out("ACCOUNT_SWITCH: Switched to account " . ($accountIdx + 1) . " of " . count($accounts) . " (switch #$switchCount)\n");
                
                // Send email report every EMAIL_EVERY_SWITCHES
                if ($switchCount % EMAIL_EVERY_SWITCHES === 0) {
                    $activity = get_activity_since(100);
                    send_activity_report(EMAIL_REPORT_TO, $activity, $switchCount);
                    out("Sent activity report email (switch #$switchCount)\n");
                }
                
                @file_put_contents(__DIR__ . '/scratch-session-cycle-count.txt', '0');
            }
            
            if (isset($accounts[$accountIdx])) {
                $currentUser = $accounts[$accountIdx]['user'];
                $currentPass = $accounts[$accountIdx]['pass'];
            }
        }
    }
    
    $base = __DIR__ . '/scratch-session-' . md5(CRON_SECRET . $currentUser);
    $jar = $base . '.cookies';
    $stateFile = $base . '.json';

    $auth = load_session($jar, $stateFile);
    $fresh = false;
    if (!$auth) {
        $auth = scratch_login($currentUser, $currentPass, $jar, $stateFile);
        $fresh = true;
    }

    $ok = 0;
    $tried = 0;
    $lastStart = 0.0;
    foreach ($todo as $n => $studio) {
        // PAUSE_SEC is the gap between request starts, so time spent waiting on Scratch counts towards it
        if ($n > 0) {
            $gap = PAUSE_SEC - (microtime(true) - $lastStart);
            if ($gap > 0) {
                usleep((int)round($gap * 1000000));
            }
        }
        $lastStart = microtime(true);
        $tried++;
        [$code, $hdrs, $body] = studio_request($method, $studio, $project, $auth, $jar);

        // A saved session can go stale: log in again once and retry.
        // DELETE rotates the CSRF token: retry once with the fresh one from the jar.
        if ($code === 419) {
            [$code, $hdrs, $body] = studio_request($method, $studio, $project, $auth, $jar);
        }

        if (!$fresh && in_array($code, [401, 403, 419], true)) {
            $auth = scratch_login($currentUser, $currentPass, $jar, $stateFile);
            $fresh = true;
            [$code, $hdrs, $body] = studio_request($method, $studio, $project, $auth, $jar);
        }

        out("$tag $project $studio: HTTP $code " . substr(trim(preg_replace('/\s+/', ' ', $body)), 0, 150) . "\n");
        if ($code >= 200 && $code < 300) {
            $ok++;
        }
        if ($action === 'queue') {
            if ($code > 0 && $code < 500 && $code !== 429) {
                $pendDone[] = $studio;
            } elseif ($code >= 500) {
                $pendEnd[] = $studio;
            }
            if ($code >= 200 && $code < 300) {
                @unlink(COOLDOWN_FILE); // adds work again, so any earlier wait is over
            }
        }

        // Rate limited: stop and hand back everything not yet tried.
        if ($code === 429) {
            $rest = array_merge(array_slice($todo, $n + 1), $rest);
            if ($action === 'queue') {
                $wait = cooldown_start((string)$hdrs);
                out('Stopped: rate limited. Queue runs wait ' . (int)ceil($wait / 60) . " minutes (add &force=1 to run anyway).\n");
            } else {
                out("Stopped: rate limited. Wait a while before continuing.\n");
            }
            break;
        }
    }

    queue_flush();
    $secs = microtime(true) - $T0;
    out("\nDone: $ok ok of $tried tried in " . number_format($secs, 2) . 's (' . number_format($secs > 0 ? $tried / $secs : 0, 2) . " studios/s).\n");
    if ($action === 'queue') {
        out('Left in queue: ' . count(queue_ids()) . "\n");
    } elseif ($rest) {
        out('Remaining (' . count($rest) . '): ' . implode(',', $rest) . "\n");
    }
} catch (Throwable $e) {
    echo 'Error: ', $e->getMessage(), "\n";
}