<?php
// Candidate list: open studios whose title matches any of your keywords, biggest first.
// Key-gated (same CRON_SECRET as the other private pages).
//
//   /s/census/candidates.php?key=SECRET
//   /s/census/candidates.php?key=SECRET&q=add,popular,viral,#&n=50&page=1&min=0
//
// q    comma separated keywords. A plain word (3+ letters) matches the START of a word in the title,
//      using the FULLTEXT index. Anything else ("#", "1000 projects") is matched as plain text anywhere
//      in the title (every space separated part must appear).
// n    studios per page (default 50, at most 100)
// page which page of the combined list (1 = biggest studios). The page count is shown under the form.
// min  only studios with at least this many followers
//
// words=1  (the "Top words" button) skips the search and instead counts the most common words and
//      two-word phrases in the titles of the biggest open studios. top=N is how many studios to scan
//      (default 20000, at most 50000). Every word links back to a search for it.
//
// Speed: the keyword searches are merged into at most two queries (one FULLTEXT, one LIKE with OR). They return
// the ids of every match, biggest first, and that id list is cached in the temp dir for 30 minutes (Top words:
// 1 hour), so paging, changing n and the queue button are instant. Add &fresh=1 to skip the cache. The line under
// the form shows how long the FULLTEXT and the LIKE query took (or how old the cached copy is).
// Each query stops at CANDIDATE_MAX_MATCHES matches as a safety net; a list that hits it shows "100,000+".
//
// warm=CRON_SECRET (used by cron/warm-cache.php, no key needed) rebuilds the cached list for the default
// keywords once it is older than 25 minutes, so the first visit is instant too.
//
// This only lists studios. Adding a project is still done one studio at a time with script.php.
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/studios-functions.php';

$warming = (string)($_GET['warm'] ?? '') !== '' && hash_equals((string)CRON_SECRET, (string)$_GET['warm']);
if (!$warming && !hash_equals((string)CRON_SECRET, (string)($_GET['key'] ?? ''))) {
    http_response_code(404);
    exit;
}

const CANDIDATE_DEFAULT_KEYWORDS = 'add, popular, projects, viral, games, follow, friends, #';
const CANDIDATE_MAX_MATCHES = 100000; // safety cap per query (FULLTEXT and LIKE each), keeps memory and time bounded
const CANDIDATE_LIST_TTL_SEC = 1800; // how long a cached list is used
const CANDIDATE_WARM_AGE_SEC = 1500; // the cron rebuilds the default list once its copy is older than this

// "add, #, 1000 projects" -> ['ft' => ['add'], 'like' => [['#'], ['1000', 'projects']]]
function candidateTerms(string $q): array {
    $ft = [];
    $like = [];
    foreach (explode(',', $q) as $item) {
        $item = trim(str_replace('*', '', $item));
        if ($item === '') continue;
        $parts = preg_split('/\s+/u', $item, -1, PREG_SPLIT_NO_EMPTY);
        if (count($parts) === 1 && preg_match('/^[\p{L}\p{N}]{3,40}$/u', $parts[0])
            && !in_array(mb_strtolower($parts[0]), STUDIO_FT_STOPWORDS, true)) {
            $ft[] = $parts[0];
        } else {
            $like[] = array_slice($parts, 0, 4);
        }
        if (count($ft) + count($like) >= 12) break;
    }
    return ['ft' => array_values(array_unique($ft)), 'like' => $like];
}

function candidateCacheFile(string $key): string {
    return sys_get_temp_dir() . '/candidates-' . md5(__DIR__ . '|' . $key) . '.json';
}
function candidateCacheRead(string $key, int $ttl): ?array {
    $f = candidateCacheFile($key);
    if (!empty($_GET['fresh']) || !is_file($f) || time() - (int)filemtime($f) > $ttl) return null;
    $d = json_decode((string)@file_get_contents($f), true);
    return is_array($d) ? $d : null;
}
function candidateCacheWrite(string $key, array $data): void {
    $f = candidateCacheFile($key);
    $tmp = $f . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE)) !== false) @rename($tmp, $f);
}
function candidateCacheAge(string $key): ?int {
    $f = candidateCacheFile($key);
    clearstatcache(true, $f);
    return is_file($f) ? max(0, time() - (int)filemtime($f)) : null;
}
function candidateListKey(string $q, int $min): string {
    return 'list2|' . mb_strtolower($q) . "|$min";
}

function candidateRows(string $sql, string $types, array $params, int $mode = MYSQLI_ASSOC): array {
    $stmt = getDB()->prepare($sql);
    if (!$stmt) throw new RuntimeException('query could not be prepared');
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all($mode);
    $stmt->close();
    return $rows;
}

// Ids of every studio matching these terms, biggest first: ['ids' => [...], 'capped' => bool]. $timing gets the
// seconds the FULLTEXT query and the LIKE query took (null when that query was not needed). Each query stops at
// CANDIDATE_MAX_MATCHES; when one does, the list is cut where that query's coverage ends so it stays exact.
function candidateBuildList(array &$terms, int $min, array &$timing): array {
    $base = "SELECT id, follower_count FROM studios WHERE status = 'fetched' AND open_to_all = 1 AND follower_count >= ?";
    // follower_count DESC, id DESC can be read straight off the (status, open_to_all, follower_count, id)
    // index with no sort, so a rare keyword stops scanning as soon as it has enough matches.
    $tail = ' ORDER BY follower_count DESC, id DESC LIMIT ?';
    $cap = CANDIDATE_MAX_MATCHES;
    $all = [];  // id => follower_count
    $cuts = []; // [follower_count, id] of the last row of every query that hit the cap
    $timing = ['ft' => null, 'like' => null];
    $run = function (string $sql, string $types, array $params) use (&$all, &$cuts, $cap): void {
        $rows = candidateRows($sql, $types, $params, MYSQLI_NUM);
        foreach ($rows as $r) $all[(int)$r[0]] = (int)$r[1];
        if (count($rows) >= $cap) {
            $last = end($rows);
            $cuts[] = [(int)$last[1], (int)$last[0]];
        }
    };
    if ($terms['ft']) {
        $t = microtime(true);
        $bool = implode(' ', array_map(fn($w) => $w . '*', $terms['ft'])); // no "+": any of the words
        try {
            $run("$base AND MATCH(title) AGAINST (? IN BOOLEAN MODE)$tail", 'isi', [$min, $bool, $cap]);
        } catch (\Throwable $e) {
            // no FULLTEXT index: fall back to plain text matching for these words
            foreach ($terms['ft'] as $w) $terms['like'][] = [$w];
        }
        $timing['ft'] = microtime(true) - $t;
    }
    if ($terms['like']) {
        $t = microtime(true);
        // one query for all LIKE groups: (a AND b) OR (c) OR ..., read off the index in order
        $sql = $base;
        $types = 'i';
        $params = [$min];
        $ors = [];
        foreach ($terms['like'] as $parts) {
            $ands = [];
            foreach ($parts as $part) {
                $ands[] = 'title LIKE ?';
                $types .= 's';
                $params[] = '%' . likeEscape($part) . '%';
            }
            $ors[] = '(' . implode(' AND ', $ands) . ')';
        }
        $sql .= ' AND (' . implode(' OR ', $ors) . ')';
        $types .= 'i';
        $params[] = $cap;
        $run($sql . $tail, $types, $params);
        $timing['like'] = microtime(true) - $t;
    }
    if ($cuts) {
        // below the shallowest cut the other query may have matches we never fetched, so drop everything under it
        $cut = [PHP_INT_MIN, PHP_INT_MIN];
        foreach ($cuts as $c) if ($c > $cut) $cut = $c;
        foreach ($all as $id => $f) if ([$f, $id] < $cut) unset($all[$id]);
    }
    $ids = array_keys($all);
    $fols = array_values($all);
    array_multisort($fols, SORT_DESC, SORT_NUMERIC, $ids, SORT_DESC, SORT_NUMERIC);
    return ['ids' => $ids, 'capped' => (bool)$cuts];
}

// Rows (id, title, followers, projects) for one page of ids, in the order given.
function candidatePageRows(array $ids): array {
    if (!$ids) return [];
    $rows = candidateRows('SELECT id, title, follower_count, project_count FROM studios WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
        str_repeat('i', count($ids)), array_map('intval', $ids));
    $by = [];
    foreach ($rows as $r) $by[(int)$r['id']] = $r;
    $out = [];
    foreach ($ids as $id) if (isset($by[(int)$id])) $out[] = $by[(int)$id];
    return $out;
}

// Most common words and two-word phrases in the titles of the top open studios by followers.
function candidateWordStats(int $top, int $min): array {
    $rows = candidateRows(
        "SELECT title, follower_count FROM studios WHERE status = 'fetched' AND open_to_all = 1 AND follower_count >= ?"
        . ' ORDER BY follower_count DESC, id DESC LIMIT ?', 'ii', [$min, $top]);
    $skip = array_flip(array_merge(STUDIO_FT_STOPWORDS, ['studio', 'studios', 'the', 'and', 'for', 'you', 'your', 'are', 'with', 'this', 'that', 'all', 'any', 'can', 'our', 'not']));
    $words = [];
    $phrases = [];
    foreach ($rows as $r) {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string)$r['title']), -1, PREG_SPLIT_NO_EMPTY);
        $f = (int)$r['follower_count'];
        $seen = [];
        $prev = null;
        foreach ($tokens as $t) {
            $ok = mb_strlen($t) >= 3 && !isset($skip[$t]) && !ctype_digit($t);
            if ($ok) {
                $seen['w' . $t] = true;
                if ($prev !== null) $seen['p' . $prev . ' ' . $t] = true;
            }
            $prev = $ok ? $t : null;
        }
        foreach ($seen as $k => $_) {
            $k = (string)$k;
            $word = substr($k, 1);
            if ($k[0] === 'w') {
                $words[$word] = ($words[$word] ?? [0, 0]);
                $words[$word][0]++;
                $words[$word][1] += $f;
            } else {
                $phrases[$word] = ($phrases[$word] ?? [0, 0]);
                $phrases[$word][0]++;
                $phrases[$word][1] += $f;
            }
        }
    }
    $sort = function (array $a): array {
        uasort($a, fn($x, $y) => [$y[0], $y[1]] <=> [$x[0], $x[1]]);
        return $a;
    };
    $phrases = array_filter($phrases, fn($v) => $v[0] >= 3);
    return [array_slice($sort($words), 0, 60, true), array_slice($sort($phrases), 0, 40, true), count($rows)];
}

// Cron pre-warm (cron/warm-cache.php): keeps the default keyword list cached so a visit never waits for it.
if ($warming) {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    ignore_user_abort(true);
    @set_time_limit(180);
    $wLock = @fopen(sys_get_temp_dir() . '/candidates-warm-' . md5(__DIR__) . '.lock', 'c');
    if (!$wLock || !flock($wLock, LOCK_EX | LOCK_NB)) {
        header('X-ScratchCensus-Cache: busy');
        echo 'busy';
        exit;
    }
    try {
        $wKey = candidateListKey(CANDIDATE_DEFAULT_KEYWORDS, 0);
        $wCached = candidateCacheRead($wKey, CANDIDATE_LIST_TTL_SEC);
        $wAge = candidateCacheAge($wKey);
        if ($wCached && isset($wCached['ids']) && $wAge !== null && $wAge < CANDIDATE_WARM_AGE_SEC) {
            header('X-ScratchCensus-Cache: fresh');
            echo 'fresh';
            exit;
        }
        $wTerms = candidateTerms(CANDIDATE_DEFAULT_KEYWORDS);
        $wTiming = [];
        candidateCacheWrite($wKey, candidateBuildList($wTerms, 0, $wTiming));
        echo 'warmed';
    } catch (\Throwable $e) {
        http_response_code(500);
        echo 'error: ' . $e->getMessage();
    }
    exit;
}

$wordsMode = !empty($_GET['words']);
$topN = max(1000, min(50000, (int)($_GET['top'] ?? 20000)));

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') $q = CANDIDATE_DEFAULT_KEYWORDS;
$n = max(1, min(100, (int)($_GET['n'] ?? 50)));
$page = max(1, (int)($_GET['page'] ?? 1));
$min = max(0, (int)($_GET['min'] ?? 0));
$offset = ($page - 1) * $n;

$terms = candidateTerms($q);

$list = ['ids' => [], 'capped' => false];
$rows = [];
$error = '';
$fromCache = false;
$cacheAge = null;
$timing = ['ft' => null, 'like' => null];
$t0 = microtime(true);
try {
    if ($wordsMode) {
        $wk = "words|$topN|$min";
        $c = candidateCacheRead($wk, 3600);
        if ($c && isset($c['w'], $c['p'], $c['n'])) {
            [$topWords, $topPhrases, $scanned] = [$c['w'], $c['p'], $c['n']];
            $fromCache = true;
        } else {
            [$topWords, $topPhrases, $scanned] = candidateWordStats($topN, $min);
            candidateCacheWrite($wk, ['w' => $topWords, 'p' => $topPhrases, 'n' => $scanned]);
        }
    } else {
        @set_time_limit(120);
        $lk = candidateListKey($q, $min);
        $c = candidateCacheRead($lk, CANDIDATE_LIST_TTL_SEC);
        if ($c && isset($c['ids']) && is_array($c['ids'])) {
            $list = $c;
            $fromCache = true;
            $cacheAge = candidateCacheAge($lk);
        } else {
            $list = candidateBuildList($terms, $min, $timing);
            candidateCacheWrite($lk, $list);
        }
        $rows = candidatePageRows(array_slice($list['ids'], $offset, $n));
    }
} catch (\Throwable $e) {
    $error = $e->getMessage();
}
$took = microtime(true) - $t0;

$total = count($list['ids']);
$capped = !empty($list['capped']);
$pages = max(1, (int)ceil($total / $n));

// Queue: the "Add this page to queue" button appends this page's IDs to scratch-session-queue.txt
// (one ID per line, no duplicates). script.php?action=queue takes them from the top and removes them.
const CANDIDATE_QUEUE_FILE = __DIR__ . '/scratch-session-queue.txt';
function candidateQueueIds(): array {
    $lines = is_file(CANDIDATE_QUEUE_FILE) ? file(CANDIDATE_QUEUE_FILE, FILE_IGNORE_NEW_LINES) : [];
    return array_values(array_unique(array_filter(array_map('intval', $lines), fn($v) => $v > 0)));
}
$queueMsg = '';
if (!$wordsMode && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['queue']) && $rows) {
    $fh = fopen(CANDIDATE_QUEUE_FILE, 'c+');
    if ($fh && flock($fh, LOCK_EX)) {
        $have = [];
        while (($l = fgets($fh)) !== false) {
            if ((int)$l > 0) $have[(int)$l] = true;
        }
        $new = 0;
        $out = '';
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            if (!isset($have[$id])) { $have[$id] = true; $out .= $id . "\n"; $new++; }
        }
        fseek($fh, 0, SEEK_END);
        if ($out !== '' && ftell($fh) > 0) {
            // make sure the last existing line ended with a newline
            fseek($fh, -1, SEEK_END);
            if (fgetc($fh) !== "\n") $out = "\n" . $out;
            fseek($fh, 0, SEEK_END);
        }
        fwrite($fh, $out);
        flock($fh, LOCK_UN);
        fclose($fh);
        $queueMsg = "Queued $new new IDs (" . (count($rows) - $new) . ' were already queued). ';
    } else {
        $queueMsg = 'Could not write the queue file. ';
    }
}

// which keywords each title contains
$labels = array_merge($terms['ft'], array_map(fn($p) => implode(' ', $p), $terms['like']));
function candidateMatched(string $title, array $labels): string {
    $hit = [];
    foreach ($labels as $l) {
        $ok = true;
        foreach (preg_split('/\s+/u', $l) as $part) if (stripos($title, $part) === false) { $ok = false; break; }
        if ($ok) $hit[] = $l;
    }
    return implode(', ', $hit);
}

$note = '';
if ($fromCache) {
    $note = $cacheAge !== null ? ' (cached, ' . ($cacheAge < 90 ? $cacheAge . 's' : round($cacheAge / 60) . 'm') . ' old)' : ' (cached)';
} elseif ($timing['ft'] !== null || $timing['like'] !== null) {
    $bits = [];
    if ($timing['ft'] !== null) $bits[] = 'FULLTEXT ' . number_format($timing['ft'], 2) . 's';
    if ($timing['like'] !== null) $bits[] = 'LIKE ' . number_format($timing['like'], 2) . 's';
    $note = ' (' . implode(', ', $bits) . ')';
}

$keyQ = rawurlencode((string)$_GET['key']);
$link = function (int $p) use ($keyQ, $q, $n, $min) {
    return '?key=' . $keyQ . '&q=' . rawurlencode($q) . '&n=' . $n . '&min=' . $min . '&page=' . $p;
};
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Studio candidates - ScratchCensus</title>
<style>
body{font:15px/1.4 system-ui,sans-serif;margin:16px;max-width:1000px}
table{border-collapse:collapse;width:100%}th,td{padding:6px 8px;border-bottom:1px solid #ddd;text-align:left;vertical-align:top}
td.n,th.n{text-align:right}.muted{color:#777}textarea{width:100%;height:70px;font-family:monospace}
input[type=text]{width:100%;max-width:520px}
</style></head><body>
<h1>Open studio candidates</h1>
<form method="get">
    <input type="hidden" name="key" value="<?= e((string)$_GET['key']) ?>">
    <p><label>Keywords, comma separated<br><input type="text" name="q" value="<?= e($q) ?>"></label></p>
    <p>Per page <input type="number" name="n" value="<?= (int)$n ?>" min="1" max="100" style="width:5em">
       Minimum followers <input type="number" name="min" value="<?= (int)$min ?>" min="0" style="width:7em">
       Page <input type="number" name="page" value="<?= (int)$page ?>" min="1" max="<?= (int)$pages ?>" style="width:5em"> of <?= number_format($pages) ?><?= $capped ? '+' : '' ?>
       <button type="submit">Search</button></p>
    <p>Scan top <input type="number" name="top" value="<?= (int)$topN ?>" min="1000" max="50000" step="1000" style="width:6em"> open studios
       <button type="submit" name="words" value="1">Top words</button></p>
</form>
<?php if ($error !== ''): ?><p><strong>Error:</strong> <?= e($error) ?></p><?php endif; ?>
<?php if ($wordsMode && !$error): ?>
<p class="muted">Scanned the top <?= number_format($scanned) ?> open studios by followers (min <?= (int)$min ?>), <?= number_format($took, 2) ?>s<?= $fromCache ? ' (cached)' : '' ?>. "Studios" is how many titles contain it. Click a word to search it.</p>
<?php $wl = function (string $w) use ($keyQ, $n, $min) { return '?key=' . $keyQ . '&q=' . rawurlencode($w) . '&n=' . $n . '&min=' . $min; }; ?>
<p><label>Top 20 words as keywords (paste into the box above)<br><textarea readonly onclick="this.select()"><?= e(implode(', ', array_slice(array_keys($topWords), 0, 20))) ?></textarea></label></p>
<table style="width:auto;display:inline-block;vertical-align:top;margin-right:24px">
<tr><th>Word</th><th class="n">Studios</th><th class="n">Followers</th></tr>
<?php foreach ($topWords as $w => $v): ?>
<tr><td><a href="<?= e($wl((string)$w)) ?>"><?= e((string)$w) ?></a></td><td class="n"><?= number_format($v[0]) ?></td><td class="n"><?= number_format($v[1]) ?></td></tr>
<?php endforeach; ?>
</table>
<table style="width:auto;display:inline-block;vertical-align:top">
<tr><th>Phrase</th><th class="n">Studios</th><th class="n">Followers</th></tr>
<?php foreach ($topPhrases as $w => $v): ?>
<tr><td><a href="<?= e($wl((string)$w)) ?>"><?= e((string)$w) ?></a></td><td class="n"><?= number_format($v[0]) ?></td><td class="n"><?= number_format($v[1]) ?></td></tr>
<?php endforeach; ?>
</table>
</body></html>
<?php exit; endif; ?>
<p class="muted"><?= number_format($total) ?><?= $capped ? '+' : '' ?> studios found on <?= number_format($pages) ?><?= $capped ? '+' : '' ?> pages of <?= (int)$n ?>, <?= number_format($took, 2) ?>s<?= e($note) ?>. <a href="<?= e($link((int)$page) . '&fresh=1') ?>">Refresh</a><br>
Open studios only, biggest first<?= $capped ? '. A query hit the ' . number_format(CANDIDATE_MAX_MATCHES) . ' match limit, so the smallest studios are left out' : '' ?>. Add to a handful at a time through script.php.</p>
<?php if ($rows): ?>
<?php $idList = array_map(fn($r) => (int)$r['id'], $rows); ?>
<p><label>IDs for script.php (studio=...)<br><textarea readonly onclick="this.select()"><?= e(implode(',', $idList)) ?></textarea></label></p>
<form method="post" action="<?= e($link((int)$page)) ?>" style="margin:0 0 12px">
    <button type="submit" name="queue" value="1">Add this page to queue</button>
    <span class="muted"><?= e($queueMsg) ?><?= number_format(count(candidateQueueIds())) ?> IDs waiting. Run script.php?key=...&amp;project=...&amp;action=queue</span>
</form>
<p><label>PHP array<br><textarea readonly onclick="this.select()">[<?= e(implode(', ', $idList)) ?>]</textarea></label></p>
<table>
<tr><th class="n">#</th><th>Studio</th><th class="n">Followers</th><th class="n">Projects</th><th>Matched</th></tr>
<?php foreach ($rows as $i => $r): $title = (string)$r['title'] !== '' ? (string)$r['title'] : 'Studio ' . $r['id']; ?>
<tr>
    <td class="n"><?= $offset + $i + 1 ?></td>
    <td><a href="https://scratch.mit.edu/studios/<?= (int)$r['id'] ?>/" target="_blank" rel="noopener"><?= e(shortTitle($title)) ?></a> <span class="muted">#<?= (int)$r['id'] ?></span></td>
    <td class="n"><?= number_format((int)$r['follower_count']) ?></td>
    <td class="n"><?= number_format((int)$r['project_count']) ?></td>
    <td class="muted"><?= e(candidateMatched($title, $labels)) ?></td>
</tr>
<?php endforeach; ?>
</table>
<p>
<?php if ($page > 1): ?><a href="<?= e($link(1)) ?>">&laquo; First</a> <a href="<?= e($link(min($page - 1, $pages))) ?>">&larr; Previous</a> <?php endif; ?>
Page <?= number_format($page) ?> of <?= number_format($pages) ?><?= $capped ? '+' : '' ?>
<?php if ($page < $pages): ?><a href="<?= e($link($page + 1)) ?>">Next &rarr;</a> <a href="<?= e($link($pages)) ?>">Last &raquo;</a><?php endif; ?>
</p>
<?php elseif ($error === '' && $total > 0): ?>
<p>Page <?= number_format($page) ?> is past the end. <a href="<?= e($link($pages)) ?>">Go to the last page (<?= number_format($pages) ?>)</a></p>
<?php elseif ($error === ''): ?>
<p>No open studios matched.</p>
<?php endif; ?>
</body></html>