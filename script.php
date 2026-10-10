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
// studio takes one ID or a comma/space separated list. At most MAX_PER_RUN studios per request,
// with PAUSE_SEC between them. The leftover IDs are printed so you can paste them into the next run.

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

if (($_GET['key'] ?? '') !== CRON_SECRET) {
    http_response_code(404);
    exit;
}

const SCRATCH_UA = 'Mozilla/5.0 (compatible; ScratchCensus/1.0)';
const SESSION_MAX_AGE = 43200; // reuse a login for 12 hours
const MAX_PER_RUN = 50;        // studios handled per request
const PAUSE_SEC = 0.5;           // pause between studios

// One HTTP call. Returns [status code, raw headers, body].
function scratch_http(string $method, string $url, string $jar, array $headers = [], ?string $body = null): array {
    $ch = curl_init($url);
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

function queue_ids(): array {
    $lines = is_file(QUEUE_FILE) ? file(QUEUE_FILE, FILE_IGNORE_NEW_LINES) : [];
    return array_values(array_unique(array_filter(array_map('intval', $lines), fn($v) => $v > 0)));
}

// Remove one ID from the queue file (locked, so a button press during a run is not lost).
function queue_remove(int $id): void {
    $fh = @fopen(QUEUE_FILE, 'c+');
    if (!$fh || !flock($fh, LOCK_EX)) {
        return;
    }
    $keep = [];
    while (($l = fgets($fh)) !== false) {
        $v = (int)$l;
        if ($v > 0 && $v !== $id) {
            $keep[] = $v;
        }
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, $keep ? implode("\n", $keep) . "\n" : '');
    flock($fh, LOCK_UN);
    fclose($fh);
}

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
        $studios = queue_ids();
        if (!$studios) {
            echo "The queue is empty.\n";
            exit;
        }
        echo count($studios) . " IDs in the queue.\n";
    }

    if (!$studios || $project <= 0) {
        throw new Exception('Missing studio or project parameter.');
    }

    $todo = array_slice($studios, 0, MAX_PER_RUN);
    $rest = array_slice($studios, MAX_PER_RUN);

    $base = __DIR__ . '/scratch-session-' . md5(CRON_SECRET . SCRATCH_USER);
    $jar = $base . '.cookies';
    $stateFile = $base . '.json';

    $auth = load_session($jar, $stateFile);
    $fresh = false;
    if (!$auth) {
        $auth = scratch_login(SCRATCH_USER, SCRATCH_PASS, $jar, $stateFile);
        $fresh = true;
    }

    $ok = 0;
    foreach ($todo as $n => $studio) {
        if ($n > 0) {
            sleep(PAUSE_SEC);
        }
        [$code, , $body] = studio_request($method, $studio, $project, $auth, $jar);

        // A saved session can go stale: log in again once and retry.
        // DELETE rotates the CSRF token: retry once with the fresh one from the jar.
        if ($code === 419) {
            [$code, , $body] = studio_request($method, $studio, $project, $auth, $jar);
        }

        if (!$fresh && in_array($code, [401, 403, 419], true)) {
            $auth = scratch_login(SCRATCH_USER, SCRATCH_PASS, $jar, $stateFile);
            $fresh = true;
            [$code, , $body] = studio_request($method, $studio, $project, $auth, $jar);
        }

        out("$tag $project $studio: HTTP $code " . substr(trim(preg_replace('/\s+/', ' ', $body)), 0, 150) . "\n");
        if ($code >= 200 && $code < 300) {
            $ok++;
        }
        if ($action === 'queue' && $code > 0 && $code < 500 && $code !== 429) {
            queue_remove($studio);
        }

        // Rate limited: stop and hand back everything not yet tried.
        if ($code === 429) {
            $rest = array_merge(array_slice($todo, $n + 1), $rest);
            out("Stopped: rate limited. Wait a while before continuing.\n");
            break;
        }
    }

    out("\nDone: $ok ok of " . count($todo) . " tried.\n");
    if ($action === 'queue') {
        out('Left in queue: ' . count(queue_ids()) . "\n");
    } elseif ($rest) {
        out('Remaining (' . count($rest) . '): ' . implode(',', $rest) . "\n");
    }
} catch (Throwable $e) {
    echo 'Error: ', $e->getMessage(), "\n";
}
