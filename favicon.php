<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$local = __DIR__ . '/includes/branding.local.php';
if (is_file($local)) {
    $overrides = require $local;
    if (is_array($overrides)) {
        $config = array_replace_recursive($config, $overrides);
    }
}

$name = trim($config['app_name'] ?? 'LMS');
$words = preg_split('/\s+/u', $name) ?: [];
$initials = '';
foreach (array_slice($words, 0, 2) as $word) {
    if ($word !== '') {
        $initials .= mb_strtoupper(mb_substr($word, 0, 1));
    }
}
if ($initials === '' && count($words) === 1 && mb_strlen($words[0]) >= 2) {
    $initials = mb_strtoupper(mb_substr($words[0], 0, 2));
}
if ($initials === '') {
    $initials = 'L';
}

$accent = $config['theme']['accent'] ?? '#0d9488';
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
    $accent = '#0d9488';
}

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=86400');

echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" role="img" aria-label="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">
  <rect width="32" height="32" rx="8" fill="<?= htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') ?>"/>
  <text x="16" y="21" text-anchor="middle" font-family="system-ui,sans-serif" font-size="13" font-weight="700" fill="#ffffff"><?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?></text>
</svg>