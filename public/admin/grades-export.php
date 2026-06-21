<?php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/grade_export.php';
$user = require_login();

$courseId = (int) ($_GET['course_id'] ?? 0);
$course = require_course_access($pdo, $courseId, $user);
if (!user_can_grade($pdo, $courseId, $user)) {
    flash('error', 'You do not have permission to export grades.');
    redirect("gradebook.php?course_id={$courseId}");
}

$csv = build_grades_csv($pdo, $courseId, $user);
$slug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $course['code'] ?? 'course');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $slug . '-grades.csv"');
echo $csv;
exit;