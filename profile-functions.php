<?php
// ScratchCensus 6.2: data helpers for the Scratcher profile page (profile.php).
// History comes from the scratcher_history table, one row per user per daily refresh.

const PROFILE_HISTORY_DAYS = 90;
const PROFILE_BAR_DAYS = 14;

function getProfileUser(string $username): ?array {
    $stmt = getDB()->prepare("SELECT id, username, follower_count, checked_at, scratch_id, country,
            IF(checked_at >= DATE_SUB(NOW(), INTERVAL 2 DAY), follower_delta, NULL) AS delta
        FROM scratchers WHERE status = 'fetched' AND username = ?");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return null;
    $row['rank'] = rankOf((int)$row['follower_count'], $row['username']);
    return $row;
}

// Position inside the user's country, same order as the country leaderboard (followers DESC, username DESC).
function getCountryRank(string $country, int $followers, string $username): int {
    $stmt = getDB()->prepare("SELECT COUNT(*) AS c FROM scratchers WHERE status = 'fetched' AND country = ?
        AND (follower_count > ? OR (follower_count = ? AND username > ?))");
    $stmt->bind_param('siis', $country, $followers, $followers, $username);
    $stmt->execute();
    $c = (int)$stmt->get_result()->fetch_assoc()['c'];
    $stmt->close();
    return $c + 1;
}

// [['day' => 'YYYY-MM-DD', 'n' => followers], ...] oldest first. Empty if the table is missing.
function getUserHistory(int $scratcherId, int $days = PROFILE_HISTORY_DAYS): array {
    try {
        $stmt = getDB()->prepare("SELECT day, follower_count AS n FROM scratcher_history
            WHERE scratcher_id = ? AND day >= DATE_SUB(CURDATE(), INTERVAL ? DAY) ORDER BY day ASC");
        if (!$stmt) return [];
        $stmt->bind_param('ii', $scratcherId, $days);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        foreach ($rows as &$r) $r['n'] = (int)$r['n'];
        unset($r);
        return $rows;
    } catch (\Throwable $e) {
        return [];
    }
}

// Straight-line projection from the first to the last stored point.
// Returns null with fewer than 2 points on different days.
function profileProjection(array $hist, int $current): ?array {
    if (count($hist) < 2) return null;
    $first = $hist[0];
    $last = $hist[count($hist) - 1];
    $span = (strtotime($last['day']) - strtotime($first['day'])) / 86400;
    if ($span < 1) return null;
    $perDay = ($last['n'] - $first['n']) / $span;
    $out = ['per_day' => $perDay, 'span' => (int)$span, 'in30' => (int)round($current + $perDay * 30), 'milestone' => null, 'days' => null];
    if ($perDay > 0) {
        foreach ([100, 500, 1000, 2500, 5000, 10000, 25000, 50000, 100000, 250000, 500000, 1000000, 5000000] as $m) {
            if ($m > $current) {
                $out['milestone'] = $m;
                $out['days'] = (int)ceil(($m - $current) / $perDay);
                break;
            }
        }
    }
    return $out;
}

// Follower line chart as inline SVG (no JavaScript, styled by .prof-* classes in census.css).
function profileGraphSvg(array $hist): string {
    $n = count($hist);
    if ($n < 2) return '';
    $W = 640; $H = 220; $pl = 56; $pr = 14; $pt = 14; $pb = 28;
    $vals = array_column($hist, 'n');
    $min = min($vals); $max = max($vals);
    if ($max === $min) { $min -= 1; $max += 1; }
    $t0 = strtotime($hist[0]['day']);
    $t1 = strtotime($hist[$n - 1]['day']);
    $tspan = max(1, $t1 - $t0);
    $pts = [];
    foreach ($hist as $h) {
        $x = $pl + (strtotime($h['day']) - $t0) / $tspan * ($W - $pl - $pr);
        $y = $pt + (1 - ($h['n'] - $min) / ($max - $min)) * ($H - $pt - $pb);
        $pts[] = [round($x, 1), round($y, 1)];
    }
    $line = implode(' ', array_map(fn($p) => $p[0] . ',' . $p[1], $pts));
    $area = $pl . ',' . ($H - $pb) . ' ' . $line . ' ' . end($pts)[0] . ',' . ($H - $pb);
    $s = '<svg class="prof-graph" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Followers over time">';
    $s .= '<line class="prof-grid" x1="' . $pl . '" y1="' . $pt . '" x2="' . ($W - $pr) . '" y2="' . $pt . '"/>';
    $s .= '<line class="prof-grid" x1="' . $pl . '" y1="' . ($H - $pb) . '" x2="' . ($W - $pr) . '" y2="' . ($H - $pb) . '"/>';
    $s .= '<text class="prof-axis" x="' . ($pl - 6) . '" y="' . ($pt + 4) . '" text-anchor="end">' . number_format($max) . '</text>';
    $s .= '<text class="prof-axis" x="' . ($pl - 6) . '" y="' . ($H - $pb + 4) . '" text-anchor="end">' . number_format($min) . '</text>';
    $s .= '<text class="prof-axis" x="' . $pl . '" y="' . ($H - 8) . '">' . e($hist[0]['day']) . '</text>';
    $s .= '<text class="prof-axis" x="' . ($W - $pr) . '" y="' . ($H - 8) . '" text-anchor="end">' . e($hist[$n - 1]['day']) . '</text>';
    $s .= '<polygon class="prof-area" points="' . $area . '"/>';
    $s .= '<polyline class="prof-line" points="' . $line . '"/>';
    foreach ($hist as $i => $h) {
        $s .= '<circle class="prof-dot" cx="' . $pts[$i][0] . '" cy="' . $pts[$i][1] . '" r="3"><title>' . e($h['day']) . ': ' . number_format($h['n']) . '</title></circle>';
    }
    return $s . '</svg>';
}

// Daily change bars for the last PROFILE_BAR_DAYS points (green up, red down).
function profileBarsSvg(array $hist): string {
    $d = [];
    for ($i = 1; $i < count($hist); $i++) {
        $d[] = ['day' => $hist[$i]['day'], 'v' => $hist[$i]['n'] - $hist[$i - 1]['n']];
    }
    $d = array_slice($d, -PROFILE_BAR_DAYS);
    if (!$d) return '';
    $W = 640; $H = 120; $mid = $H / 2;
    $maxAbs = max(1, max(array_map(fn($x) => abs($x['v']), $d)));
    $slot = $W / count($d);
    $bw = max(4, $slot * 0.65);
    $s = '<svg class="prof-graph" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Daily follower change">';
    $s .= '<line class="prof-grid" x1="0" y1="' . $mid . '" x2="' . $W . '" y2="' . $mid . '"/>';
    foreach ($d as $i => $x) {
        $h = abs($x['v']) / $maxAbs * ($mid - 6);
        $h = $x['v'] === 0 ? 1 : max(2, $h);
        $bx = round($i * $slot + ($slot - $bw) / 2, 1);
        $by = $x['v'] >= 0 ? $mid - $h : $mid;
        $cls = $x['v'] > 0 ? 'prof-up' : ($x['v'] < 0 ? 'prof-down' : 'prof-zero');
        $s .= '<rect class="' . $cls . '" x="' . $bx . '" y="' . round($by, 1) . '" width="' . round($bw, 1) . '" height="' . round($h, 1) . '"><title>' . e($x['day']) . ': ' . ($x['v'] > 0 ? '+' : '') . number_format($x['v']) . '</title></rect>';
    }
    return $s . '</svg>';
}
