<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$courseId = (int) ($_GET['course_id'] ?? 0);
$course = require_course_access($pdo, $courseId, $user);

$stmt = $pdo->prepare('SELECT * FROM quizzes WHERE course_id = ? ORDER BY due_at ASC');
$stmt->execute([$courseId]);
$quizzes = filter_rows_by_published_refs($pdo, $courseId, $user, $stmt->fetchAll(), 'quiz');
$canEdit = user_can_edit_course_content($pdo, $courseId, $user);

render_head('Quizzes');
render_app_shell_start($user, 'courses', "course.php?id={$courseId}");
render_course_shell_start($course, 'quizzes', $courseId);
render_course_header('Quizzes', course_header_actions($pdo, $courseId, $user));
?>
<div class="course-page">
  <div class="panel">
    <?php foreach ($quizzes as $q): ?>
      <a class="panel-row" href="<?= url("quiz.php?course_id={$courseId}&id={$q['id']}") ?>" style="display:block;text-decoration:none;color:inherit;">
        <div style="font-weight:600;color:var(--brand-accent);"><?= e($q['title']) ?></div>
        <div style="font-size:12px;color:#71717a;margin-top:4px;"><?= e((string)$q['points']) ?> pts</div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php if (!$quizzes): ?>
    <?php
    $actions = $canEdit
        ? [['label' => 'Create quizzes', 'href' => url('admin/quizzes.php?course_id=' . $courseId), 'primary' => true]]
        : [];
    render_empty_state('No quizzes yet.', $canEdit ? 'Build a quiz and publish it to a module.' : 'Nothing published yet — check back soon.', $actions);
    ?>
  <?php endif; ?>
</div>
<?php
render_course_shell_end();
render_app_shell_end();