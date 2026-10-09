<?php
// Adds a project to a Scratch studio using the account in config.php.
// Usage: script.php?key=CRON_SECRET&studio=12345678&project=987654321

require_once __DIR__ . '/config.php';

header('Content-Type: text/plain; charset=utf-8');

if (($_GET['key'] ?? '') !== CRON_SECRET) {
    http_response_code(404);
    exit;
}

const SCRATCH_UA = 'Mozilla/5.0 (compatible; ScratchCensus/1.0)';

function scratch_login(string $user, string $pass, string $jar): array {
    // Step 1: get a CSRF cookie
    $ch = curl_init('https://scratch.mit.edu/csrf_token/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_USERAGENT => SCRATCH_UA,
        CURLOPT_HTTPHEADER => ['Referer: https://scratch.mit.edu'],
        CURLOPT_TIMEOUT => 20,
    ]);
    curl_exec($ch);
    $csrfCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $csrf = null;
    if (is_file($jar)) {
        foreach (file($jar) as $line) {
            if (strpos($line, 'scratchcsrftoken') !== false) {
                $parts = preg_split('/\s+/', trim($line));
                $csrf = end($parts);
            }
        }
    }
    if (!$csrf) {
        throw new Exception("No CSRF token (HTTP $csrfCode). Scratch may be blocking this server.");
    }

    // Step 2: log in
    $ch = curl_init('https://scratch.mit.edu/login/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_COOKIEJAR => $jar,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_USERAGENT => SCRATCH_UA,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_POSTFIELDS => json_encode([
            'username' => $user,
            'password' => $pass,
            'useMessages' => true,
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-CSRFToken: ' . $csrf,
            'X-Requested-With: XMLHttpRequest',
            'Referer: https://scratch.mit.edu',
        ],
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    $res = json_decode((string)$raw, true);
    if (empty($res[0]['token'])) {
        $snippet = substr(trim(strip_tags((string)$raw)), 0, 200);
        throw new Exception("Login failed (HTTP $code). " . ($curlErr ? "cURL: $curlErr. " : '') . "Response: $snippet");
    }
    return ['token' => $res[0]['token'], 'csrf' => $csrf];
}

function studio_project_request(string $method, int $studioId, int $projectId, array $auth, string $jar): array {
    $ch = curl_init("https://api.scratch.mit.edu/studios/$studioId/project/$projectId");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIEFILE => $jar,
        CURLOPT_USERAGENT => SCRATCH_UA,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'X-Token: ' . $auth['token'],
            'X-CSRFToken: ' . $auth['csrf'],
            'Origin: https://scratch.mit.edu',
            'Referer: https://scratch.mit.edu/',
            'Content-Length: 0',
        ],
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, $body];
}

try {
    $studio  = (int)($_GET['studio'] ?? 0);
    $project = (int)($_GET['project'] ?? 0);
    $method  = (($_GET['action'] ?? 'add') === 'remove') ? 'DELETE' : 'PUT';

    if ($studio <= 0 || $project <= 0) {
        throw new Exception('Missing studio or project parameter.');
    }

    $jar = sys_get_temp_dir() . '/sc_scratch_' . md5(SCRATCH_USER) . '.txt';
    $auth = scratch_login(SCRATCH_USER, SCRATCH_PASS, $jar);
    [$code, $body] = studio_project_request($method, $studio, $project, $auth, $jar);
    @unlink($jar);

    echo "HTTP $code\n$body\n";
} catch (Throwable $e) {
    echo 'Error: ', $e->getMessage(), "\n";
}