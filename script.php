<?php
// Adds (or removes) a project in a Scratch studio using the account in config.php.
// Usage: script.php?key=CRON_SECRET&studio=12345678&project=987654321[&action=remove]

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

if (($_GET['key'] ?? '') !== CRON_SECRET) {
    http_response_code(404);
    exit;
}

const SCRATCH_UA = 'Mozilla/5.0 (compatible; ScratchCensus/1.0)';
const SESSION_MAX_AGE = 43200; // reuse a login for 12 hours

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
        'X-CSRFToken: ' . $auth['csrf'],
        'X-Requested-With: XMLHttpRequest',
        'Origin: https://scratch.mit.edu',
    ]);
}

try {
    $studio  = (int)($_GET['studio'] ?? 0);
    $project = (int)($_GET['project'] ?? 0);
    $method  = (($_GET['action'] ?? 'add') === 'remove') ? 'DELETE' : 'POST';

    if ($studio <= 0 || $project <= 0) {
        throw new Exception('Missing studio or project parameter.');
    }

    $base = __DIR__ . '/scratch-session-' . md5(CRON_SECRET . SCRATCH_USER);
    $jar = $base . '.cookies';
    $stateFile = $base . '.json';

    $auth = load_session($jar, $stateFile);
    $fresh = false;
    if (!$auth) {
        $auth = scratch_login(SCRATCH_USER, SCRATCH_PASS, $jar, $stateFile);
        $fresh = true;
    }

    [$code, , $body] = studio_request($method, $studio, $project, $auth, $jar);

    // A saved session can go stale: log in again once and retry.
    if (!$fresh && ($code === 401 || $code === 403)) {
        $auth = scratch_login(SCRATCH_USER, SCRATCH_PASS, $jar, $stateFile);
        [$code, , $body] = studio_request($method, $studio, $project, $auth, $jar);
    }

    echo "HTTP $code\n$body\n";
} catch (Throwable $e) {
    echo 'Error: ', $e->getMessage(), "\n";
}