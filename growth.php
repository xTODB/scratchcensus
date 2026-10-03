<?php
// Moved: Growth is now Dynamic mode on the main page. Old links keep working.
$p = ['c' => ($_GET['type'] ?? '') === 'studios' ? 'studios' : 'users', 'm' => 'dynamic'];
if (($_GET['dir'] ?? '') === 'down') $p['dir'] = 'down';
header('Location: /s/census/?' . http_build_query($p), true, 301);
exit;