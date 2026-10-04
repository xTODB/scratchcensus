<?php
// Forum crawler + queries. Lives in its own file so functions.php (users) and
// studios-functions.php stay untouched. Reuses httpMultiGet() and getDB().
//
// Scratch's forums have no API, so this reads the HTML of scratch.mit.edu/discuss.
//   1. forum index (/discuss/)          -> the forums table
//   2. topic lists (/discuss/<id>/?page=N) -> forum_topics (replies, views)
//   3. topic pages (/discuss/topic/<id>/?page=N) -> forum_posts (search text),
//      only for topics with FORUM_POST_MIN_REPLIES or more replies
// Tables: see forums-schema.sql.
require_once __DIR__ . '/functions.php';

// ---- Tuning. Override any of these in config.php (config.php loads first).
defined('FORUM_ENABLED')              || define('FORUM_ENABLED', true);   // false = the forum cron does nothing
defined('FORUM_POSTS_ENABLED')        || define('FORUM_POSTS_ENABLED', true); // false = only crawl topic lists, never topic pages
defined('FORUM_TIME_BUDGET_SEC')      || define('FORUM_TIME_BUDGET_SEC', 20); // per cron run. Small on purpose: the other crons share this IP's rate limit
defined('FORUM_LIST_TIME_SHARE')      || define('FORUM_LIST_TIME_SHARE', 0.6); // share of a run spent on topic lists, the rest on topic pages
defined('FORUM_PAGES_PER_ROUND')      || define('FORUM_PAGES_PER_ROUND', 6);   // pages fetched at once per round (was 4). Editable on the stats page
defined('FORUM_ROUND_PAUSE_MS')       || define('FORUM_ROUND_PAUSE_MS', 200);  // pause between rounds (was 400). 6 pages per ~0.5s round + 0.2s pause = roughly 9-10 requests/s. Editable on the stats page
defined('FORUM_COOLDOWN_SEC')         || define('FORUM_COOLDOWN_SEC', 90);     // after Scratch answers 429, the forum crawler sits out this long so the shared IP can recover. 0 = no cooldown
defined('FORUM_POST_MIN_REPLIES')     || define('FORUM_POST_MIN_REPLIES', 50); // only topics with at least this many replies get their posts stored for search
defined('FORUM_POST_MAX_PAGES')       || define('FORUM_POST_MAX_PAGES', 25);   // pages of 20 posts stored per topic (25 = the first 500 posts)
defined('FORUM_POST_MAX_CHARS')       || define('FORUM_POST_MAX_CHARS', 3000); // stored characters per post
defined('FORUM_INDEX_REFRESH_HOURS')  || define('FORUM_INDEX_REFRESH_HOURS', 24); // how often the forum list itself is re-read
defined('FORUM_SKIP_IDS')             || define('FORUM_SKIP_IDS', '');          // comma list of forum ids never crawled, e.g. '16,17'
defined('FORUM_CLAIM_TTL_SEC')        || define('FORUM_CLAIM_TTL_SEC', 120);

const FORUM_BASE = 'https://scratch.mit.edu/discuss';
const FORUM_POSTS_PER_PAGE = 20;

// ---------------------------------------------------------------- parsing

function forumXPath(?string $html): ?DOMXPath {
    if ($html === null || trim($html) === '') return null;
    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $ok = $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $ok ? new DOMXPath($dom) : null;
}

function fxc(string $class): string {
    return "contains(concat(' ', normalize-space(@class), ' '), ' $class ')";
}

function fxText(?DOMNode $n): string {
    return $n ? trim(preg_replace('/\s+/u', ' ', $n->textContent)) : '';
}

function fxFirst(DOMXPath $xp, string $q, ?DOMNode $ctx = null): ?DOMNode {
    $r = $ctx ? $xp->query($q, $ctx) : $xp->query($q);
    return ($r && $r->length) ? $r->item(0) : null;
}

// /discuss/ -> list of ['id','name','category','topics','posts']
function parseForumIndex(?string $html): array {
    $xp = forumXPath($html);
    if (!$xp) return [];
    $out = [];
    foreach ($xp->query('//div[starts-with(@id, "category_body_")]') as $cat) {
        $h4 = fxFirst($xp, './/h4', $cat);
        $catName = trim(preg_replace('/^\s*Toggle shoutbox\s*/u', '', fxText($h4)));
        foreach ($xp->query('.//tbody/tr', $cat) as $tr) {
            $a = fxFirst($xp, './/h3/a', $tr);
            if (!$a || !preg_match('#/discuss/(\d+)/?$#', (string)$a->getAttribute('href'), $m)) continue;
            $out[] = [
                'id' => (int)$m[1],
                'name' => fxText($a),
                'category' => $catName,
                'topics' => (int)preg_replace('/\D/', '', fxText(fxFirst($xp, './/td[' . fxc('tc2') . ']', $tr))),
                'posts' => (int)preg_replace('/\D/', '', fxText(fxFirst($xp, './/td[' . fxc('tc3') . ']', $tr))),
            ];
        }
    }
    return $out;
}

function parseForumPagination(DOMXPath $xp): int {
    $max = 1;
    foreach ($xp->query('//div[' . fxc('pagination') . ']//a/@href') as $h) {
        if (preg_match('/[?&]page=(\d+)/', (string)$h->nodeValue, $m)) $max = max($max, (int)$m[1]);
    }
    foreach ($xp->query('//div[' . fxc('pagination') . ']//span[' . fxc('current') . ']') as $c) {
        $max = max($max, (int)fxText($c));
    }
    return $max;
}

// /discuss/<forum>/?page=N -> ['topics' => [...], 'total_pages' => int]
function parseForumTopicList(?string $html): array {
    $xp = forumXPath($html);
    if (!$xp) return ['topics' => [], 'total_pages' => 1];
    $topics = [];
    foreach ($xp->query('//table//tbody/tr') as $tr) {
        $a = fxFirst($xp, './/td[' . fxc('tcl') . ']//h3/a', $tr);
        if (!$a || !preg_match('#/discuss/topic/(\d+)/#', (string)$a->getAttribute('href'), $m)) continue;
        $repliesTxt = fxText(fxFirst($xp, './/td[' . fxc('tc2') . ']', $tr));
        $viewsTxt = fxText(fxFirst($xp, './/td[' . fxc('tc3') . ']', $tr));
        if (!preg_match('/^\d+$/', $repliesTxt) || !preg_match('/^\d+$/', $viewsTxt)) continue; // moved-topic stubs have no counts
        $con = fxFirst($xp, './/div[' . fxc('tclcon') . ']', $tr);
        $conText = fxText($con);
        $by = fxText(fxFirst($xp, './/span[' . fxc('byuser') . ']', $con));
        $lastA = fxFirst($xp, './/td[' . fxc('tcr') . ']//a', $tr);
        $lastId = 0;
        if ($lastA && preg_match('#/discuss/post/(\d+)/#', (string)$lastA->getAttribute('href'), $lm)) $lastId = (int)$lm[1];
        $topics[] = [
            'id' => (int)$m[1],
            'title' => mb_substr(fxText($a), 0, 255),
            'author' => mb_substr(preg_replace('/^by\s+/u', '', $by), 0, 60),
            'replies' => (int)$repliesTxt,
            'views' => (int)$viewsTxt,
            'sticky' => (stripos($conText, 'Sticky') === 0 || fxFirst($xp, './/div[' . fxc('isticky') . ']', $tr)) ? 1 : 0,
            'closed' => stripos($conText, 'Closed') === 0 ? 1 : 0,
            'last_post_id' => $lastId,
            'last_post_by' => mb_substr(preg_replace('/^by\s+/u', '', fxText(fxFirst($xp, './/td[' . fxc('tcr') . ']//span[' . fxc('byuser') . ']', $tr))), 0, 60),
        ];
    }
    return ['topics' => $topics, 'total_pages' => parseForumPagination($xp)];
}

// /discuss/topic/<id>/?page=N -> list of ['id','author','pos','text']
// Quoted text is dropped so a post only matches searches for what it says itself.
function parseTopicPosts(?string $html): array {
    $xp = forumXPath($html);
    if (!$xp) return [];
    $out = [];
    foreach ($xp->query('//div[' . fxc('blockpost') . '][starts-with(@id, "p")]') as $post) {
        if (!preg_match('/^p(\d+)$/', (string)$post->getAttribute('id'), $m)) continue;
        $body = fxFirst($xp, './/div[' . fxc('post_body_html') . ']', $post);
        if (!$body) continue;
        foreach (iterator_to_array($xp->query('.//blockquote', $body)) as $bq) {
            if ($bq->parentNode) $bq->parentNode->removeChild($bq);
        }
        foreach (iterator_to_array($xp->query('.//br', $body)) as $br) {
            $br->parentNode->replaceChild($body->ownerDocument->createTextNode(' '), $br);
        }
        $text = fxText($body);
        if (mb_strlen($text) > FORUM_POST_MAX_CHARS) $text = mb_substr($text, 0, FORUM_POST_MAX_CHARS);
        $pos = (int)preg_replace('/\D/', '', fxText(fxFirst($xp, './/span[' . fxc('conr') . ']', $post)));
        $out[] = [
            'id' => (int)$m[1],
            'author' => mb_substr(fxText(fxFirst($xp, './/dt/a[' . fxc('username') . ']', $post)), 0, 60),
            'pos' => $pos,
            'text' => $text,
        ];
    }
    return $out;
}

// ---------------------------------------------------------------- db helpers

function forumExec(string $sql, string $types = '', array $params = []): int {
    $stmt = getDB()->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $n = $stmt->affected_rows;
    $stmt->close();
    return (int)$n;
}

function forumRows(string $sql, string $types = '', array $params = []): array {
    $stmt = getDB()->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $res = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

// Claims up to $n rows of $table (forums or forum_topics) so overlapping cron
// runs don't work on the same row. $order is a trusted fixed string.
function forumClaim(string $table, string $key, string $where, string $order, int $n): array {
    $tok = bin2hex(random_bytes(6));
    $ttl = (int)FORUM_CLAIM_TTL_SEC;
    forumExec("UPDATE $table SET claim_token = ?, claimed_until = DATE_ADD(NOW(), INTERVAL $ttl SECOND)
               WHERE ($where) AND (claimed_until IS NULL OR claimed_until < NOW()) ORDER BY $order LIMIT $n", 's', [$tok]);
    return forumRows("SELECT * FROM $table WHERE claim_token = ? AND claimed_until >= NOW()", 's', [$tok]);
}

function forumSkipIds(): array {
    $ids = [];
    foreach (explode(',', (string)FORUM_SKIP_IDS) as $s) if ((int)$s > 0) $ids[] = (int)$s;
    return $ids;
}

// ---------------------------------------------------------------- crawling

// Re-reads /discuss/ when the forums table is empty or older than FORUM_INDEX_REFRESH_HOURS.
function ensureForumIndex(array &$st, bool $force = false): void {
    $r = forumRows("SELECT COUNT(*) AS c FROM forums");
    $count = (int)$r[0]['c'];
    // Compared in SQL so the server's time zone never gets mixed with PHP's.
    if ($count > 0 && !$force) {
        $q = forumRows("SELECT (MAX(index_at) >= DATE_SUB(NOW(), INTERVAL " . (int)FORUM_INDEX_REFRESH_HOURS . " HOUR)) AS f FROM forums");
        if ((bool)$q[0]['f']) return;
    }
    $x = httpMultiGet(['idx' => FORUM_BASE . '/'])['results']['idx'] ?? null;
    $st['requests']++;
    if (!$x || $x['code'] !== 200) { $st['index_error'] = $x ? $x['code'] : 'not attempted'; return; }
    $forums = parseForumIndex($x['body']);
    foreach ($forums as $f) {
        forumExec("INSERT INTO forums (id, name, category, topic_count, post_count, index_at) VALUES (?, ?, ?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE name = VALUES(name), category = VALUES(category), topic_count = VALUES(topic_count), post_count = VALUES(post_count), index_at = NOW()",
            'issii', [$f['id'], mb_substr($f['name'], 0, 120), mb_substr($f['category'], 0, 120), $f['topics'], $f['posts']]);
    }
    foreach (forumSkipIds() as $id) forumExec("UPDATE forums SET enabled = 0 WHERE id = ?", 'i', [$id]);
    $st['forums_indexed'] = count($forums);
}

function upsertForumTopics(int $forumId, array $topics): int {
    $n = 0;
    foreach (array_chunk($topics, 100) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'));
        $params = [];
        foreach ($chunk as $t) {
            array_push($params, $t['id'], $forumId, $t['title'], $t['author'], $t['replies'], $t['views'], $t['sticky'], $t['closed'], $t['last_post_id'], $t['last_post_by']);
        }
        $n += forumExec("INSERT INTO forum_topics (id, forum_id, title, author, replies, views, sticky, closed, last_post_id, last_post_by, seen_at) VALUES $ph
            ON DUPLICATE KEY UPDATE forum_id = VALUES(forum_id), title = VALUES(title), replies = VALUES(replies), views = VALUES(views),
            sticky = VALUES(sticky), closed = VALUES(closed), last_post_id = VALUES(last_post_id), last_post_by = VALUES(last_post_by), seen_at = NOW()",
            str_repeat('iissiiiiis', count($chunk)), $params);
    }
    return $n;
}

// One round of topic-list crawling: claim a forum, fetch its next few pages.
// Returns false when there was nothing to do.
function crawlForumListRound(array &$st): bool {
    $claimed = forumClaim('forums', 'id', 'enabled = 1', 'last_list_at IS NOT NULL, last_list_at ASC', 1);
    if (!$claimed) return false;
    $f = $claimed[0];
    $fid = (int)$f['id'];
    $next = max(1, (int)$f['next_page']);
    $total = (int)$f['total_pages'];
    $urls = [];
    for ($p = $next; $p < $next + FORUM_PAGES_PER_ROUND; $p++) {
        if ($total > 0 && $p > $total) break;
        $urls[$p] = FORUM_BASE . "/$fid/?page=$p";
    }
    if (!$urls) { $urls[1] = FORUM_BASE . "/$fid/?page=1"; $next = 1; }
    $r = httpMultiGet($urls);
    $st['requests'] += count($r['results']);
    if ($r['rate_limited']) $st['rate_limited'] = true;

    $newNext = $next;
    $wrapped = false;
    foreach ($urls as $p => $_) {
        $x = $r['results'][$p] ?? null;
        if (!$x) break;                       // not attempted (429)
        if ($x['code'] === 404) { $wrapped = true; break; } // past the last page
        if ($x['code'] !== 200) { $st['list_errors']++; break; }
        $parsed = parseForumTopicList($x['body']);
        if ($parsed['total_pages'] > 0) $total = max($parsed['total_pages'], $p);
        if (!$parsed['topics']) {              // an empty page means we ran off the end
            if ($p > 1) { $wrapped = true; break; }
            $newNext = $p + 1; continue;
        }
        upsertForumTopics($fid, $parsed['topics']);
        $st['topics_seen'] += count($parsed['topics']);
        $st['list_pages']++;
        $newNext = $p + 1;
        if ($total > 0 && $p >= $total) { $wrapped = true; break; }
    }
    if ($wrapped) { $newNext = 1; $st['forums_wrapped']++; }
    forumExec("UPDATE forums SET next_page = ?, total_pages = ?, last_list_at = NOW(), claim_token = NULL, claimed_until = NULL WHERE id = ?",
        'iii', [$newNext, $total, $fid]);
    return true;
}

// One round of topic-page crawling: claim the biggest unfinished topics, fetch one page of each.
function crawlForumPostRound(array &$st): bool {
    $min = (int)FORUM_POST_MIN_REPLIES;
    $claimed = forumClaim('forum_topics', 'id', "replies >= $min AND posts_done = 0", 'views DESC', FORUM_PAGES_PER_ROUND);
    if (!$claimed) return false;
    $urls = [];
    $byKey = [];
    foreach ($claimed as $t) {
        $k = (int)$t['id'];
        $byKey[$k] = $t;
        $urls[$k] = FORUM_BASE . "/topic/$k/?page=" . max(1, (int)$t['posts_next_page']);
    }
    $r = httpMultiGet($urls);
    $st['requests'] += count($r['results']);
    if ($r['rate_limited']) $st['rate_limited'] = true;

    foreach ($byKey as $tid => $t) {
        $page = max(1, (int)$t['posts_next_page']);
        $x = $r['results'][$tid] ?? null;
        if (!$x) { forumExec("UPDATE forum_topics SET claim_token = NULL, claimed_until = NULL WHERE id = ?", 'i', [$tid]); continue; }
        if ($x['code'] === 404 || $x['code'] === 403 || $x['code'] === 410) {
            forumExec("UPDATE forum_topics SET posts_done = 1, claim_token = NULL, claimed_until = NULL WHERE id = ?", 'i', [$tid]);
            continue;
        }
        if ($x['code'] !== 200) {
            $st['post_errors']++;
            forumExec("UPDATE forum_topics SET posts_errors = posts_errors + 1, posts_done = (posts_errors >= 3), claim_token = NULL, claimed_until = NULL WHERE id = ?", 'i', [$tid]);
            continue;
        }
        $posts = parseTopicPosts($x['body']);
        foreach (array_chunk($posts, 20) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, NOW())'));
            $params = [];
            foreach ($chunk as $p) array_push($params, $p['id'], $tid, (int)$t['forum_id'], $p['author'], $p['pos'], $p['text']);
            $st['posts_stored'] += forumExec("INSERT IGNORE INTO forum_posts (id, topic_id, forum_id, author, pos, body_text, fetched_at) VALUES $ph",
                str_repeat('iiisis', count($chunk)), $params);
        }
        $st['post_pages']++;
        $lastPage = min((int)FORUM_POST_MAX_PAGES, (int)ceil(((int)$t['replies'] + 1) / FORUM_POSTS_PER_PAGE));
        $done = (!$posts || $page >= $lastPage) ? 1 : 0;
        forumExec("UPDATE forum_topics SET posts_next_page = ?, posts_done = ?, claim_token = NULL, claimed_until = NULL WHERE id = ?",
            'iii', [$page + 1, $done, $tid]);
    }
    return true;
}

function crawlForumsBatch(bool $forceIndex = false): array {
    $st = ['requests' => 0, 'rate_limited' => false, 'forums_indexed' => 0, 'list_pages' => 0, 'topics_seen' => 0,
           'list_errors' => 0, 'forums_wrapped' => 0, 'post_pages' => 0, 'posts_stored' => 0, 'post_errors' => 0];
    if (!FORUM_ENABLED) return $st;
    $cool = forumCooldownLeft();
    if ($cool > 0) { $st['cooldown'] = $cool; return $st; }
    $start = microtime(true);
    ensureForumIndex($st, $forceIndex);
    $listBudget = FORUM_POSTS_ENABLED ? FORUM_TIME_BUDGET_SEC * FORUM_LIST_TIME_SHARE : FORUM_TIME_BUDGET_SEC;
    while (!$st['rate_limited'] && microtime(true) - $start < $listBudget) {
        if (!crawlForumListRound($st)) break;
        usleep((int)FORUM_ROUND_PAUSE_MS * 1000);
    }
    if (FORUM_POSTS_ENABLED) {
        while (!$st['rate_limited'] && microtime(true) - $start < FORUM_TIME_BUDGET_SEC) {
            if (!crawlForumPostRound($st)) break;
            usleep((int)FORUM_ROUND_PAUSE_MS * 1000);
        }
    }
    if ($st['rate_limited'] && (int)FORUM_COOLDOWN_SEC > 0) @touch(forumCooldownFile());
    return $st;
}

// 429 cooldown: a tiny temp file marks "Scratch said slow down"; runs skip the forum crawl until it is old enough.
function forumCooldownFile(): string {
    return sys_get_temp_dir() . '/scratchcensus_forum_429_' . md5(__DIR__);
}
function forumCooldownLeft(): int {
    $f = forumCooldownFile();
    if (!is_file($f)) return 0;
    return max(0, (int)FORUM_COOLDOWN_SEC - (time() - (int)@filemtime($f)));
}

// ---------------------------------------------------------------- queries

function getForumChoices(): array {
    return forumRows("SELECT id, name FROM forums ORDER BY category, name");
}

function getForumStats(): array {
    $t = forumRows("SELECT COUNT(*) AS c, SUM(replies >= " . (int)FORUM_POST_MIN_REPLIES . ") AS big, SUM(posts_done = 1 AND replies >= " . (int)FORUM_POST_MIN_REPLIES . ") AS done FROM forum_topics")[0];
    $p = forumRows("SELECT COUNT(*) AS c FROM forum_posts")[0];
    $f = forumRows("SELECT COUNT(*) AS c FROM forums WHERE enabled = 1")[0];
    return ['topics' => (int)$t['c'], 'big_topics' => (int)$t['big'], 'big_done' => (int)$t['done'], 'posts' => (int)$p['c'], 'forums' => (int)$f['c']];
}

// Same numbers as getForumStats(), kept in a small temp file for FORUM_STATS_CACHE_SEC.
// The cron prints these every minute; counting 100k+ topic rows that often is wasted work.
defined('FORUM_STATS_CACHE_SEC') || define('FORUM_STATS_CACHE_SEC', 300);
function getForumStatsCached(): array {
    $f = sys_get_temp_dir() . '/scratchcensus_fstats_' . md5(__DIR__);
    if (is_file($f) && time() - (int)@filemtime($f) < (int)FORUM_STATS_CACHE_SEC) {
        $d = json_decode((string)@file_get_contents($f), true);
        if (is_array($d) && isset($d['topics'])) return $d;
    }
    $s = getForumStats();
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, json_encode($s)) !== false) @rename($tmp, $f); else @unlink($tmp);
    return $s;
}

// Everything the key-gated stats page shows about forums. One pass over forum_topics
// instead of one scan per number, plus two tiny queries.
function getForumAdminStats(): array {
    $min = (int)FORUM_POST_MIN_REPLIES;
    $t = forumRows("SELECT COUNT(*) AS c, COALESCE(SUM(replies >= $min), 0) AS big, COALESCE(SUM(posts_done = 1 AND replies >= $min), 0) AS done,
                    COALESCE(SUM(sticky = 1), 0) AS stk, COALESCE(SUM(claimed_until >= NOW()), 0) AS clm FROM forum_topics")[0];
    $f = forumRows("SELECT COUNT(*) AS c, COALESCE(SUM(enabled = 1), 0) AS en, COALESCE(SUM(topic_count), 0) AS t, COALESCE(SUM(post_count), 0) AS p,
                    MAX(last_list_at) AS last_list, MAX(index_at) AS index_at FROM forums")[0];
    $p = forumRows("SELECT COUNT(*) AS c FROM forum_posts")[0];
    $top = forumRows("SELECT t.id, t.title, t.views FROM forum_topics t ORDER BY t.views DESC LIMIT 1");
    return [
        'topics' => (int)$t['c'], 'big_topics' => (int)$t['big'], 'big_done' => (int)$t['done'],
        'rows_sticky' => (int)$t['stk'], 'claimed' => (int)$t['clm'],
        'posts' => (int)$p['c'], 'forums' => (int)$f['en'], 'forums_all' => (int)$f['c'], 'skipped' => (int)$f['c'] - (int)$f['en'],
        'scratch_topics' => (int)$f['t'], 'scratch_posts' => (int)$f['p'],
        'last_list' => $f['last_list'], 'index_at' => $f['index_at'],
        'top_topic' => $top[0] ?? null,
    ];
}

// Adds any missing speed index. Safe to run again: an index counts as present when some
// existing index already starts with the same columns, whatever it is named.
// Run it from stats.php?key=...&indexes=1 (ALTER TABLE on big tables can take a minute).
function ensureSpeedIndexes(): array {
    $wanted = [
        'forum_topics' => [
            'idx_views_id'          => ['views', 'id'],
            'idx_replies_id'        => ['replies', 'id'],
            'idx_forum_views_id'    => ['forum_id', 'views', 'id'],
            'idx_forum_replies_id'  => ['forum_id', 'replies', 'id'],
            'idx_post_queue'        => ['posts_done', 'views'],
        ],
        'forum_posts' => [
            'idx_topic_id'          => ['topic_id'],
        ],
        'studios' => [
            'idx_status_followers_id'      => ['status', 'follower_count', 'id'],
            'idx_status_open_followers_id' => ['status', 'open_to_all', 'follower_count', 'id'],
        ],
        'scratchers' => [
            'idx_status_followers_username' => ['status', 'follower_count', 'username'],
            'idx_status_country_followers'  => ['status', 'country', 'follower_count', 'username'],
        ],
    ];
    $db = getDB();
    $out = [];
    foreach ($wanted as $table => $indexes) {
        $have = [];
        $stmt = $db->prepare("SELECT index_name AS iname, column_name AS cname FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? ORDER BY index_name, seq_in_index");
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) $have[$r['iname']][] = strtolower($r['cname']);
        $stmt->close();
        if (!$have) { $out[] = "$table: table not found, skipped"; continue; }
        foreach ($indexes as $name => $cols) {
            $sig = implode(',', $cols);
            $found = null;
            foreach ($have as $hn => $hc) {
                if (strpos(implode(',', $hc) . ',', $sig . ',') === 0) { $found = $hn; break; }
            }
            if ($found !== null) { $out[] = "$table: $name already covered by $found"; continue; }
            $t0 = microtime(true);
            try {
                $ok = $db->query("ALTER TABLE `$table` ADD INDEX `$name` (`" . implode('`, `', $cols) . "`)");
                $out[] = $ok ? "$table: added $name (" . $sig . ") in " . number_format(microtime(true) - $t0, 1) . "s" : "$table: FAILED $name: " . $db->error;
            } catch (\Throwable $e) {
                $out[] = "$table: FAILED $name: " . $e->getMessage();
            }
        }
    }
    return $out;
}

// $sort: 'views' or 'replies' (whitelisted, never user text in the SQL).
function getForumTopicsPage(string $sort, int $forumId, int $page, int $perPage): array {
    $col = $sort === 'replies' ? 'replies' : 'views';
    $where = $forumId > 0 ? 'WHERE t.forum_id = ?' : '';
    $types = $forumId > 0 ? 'i' : '';
    $params = $forumId > 0 ? [$forumId] : [];
    $total = (int)forumRows("SELECT COUNT(*) AS c FROM forum_topics t $where", $types, $params)[0]['c'];
    $offset = max(0, ($page - 1) * $perPage);
    $rows = forumRows("SELECT t.id, t.title, t.author, t.replies, t.views, t.sticky, t.closed, t.forum_id, f.name AS forum_name
        FROM forum_topics t LEFT JOIN forums f ON f.id = t.forum_id $where
        ORDER BY t.$col DESC, t.id DESC LIMIT $perPage OFFSET $offset", $types, $params);
    foreach ($rows as $i => &$r) $r['rank'] = $offset + $i + 1;
    return ['rows' => $rows, 'total' => $total];
}

// Splits a search box string into plain words and "quoted phrases".
function parseForumSearch(string $q): array {
    $words = [];
    $phrases = [];
    if (preg_match_all('/"([^"]+)"|(\S+)/u', $q, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            if (isset($x[2]) && $x[2] !== '') {
                $w = trim(preg_replace('/[+\-<>()~*"@]+/u', ' ', $x[2]));
                foreach (preg_split('/\s+/u', $w, -1, PREG_SPLIT_NO_EMPTY) as $part) $words[] = mb_substr($part, 0, 40);
            } elseif (trim($x[1]) !== '') {
                $phrases[] = mb_substr(trim($x[1]), 0, 80);
            }
        }
    }
    return ['words' => array_slice($words, 0, 6), 'phrases' => array_slice($phrases, 0, 3)];
}

// Ctrl+F over stored posts, newest first. FULLTEXT when every word is 3+
// characters (its minimum), otherwise a plain LIKE scan.
function searchForumPosts(string $q, int $forumId, int $page, int $perPage): array {
    $p = parseForumSearch($q);
    $terms = array_merge($p['words'], $p['phrases']);
    if (!$terms) return ['rows' => [], 'total' => 0, 'terms' => []];
    $short = false;
    foreach ($p['words'] as $w) if (mb_strlen($w) < 3) $short = true;

    $where = [];
    $types = '';
    $params = [];
    if ($short) {
        foreach ($terms as $t) {
            $where[] = 'p.body_text LIKE ?';
            $types .= 's';
            $params[] = '%' . addcslashes($t, '%_\\') . '%';
        }
    } else {
        $bool = [];
        foreach ($p['words'] as $w) $bool[] = '+' . $w . '*';
        foreach ($p['phrases'] as $ph) $bool[] = '+"' . $ph . '"';
        $where[] = 'MATCH(p.body_text) AGAINST (? IN BOOLEAN MODE)';
        $types .= 's';
        $params[] = implode(' ', $bool);
    }
    if ($forumId > 0) { $where[] = 'p.forum_id = ?'; $types .= 'i'; $params[] = $forumId; }
    $w = implode(' AND ', $where);

    $total = (int)forumRows("SELECT COUNT(*) AS c FROM forum_posts p WHERE $w", $types, $params)[0]['c'];
    $offset = max(0, ($page - 1) * $perPage);
    $rows = forumRows("SELECT p.id, p.topic_id, p.author, p.pos, p.body_text, t.title AS topic_title, f.name AS forum_name
        FROM forum_posts p LEFT JOIN forum_topics t ON t.id = p.topic_id LEFT JOIN forums f ON f.id = p.forum_id
        WHERE $w ORDER BY p.id DESC LIMIT $perPage OFFSET $offset", $types, $params);
    return ['rows' => $rows, 'total' => $total, 'terms' => $terms];
}

// A short excerpt around the first match, HTML-escaped, matches in <mark>.
function forumPreview(string $text, array $terms, int $len = 260): string {
    $pos = null;
    foreach ($terms as $t) {
        $i = mb_stripos($text, $t);
        if ($i !== false && ($pos === null || $i < $pos)) $pos = $i;
    }
    $start = $pos === null ? 0 : max(0, $pos - 90);
    $snip = mb_substr($text, $start, $len);
    $out = ($start > 0 ? '...' : '') . e($snip) . ($start + $len < mb_strlen($text) ? '...' : '');
    foreach ($terms as $t) {
        $out = preg_replace('/' . preg_quote(htmlspecialchars($t, ENT_QUOTES), '/') . '/iu', '<mark>$0</mark>', $out) ?? $out;
    }
    return $out;
}

// ---- Public crawl buttons (crawl.php -> crawl-run.php) ---------------------
defined('PUBLIC_FORUM_CRAWL_SEC') || define('PUBLIC_FORUM_CRAWL_SEC', 15); // one click works for about this long

// $what: 'topics' (read the next pages of topic lists) or 'posts' (store the next big topics' posts).
// Same rounds the forum cron runs, so claims keep a click and the cron from working on the same rows.
function crawlForumsPublic(string $what, int $budgetSec = PUBLIC_FORUM_CRAWL_SEC): array {
    $st = ['requests' => 0, 'rate_limited' => false, 'forums_indexed' => 0, 'list_pages' => 0, 'topics_seen' => 0,
           'list_errors' => 0, 'forums_wrapped' => 0, 'post_pages' => 0, 'posts_stored' => 0, 'post_errors' => 0, 'disabled' => false];
    if (!FORUM_ENABLED || ($what === 'posts' && !FORUM_POSTS_ENABLED)) { $st['disabled'] = true; return $st; }
    $start = microtime(true);
    if ($what === 'topics') ensureForumIndex($st);
    while (!$st['rate_limited'] && microtime(true) - $start < $budgetSec) {
        $more = $what === 'posts' ? crawlForumPostRound($st) : crawlForumListRound($st);
        if (!$more) break;
        usleep((int)FORUM_ROUND_PAUSE_MS * 1000);
    }
    return $st;
}

// ---- One forum topic by id or link (crawl.php -> crawl-run.php, what=topic) ------------

// "906446", "#906446" or a topic link -> id (null when it is neither).
function parseForumTopicInput(string $s): ?int {
    $s = trim($s);
    if (preg_match('~discuss/topic/(\d{1,10})~i', $s, $m)) return (int)$m[1];
    if (preg_match('/^#?(\d{1,10})$/', $s, $m)) return (int)$m[1];
    return null;
}

// Reads what a topic page itself shows: forum id, title, first poster, page count.
// Views are NOT on a topic page, so they are not read here.
function parseForumTopicPage(?string $html): ?array {
    $xp = forumXPath($html);
    if (!$xp) return null;
    $title = '';
    $t = fxFirst($xp, '//title');
    if ($t) $title = trim(preg_replace('/\s*-\s*Discuss Scratch\s*$/u', '', fxText($t)));
    $forumId = 0;
    foreach ($xp->query('//div[' . fxc('linkst') . ']//li/a/@href') as $h) {
        if (preg_match('#^/discuss/(\d+)/?$#', (string)$h->nodeValue, $m)) $forumId = (int)$m[1]; // the last one is the forum
    }
    $posts = parseTopicPosts($html);
    if ($title === '' || $forumId <= 0 || !$posts) return null;
    return ['title' => mb_substr($title, 0, 255), 'forum_id' => $forumId, 'pages' => parseForumPagination($xp), 'posts' => $posts];
}

// Fetches one topic right now and adds it (or refreshes it). A new topic starts with 0 views:
// topic pages do not show views, the normal topic-list crawl fills them in later.
// Returns ['ok' => true, 'title', 'forum', 'replies', 'new'] or ['ok' => false, 'reason' => 'notfound'|'busy'].
function crawlSingleForumTopic(int $id): array {
    $r = httpMultiGet(['t' => FORUM_BASE . "/topic/$id/"]);
    $x = $r['results']['t'] ?? null;
    if (!$x) return ['ok' => false, 'reason' => 'busy'];
    if (in_array($x['code'], [403, 404, 410], true)) return ['ok' => false, 'reason' => 'notfound'];
    if ($x['code'] !== 200) return ['ok' => false, 'reason' => 'busy'];
    $first = parseForumTopicPage($x['body']);
    if (!$first) return ['ok' => false, 'reason' => 'notfound'];

    $pages = max(1, (int)$first['pages']);
    $last = $first['posts'];
    if ($pages > 1) { // replies need the post count of the last page
        $x2 = httpMultiGet(['t' => FORUM_BASE . "/topic/$id/?page=$pages"])['results']['t'] ?? null;
        if (!$x2 || $x2['code'] !== 200) return ['ok' => false, 'reason' => 'busy'];
        $last = parseTopicPosts($x2['body']);
        if (!$last) return ['ok' => false, 'reason' => 'busy'];
    }
    $replies = max(0, ($pages - 1) * FORUM_POSTS_PER_PAGE + count($last) - 1);
    $lastPost = $last[count($last) - 1];
    $author = $first['posts'][0]['author'];
    $forumId = (int)$first['forum_id'];
    $bigTopic = $replies >= (int)FORUM_POST_MIN_REPLIES;

    $existed = forumRows("SELECT id FROM forum_topics WHERE id = ?", 'i', [$id]) ? true : false;
    forumExec("INSERT INTO forum_topics (id, forum_id, title, author, replies, views, sticky, closed, last_post_id, last_post_by, seen_at, posts_next_page)
        VALUES (?, ?, ?, ?, ?, 0, 0, 0, ?, ?, NOW(), ?)
        ON DUPLICATE KEY UPDATE forum_id = VALUES(forum_id), title = VALUES(title), author = VALUES(author), replies = VALUES(replies),
        last_post_id = VALUES(last_post_id), last_post_by = VALUES(last_post_by), seen_at = NOW()",
        'iissiisi', [$id, $forumId, $first['title'], $author, $replies, $lastPost['id'], $lastPost['author'], $bigTopic ? 2 : 1]);

    if ($bigTopic) { // page 1 is already in hand; the posts cron carries on from page 2 (INSERT IGNORE skips repeats)
        foreach (array_chunk($first['posts'], 20) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, NOW())'));
            $params = [];
            foreach ($chunk as $p) array_push($params, $p['id'], $id, $forumId, $p['author'], $p['pos'], $p['text']);
            forumExec("INSERT IGNORE INTO forum_posts (id, topic_id, forum_id, author, pos, body_text, fetched_at) VALUES $ph",
                str_repeat('iiisis', count($chunk)), $params);
        }
    }
    $f = forumRows("SELECT name FROM forums WHERE id = ?", 'i', [$forumId]);
    return ['ok' => true, 'title' => $first['title'], 'forum' => $f ? (string)$f[0]['name'] : '', 'replies' => $replies, 'new' => !$existed];
}
