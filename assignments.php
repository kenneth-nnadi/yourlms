<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$courseId = (int) ($_GET['course_id'] ?? 0);
$course = require_course_access($pdo, $courseId, $user);

$stmt = $pdo->prepare('SELECT * FROM assignments WHERE course_id = ? ORDER BY due_at ASC');
$stmt->execute([$courseId]);
$assignments = filter_rows_by_published_refs($pdo, $courseId, $user, $stmt->fetchAll(), 'assignment');
$canEdit = user_can_edit_course_content($pdo, $courseId, $user);

render_head('Assignments');
render_app_shell_start($user, 'courses', "course.php?id={$courseId}");
render_course_shell_start($course, 'assignments', $courseId);
render_course_header('Assignments', course_header_actions($pdo, $courseId, $user));
?>
<div class="course-page">
  <div class="panel">
    <?php foreach ($assignments as $a): ?>
      <a class="panel-row" href="<?= url("assignment.php?course_id={$courseId}&id={$a['id']}") ?>" style="display:block;text-decoration:none;color:inherit;">
        <div style="font-weight:600;color:var(--brand-accent);"><?= e($a['title']) ?></div>
        <div style="font-size:12px;color:#71717a;margin-top:4px;">Due <?= e(format_datetime($a['due_at'])) ?> · <?= e((string)$a['points']) ?> pts</div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php if (!$assignments): ?>
    <?php
    $actions = $canEdit
        ? [['label' => 'Create assignments', 'href' => url('admin/assignments.php?course_id=' . $courseId), 'primary' => true]]
        : [];
    render_empty_state('No assignments yet.', $canEdit ? 'Create and publish assignments so students can submit work.' : 'Nothing published yet — check back soon.', $actions);
    ?>
  <?php endif; ?>
</div>
<?php
render_course_shell_end();
render_app_shell_end();