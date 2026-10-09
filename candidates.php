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
// page which page of the combined list (1 = biggest studios)
// min  only studios with at least this many followers
//
// This only lists studios. Adding a project is still done one studio at a time with script.php.
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/studios-functions.php';

if (!hash_equals((string)CRON_SECRET, (string)($_GET['key'] ?? ''))) {
    http_response_code(404);
    exit;
}

const CANDIDATE_DEFAULT_KEYWORDS = 'add, popular, projects, viral, games, follow, friends, #';
const CANDIDATE_MAX_DEPTH = 1000; // offset + n never goes past this, per keyword group

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

function candidateRows(string $sql, string $types, array $params): array {
    $stmt = getDB()->prepare($sql);
    if (!$stmt) throw new RuntimeException('query could not be prepared');
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') $q = CANDIDATE_DEFAULT_KEYWORDS;
$n = max(1, min(100, (int)($_GET['n'] ?? 50)));
$page = max(1, (int)($_GET['page'] ?? 1));
$min = max(0, (int)($_GET['min'] ?? 0));
$offset = ($page - 1) * $n;
$depth = min(CANDIDATE_MAX_DEPTH, $offset + $n);

$terms = candidateTerms($q);
$cols = 'id, title, follower_count, project_count';
// follower_count DESC, id DESC can be read straight off the (status, open_to_all, follower_count, id)
// index with no sort, so a rare keyword stops scanning as soon as it has enough matches.
$tail = ' ORDER BY follower_count DESC, id DESC LIMIT ?';
$base = "SELECT $cols FROM studios WHERE status = 'fetched' AND open_to_all = 1 AND follower_count >= ?";

$all = [];
$error = '';
$t0 = microtime(true);
try {
    if ($terms['ft']) {
        $bool = implode(' ', array_map(fn($w) => $w . '*', $terms['ft'])); // no "+": any of the words
        try {
            foreach (candidateRows("$base AND MATCH(title) AGAINST (? IN BOOLEAN MODE)$tail", 'isi', [$min, $bool, $depth]) as $r) $all[(int)$r['id']] = $r;
        } catch (\Throwable $e) {
            // no FULLTEXT index: fall back to plain text matching for these words
            foreach ($terms['ft'] as $w) $terms['like'][] = [$w];
        }
    }
    foreach ($terms['like'] as $parts) {
        $sql = $base;
        $types = 'i';
        $params = [$min];
        foreach ($parts as $part) {
            $sql .= ' AND title LIKE ?';
            $types .= 's';
            $params[] = '%' . likeEscape($part) . '%';
        }
        $types .= 'i';
        $params[] = $depth;
        foreach (candidateRows($sql . $tail, $types, $params) as $r) $all[(int)$r['id']] = $r;
    }
} catch (\Throwable $e) {
    $error = $e->getMessage();
}
$took = microtime(true) - $t0;

uasort($all, function ($a, $b) {
    return [(int)$b['follower_count'], (int)$b['id']] <=> [(int)$a['follower_count'], (int)$a['id']];
});
$total = count($all);
$rows = array_slice(array_values($all), $offset, $n);

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
       <button type="submit">Search</button></p>
</form>
<?php if ($error !== ''): ?><p><strong>Error:</strong> <?= e($error) ?></p><?php endif; ?>
<p class="muted"><?= number_format($total) ?> studios found (up to <?= number_format($depth) ?> per keyword group), <?= number_format($took, 2) ?>s.
Open studios only, biggest first. Add to a handful at a time through script.php.</p>
<?php if ($rows): ?>
<p><label>IDs on this page<br><textarea readonly onclick="this.select()"><?= e(implode("\n", array_map(fn($r) => $r['id'], $rows))) ?></textarea></label></p>
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
<?php if ($page > 1): ?><a href="<?= e($link($page - 1)) ?>">&larr; Previous</a> <?php endif; ?>
<?php if ($offset + $n < $total): ?><a href="<?= e($link($page + 1)) ?>">Next &rarr;</a><?php endif; ?>
</p>
<?php elseif ($error === ''): ?>
<p>No open studios matched.</p>
<?php endif; ?>
</body></html>