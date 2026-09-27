<?php
require_once __DIR__ . '/config.php';

function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

// Polite, identifiable User-Agent and a short timeout so a slow/hanging
// request can't eat the whole cron batch.
function httpGet(string $url): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => 'ScratchCensus/0.1 (+https://scratchnews.net/s/census - contact via ScratchNews)',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $httpCode !== 200) return null;
    return $body;
}

// Scratch has no official follower-count endpoint. The followers page's HTML
// includes "Followers (<N>)" in the tab heading - this is one request
// regardless of how many followers the user has, unlike paging through the
// official API 20-at-a-time. Same trick scratchattach's follower_count() uses.
function fetchFollowerCount(string $username): ?int {
    $html = httpGet('https://scratch.mit.edu/users/' . rawurlencode($username) . '/followers/');
    if ($html === null) return null;
    if (preg_match('/Followers\s*\((\d+)\)/i', $html, $m)) {
        return (int)$m[1];
    }
    return null;
}

// Discovery only, not a full crawl - pulls up to 2 pages (40 people) of a
// user's followers via the official API to find new usernames to queue.
// Deliberately shallow: the goal is finding people ScratchViews's ~8,643-user
// cutoff misses, not enumerating every follower of every popular account.
function discoverFollowerUsernames(string $username): array {
    $found = [];
    for ($offset = 0; $offset < 40; $offset += 20) {
        $url = 'https://api.scratch.mit.edu/users/' . rawurlencode($username)
            . '/followers?limit=20&offset=' . $offset;
        $json = httpGet($url);
        if ($json === null) break;
        $data = json_decode($json, true);
        if (!is_array($data) || count($data) === 0) break;
        foreach ($data as $u) {
            if (!empty($u['username'])) $found[] = $u['username'];
        }
        if (count($data) < 20) break; // last page
    }
    return $found;
}

function queueUsername(string $username, ?string $discoveredFrom = null): void {
    $db = getDB();
    $stmt = $db->prepare("INSERT IGNORE INTO scratchers (username, discovered_from) VALUES (?, ?)");
    $stmt->bind_param('ss', $username, $discoveredFrom);
    $stmt->execute();
    $stmt->close();
}

// Processes up to $limit pending scratchers: fetches their follower count,
// queues any newly-discovered usernames from their followers list, marks
// them fetched (or error, if Scratch didn't return a usable page - deleted/
// banned accounts mainly). Returns how many were processed this run.
function crawlBatch(int $limit): int {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username FROM scratchers WHERE status = 'pending' ORDER BY id ASC LIMIT ?");
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $processed = 0;
    foreach ($rows as $row) {
        $username = $row['username'];
        $count = fetchFollowerCount($username);

        if ($count === null) {
            $upd = $db->prepare("UPDATE scratchers SET status = 'error', checked_at = NOW() WHERE id = ?");
            $upd->bind_param('i', $row['id']);
            $upd->execute();
            $upd->close();
        } else {
            $upd = $db->prepare("UPDATE scratchers SET follower_count = ?, status = 'fetched', checked_at = NOW() WHERE id = ?");
            $upd->bind_param('ii', $count, $row['id']);
            $upd->execute();
            $upd->close();

            usleep(300000); // be polite between the two requests per user
            foreach (discoverFollowerUsernames($username) as $found) {
                queueUsername($found, $username);
            }
        }

        $processed++;
        usleep(300000); // be polite between users too
    }
    return $processed;
}

function getScratcherCount(): int {
    $db = getDB();
    $result = $db->query("SELECT COUNT(*) AS c FROM scratchers WHERE status = 'fetched'");
    return (int)$result->fetch_assoc()['c'];
}

function getScratchersPage(int $page, int $perPage = 100): array {
    $db = getDB();
    $offset = ($page - 1) * $perPage;
    $stmt = $db->prepare("SELECT username, follower_count, checked_at FROM scratchers WHERE status = 'fetched' ORDER BY follower_count DESC, username ASC LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $perPage, $offset);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}