<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$courseId = (int) ($_GET['course_id'] ?? 0);
$quizId = (int) ($_GET['quiz_id'] ?? 0);
$studentId = (int) ($_GET['student_id'] ?? 0);
$course = require_course_access($pdo, $courseId, $user);

$stmt = $pdo->prepare('SELECT * FROM quizzes WHERE id = ? AND course_id = ?');
$stmt->execute([$quizId, $courseId]);
$quiz = $stmt->fetch();
if (!$quiz) {
    http_response_code(404);
    die('Quiz not found.');
}

$isStaff = user_can_grade($pdo, $courseId, $user);
if (!$isStaff && $studentId !== (int) $user['id']) {
    flash('error', 'You can only view your own attempts.');
    redirect("quiz.php?course_id={$courseId}&id={$quizId}");
}
if (!$isStaff) {
    $studentId = (int) $user['id'];
}

$student = null;
if ($isStaff && $studentId) {
    $s = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
    $s->execute([$studentId]);
    $student = $s->fetch();
}

$attempts = $pdo->prepare(
    'SELECT * FROM quiz_attempts WHERE quiz_id = ? AND user_id = ? ORDER BY submitted_at DESC'
);
$attempts->execute([$quizId, $studentId]);
$attempts = $attempts->fetchAll();

render_head('Quiz attempts');
render_app_shell_start($user, 'courses', "quiz.php?course_id={$courseId}&id={$quizId}");
render_course_shell_start($course, 'quizzes', $courseId);
$back = $isStaff
    ? url("gradebook.php?course_id={$courseId}")
    : url("quiz.php?course_id={$courseId}&id={$quizId}");
render_course_header('Quiz attempts', '<a class="btn btn-sm btn-outline" href="' . e($back) . '">Back</a>');
?>
<div class="course-page">
  <h1 style="font-size:1.5rem;margin:0;"><?= e($quiz['title']) ?></h1>
  <?php if ($student): ?>
    <p style="color:#71717a;font-size:14px;"><?= e($student['full_name']) ?> · <?= count($attempts) ?> attempt(s)</p>
  <?php endif; ?>

  <div class="panel" style="margin-top:20px;">
    <?php foreach ($attempts as $i => $att): ?>
      <div class="panel-row">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
          <strong>Attempt <?= count($attempts) - $i ?></strong>
          <span style="font-size:13px;color:#71717a;"><?= e(format_datetime($att['submitted_at'])) ?></span>
        </div>
        <p style="margin:8px 0 0;font-size:14px;">
          Score: <?= $att['needs_grading'] ? 'Awaiting essay grading' : e(number_format((float)$att['score'], 1)) . ' / ' . e((string)$quiz['points']) ?>
        </p>
        <a class="btn btn-sm" style="margin-top:8px;" href="<?= url("quiz.php?course_id={$courseId}&id={$quizId}&attempt_id=" . (int)$att['id']) ?>">Review answers</a>
        <?php if ($isStaff && $att['needs_grading']): ?>
          <a class="btn btn-sm btn-outline" style="margin-top:8px;margin-left:8px;" href="<?= url("quiz-grade.php?course_id={$courseId}&attempt_id=" . (int)$att['id']) ?>">Grade essays</a>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if (!$attempts): ?>
      <div class="panel-row" style="color:#71717a;">No attempts yet.</div>
    <?php endif; ?>
  </div>
</div>
<?php
render_course_shell_end();
render_app_shell_end();