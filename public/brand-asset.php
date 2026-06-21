<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/config.php';
$local = dirname(__DIR__) . '/includes/branding.local.php';
if (is_file($local)) {
    $overrides = require $local;
    if (is_array($overrides)) {
        $config = array_replace_recursive($config, $overrides);
    }
}

$rel = trim((string) ($config['app_logo'] ?? ''));
if (!preg_match('#^branding/logo\.(png|jpe?g|webp|svg)$#i', $rel)) {
    http_response_code(404);
    exit('Not found');
}

$full = $config['upload_dir'] . '/' . $rel;
if (!is_file($full)) {
    http_response_code(404);
    exit('Not found');
}

$mime = mime_content_type($full) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($full));
header('Cache-Control: public, max-age=3600');
readfile($full);
exit;