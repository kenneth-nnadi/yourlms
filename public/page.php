<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();

$courseId = (int) ($_GET['course_id'] ?? 0);
$itemId = (int) ($_GET['item_id'] ?? 0);
$course = require_course_access($pdo, $courseId, $user);

$stmt = $pdo->prepare(
    "SELECT mi.*, m.published AS module_published FROM module_items mi
     JOIN modules m ON m.id = mi.module_id
     WHERE mi.id = ? AND mi.item_type = 'page' AND m.course_id = ?"
);
$stmt->execute([$itemId, $courseId]);
$item = $stmt->fetch();
if (!$item) {
    http_response_code(404);
    die('Page not found.');
}
if (!user_can_view_unpublished($pdo, $courseId, $user) && !item_visible_to_student($item, ['published' => $item['module_published']])) {
    http_response_code(404);
    die('Page not found.');
}

$isHtml = ($item['content_format'] ?? 'text') === 'html';

render_head($item['title']);
render_app_shell_start($user, 'courses', "course.php?id={$courseId}");
render_course_shell_start($course, 'home', $courseId);
$headerActions = course_header_actions($pdo, $courseId, $user);
if (user_can_edit_course_content($pdo, $courseId, $user)) {
    $headerActions .= '<a class="btn btn-sm" href="' . url('admin/edit-item.php?course_id=' . $courseId . '&id=' . $itemId) . '">Edit page</a>';
}
render_course_header('Page', $headerActions);
?>
<div class="course-page rich-content-page">
  <?php if (!str_contains($item['content'] ?? '', 'module-subheader')): ?>
    <h1 style="font-size:1.75rem;font-weight:700;margin:0 0 8px;"><?= e($item['title']) ?></h1>
  <?php endif; ?>
  <div class="content-box rich-html" style="margin-top:24px;">
    <?php if ($isHtml): ?>
      <?= $item['content'] ?? '' ?>
    <?php else: ?>
      <?= e($item['content'] ?? '') ?>
    <?php endif; ?>
  </div>
</div>
<?php
render_course_shell_end();
render_app_shell_end();