<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$courseId = (int) ($_GET['course_id'] ?? 0);
$course = require_course_access($pdo, $courseId, $user);

$stmt = $pdo->prepare('SELECT * FROM discussions WHERE course_id = ? ORDER BY created_at DESC');
$stmt->execute([$courseId]);
$discussions = filter_rows_by_published_refs($pdo, $courseId, $user, $stmt->fetchAll(), 'discussion');
$canEdit = user_can_edit_course_content($pdo, $courseId, $user);

render_head('Discussions');
render_app_shell_start($user, 'courses', "course.php?id={$courseId}");
render_course_shell_start($course, 'discussions', $courseId);
render_course_header('Discussions', course_header_actions($pdo, $courseId, $user));
?>
<div class="course-page">
  <div class="panel">
    <?php foreach ($discussions as $d): ?>
      <a class="panel-row" href="<?= url("discussion.php?course_id={$courseId}&id={$d['id']}") ?>" style="display:block;text-decoration:none;color:inherit;">
        <div style="font-weight:600;color:var(--brand-accent);"><?= e($d['title']) ?></div>
        <?php if ($d['prompt']): ?>
          <p style="margin:4px 0 0;font-size:13px;color:#71717a;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?= e($d['prompt']) ?></p>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php if (!$discussions): ?>
    <?php
    $actions = $canEdit
        ? [['label' => 'Create discussions', 'href' => url('admin/discussions.php?course_id=' . $courseId), 'primary' => true]]
        : [];
    render_empty_state('No discussions yet.', $canEdit ? 'Add a discussion prompt and publish it to a module.' : 'Nothing published yet — check back soon.', $actions);
    ?>
  <?php endif; ?>
</div>
<?php
render_course_shell_end();
render_app_shell_end();