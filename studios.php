<?php
// Moved: studios now live on the main page (?c=studios). Old links keep working.
$p = ['c' => 'studios'];
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') $p['q'] = $q;
$pg = (int)($_GET['page'] ?? 1);
if ($pg > 1) $p['page'] = $pg;
header('Location: /s/census/?' . http_build_query($p), true, 301);
exit;