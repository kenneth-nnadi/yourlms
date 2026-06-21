<?php
declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_login();

$rel = $_GET['f'] ?? '';
$rel = str_replace(['..', '\\'], '', $rel);
if ($rel === '' || !str_starts_with($rel, 'imscc/')) {
    http_response_code(403);
    exit('Forbidden');
}

$full = $config['upload_dir'] . '/' . $rel;
if (!is_file($full)) {
    http_response_code(404);
    exit('Not found');
}

$mime = mime_content_type($full) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full));
readfile($full);
exit;