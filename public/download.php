<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();
$cfg = config();

$fileId = (int) ($_GET['file_id'] ?? 0);
$itemId = (int) ($_GET['item_id'] ?? 0);
$submissionId = (int) ($_GET['submission_id'] ?? 0);
$assignmentId = (int) ($_GET['assignment_id'] ?? 0);
$relPath = null;
$title = 'download';

if ($submissionId) {
    $stmt = $pdo->prepare(
        'SELECT s.*, a.course_id FROM submissions s
         JOIN assignments a ON a.id = s.assignment_id WHERE s.id = ?'
    );
    $stmt->execute([$submissionId]);
    $row = $stmt->fetch();
    if ($row && $row['file_path']) {
        $course = require_course_access($pdo, (int) $row['course_id'], $user);
        $canDownload = user_can_grade($pdo, (int) $row['course_id'], $user) || (int) $row['user_id'] === $user['id'];
        if ($canDownload) {
            $relPath = $row['file_path'];
            $title = $row['file_name'] ?? 'submission';
        }
    }
} elseif ($fileId) {
    $stmt = $pdo->prepare('SELECT * FROM course_files WHERE id = ?');
    $stmt->execute([$fileId]);
    $row = $stmt->fetch();
    if ($row) {
        require_course_access($pdo, (int) $row['course_id'], $user);
        $relPath = $row['file_path'];
        $title = $row['title'];
    }
} elseif ($assignmentId) {
    $stmt = $pdo->prepare('SELECT * FROM assignments WHERE id = ?');
    $stmt->execute([$assignmentId]);
    $row = $stmt->fetch();
    if ($row && $row['attachment_path']) {
        require_course_access($pdo, (int) $row['course_id'], $user);
        require_published_ref_access($pdo, (int) $row['course_id'], $user, 'assignment', $assignmentId);
        $relPath = $row['attachment_path'];
        $title = $row['attachment_name'] ?? 'assignment';
    }
} elseif ($itemId) {
    $stmt = $pdo->prepare(
        "SELECT mi.*, m.course_id, m.published AS module_published FROM module_items mi
         JOIN modules m ON m.id = mi.module_id WHERE mi.id = ? AND mi.item_type = 'file'"
    );
    $stmt->execute([$itemId]);
    $row = $stmt->fetch();
    if ($row) {
        require_course_access($pdo, (int) $row['course_id'], $user);
        if (!user_can_view_unpublished($pdo, (int) $row['course_id'], $user) && !item_visible_to_student($row, ['published' => $row['module_published']])) {
            http_response_code(404);
            die('File not found.');
        }
        $relPath = $row['file_path'];
        $title = $row['title'];
    }
}

if (!$relPath) {
    http_response_code(404);
    die('File not found.');
}

if (
    !str_starts_with($relPath, 'imscc/')
    && !str_starts_with($relPath, 'course_')
    && !str_starts_with($relPath, 'submissions/')
    && !str_starts_with($relPath, 'assignments/')
) {
    http_response_code(403);
    die('Invalid path.');
}

$full = $cfg['upload_dir'] . '/' . $relPath;
if (!is_file($full)) {
    http_response_code(404);
    die('File missing on disk.');
}

$mime = mime_content_type($full) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="' . basename($title) . '"');
header('Content-Length: ' . filesize($full));
readfile($full);
exit;