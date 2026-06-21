<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$courseId = (int) ($_GET['course_id'] ?? 0);
$return = $_GET['return'] ?? '';
if ($return === '' || !str_starts_with($return, '/')) {
    $return = $courseId ? url("course.php?id={$courseId}") : url('dashboard.php');
}

if (!$courseId) {
    redirect('/dashboard.php');
}

course_or_404($pdo, $courseId);

if (isset($_GET['on'])) {
    if (!user_is_course_staff($pdo, $courseId, $user)) {
        flash('error', 'Only teachers and TAs can preview as a student.');
        header('Location: ' . $return);
        exit;
    }
    $adminReturn = $_GET['admin_return'] ?? '';
    if ($adminReturn !== '' && !student_preview_valid_return_path($adminReturn)) {
        $adminReturn = '';
    }
    set_student_preview($courseId, true, $adminReturn ?: null);
    flash('success', 'Student preview on — you are viewing this course as a student would.');
    header('Location: ' . $return);
    exit;
}

if (isset($_GET['off'])) {
    $dest = $return;
    if (isset($_GET['admin'])) {
        $dest = student_preview_admin_return($courseId);
    }
    clear_student_preview($courseId);
    flash('success', 'Preview ended — returned to where you were editing.');
    header('Location: ' . $dest);
    exit;
}

header('Location: ' . $return);
exit;