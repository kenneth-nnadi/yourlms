<?php
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/course_export.php';
$user = require_teach_access($pdo);

$courseId = (int) ($_GET['course_id'] ?? 0);
require_course_content_editor($pdo, $courseId, $user);

$format = $_GET['format'] ?? 'zip';
$data = export_course_json($pdo, $courseId);
$course = $data['course'];
$slug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $course['code'] ?? 'course');

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $slug . '-export.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

$zipPath = build_course_export_zip($pdo, $courseId, $config);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $slug . '-export.zip"');
header('Content-Length: ' . filesize($zipPath));
readfile($zipPath);
@unlink($zipPath);
@unlink(dirname($zipPath) . '/course.json');
@rmdir(dirname($zipPath) . '/files');
@rmdir(dirname($zipPath));
exit;