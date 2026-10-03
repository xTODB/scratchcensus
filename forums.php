<?php
// Moved: forums now live on the main page (?c=forums). Old links keep working.
$p = ['c' => 'forums'];
if (($_GET['view'] ?? '') === 'posts') $p['view'] = 'posts';
if (($_GET['sort'] ?? '') === 'replies') $p['sort'] = 'replies';
$f = (int)($_GET['f'] ?? 0);
if ($f > 0) $p['f'] = $f;
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') $p['q'] = $q;
$pg = (int)($_GET['page'] ?? 1);
if ($pg > 1) $p['page'] = $pg;
header('Location: /s/census/?' . http_build_query($p), true, 301);
exit;