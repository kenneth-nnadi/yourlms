<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();

$courseId = (int) ($_GET['course_id'] ?? 0);
$course = require_course_access($pdo, $courseId, $user);
$canEdit = user_can_edit_course_content($pdo, $courseId, $user);

$stmt = $pdo->prepare('SELECT * FROM external_tools WHERE course_id = ? ORDER BY name');
$stmt->execute([$courseId]);
$tools = $stmt->fetchAll();

render_head('Tools');
render_app_shell_start($user, 'courses', "course.php?id={$courseId}");
render_course_shell_start($course, 'tools', $courseId);
$headerActions = course_header_actions($pdo, $courseId, $user);
if ($canEdit) {
    $headerActions .= '<a class="btn btn-sm btn-outline" href="' . url('admin/lti-tools.php?course_id=' . $courseId) . '">Manage tools</a>';
}
render_course_header('External tools', $headerActions);
?>
<div class="course-page">
  <?php if ($tools): ?>
    <div class="panel">
      <?php foreach ($tools as $tool): ?>
        <a class="panel-row" href="<?= url('lti-launch.php?course_id=' . $courseId . '&tool_id=' . (int) $tool['id']) ?>" target="_blank" rel="noopener" style="display:block;text-decoration:none;color:inherit;">
          <div style="font-weight:600;color:var(--brand-accent);">🔌 <?= e($tool['name']) ?></div>
          <div style="font-size:12px;color:#71717a;margin-top:4px;">Opens in a new window</div>
        </a>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <?php
    $actions = $canEdit
        ? [['label' => 'Add external tools', 'href' => url('admin/lti-tools.php?course_id=' . $courseId), 'primary' => true]]
        : [];
    render_empty_state(
        'No external tools in this course.',
        $canEdit
            ? 'Configure LTI tools, then add them to modules or launch them from here.'
            : 'Your instructor has not added any external tools yet.',
        $actions
    );
    ?>
  <?php endif; ?>
</div>
<?php
render_course_shell_end();
render_app_shell_end();