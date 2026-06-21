<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$docs = [
    'publishing' => ['title' => 'Publishing guide', 'file' => 'publishing.md'],
    'ssl' => ['title' => 'SSL & custom domain', 'file' => 'ssl-and-domain.md'],
    'install' => ['title' => 'Installation', 'file' => 'INSTALL.md'],
];
$key = $_GET['doc'] ?? 'publishing';
if (!isset($docs[$key])) {
    http_response_code(404);
    die('Help topic not found.');
}
$path = __DIR__ . '/docs/' . $docs[$key]['file'];
if (!is_file($path)) {
    http_response_code(404);
    die('Document missing.');
}

$content = file_get_contents($path);
$lines = explode("\n", $content);
$html = '';
$inList = false;
foreach ($lines as $line) {
    $trim = trim($line);
    if ($trim === '') {
        if ($inList) {
            $html .= '</ul>';
            $inList = false;
        }
        continue;
    }
    if (str_starts_with($trim, '# ')) {
        if ($inList) { $html .= '</ul>'; $inList = false; }
        $html .= '<h1>' . e(substr($trim, 2)) . '</h1>';
    } elseif (str_starts_with($trim, '## ')) {
        if ($inList) { $html .= '</ul>'; $inList = false; }
        $html .= '<h2>' . e(substr($trim, 3)) . '</h2>';
    } elseif (str_starts_with($trim, '### ')) {
        if ($inList) { $html .= '</ul>'; $inList = false; }
        $html .= '<h3>' . e(substr($trim, 4)) . '</h3>';
    } elseif (preg_match('/^- /', $trim)) {
        if (!$inList) { $html .= '<ul>'; $inList = true; }
        $html .= '<li>' . e(substr($trim, 2)) . '</li>';
    } elseif (preg_match('/^\d+\. /', $trim)) {
        if ($inList) { $html .= '</ul>'; $inList = false; }
        $html .= '<p>' . e($trim) . '</p>';
    } else {
        if ($inList) { $html .= '</ul>'; $inList = false; }
        $html .= '<p>' . e($trim) . '</p>';
    }
}
if ($inList) {
    $html .= '</ul>';
}

render_head($docs[$key]['title']);
render_app_shell_start($user, 'dashboard', '/getting-started.php');
render_page_header($docs[$key]['title'], 'Help');
echo '<div class="page-body help-doc" style="max-width:720px;">' . $html . '</div>';
echo '<p style="max-width:720px;margin:24px auto;"><a href="' . url('getting-started.php') . '">← Back to getting started</a></p>';
render_app_shell_end();