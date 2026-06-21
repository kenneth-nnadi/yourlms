<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();

$courseId = (int) ($_GET['id'] ?? 0);
$course = require_course_access($pdo, $courseId, $user);

$modules = fetch_course_modules($pdo, $courseId, $user);
$canViewUnpublished = user_can_view_unpublished($pdo, $courseId, $user);
$canEditContent = user_can_edit_course_content($pdo, $courseId, $user);
$isGuest = user_is_course_guest($pdo, $courseId, $user);

render_head($course['name']);
render_app_shell_start($user, 'courses', '/courses.php');
render_course_shell_start($course, 'home', $courseId);
$headerActions = course_header_actions($pdo, $courseId, $user);
if ($canEditContent) {
    $headerActions .= '<a class="btn btn-sm btn-outline" href="' . url('admin/modules.php?course_id=' . $courseId) . '">Edit content</a>';
}
render_course_header('Course Home', $headerActions);
?>
<?php if ($canViewUnpublished && !course_is_published($course)): ?>
  <div class="instructor-notice">This course is <strong>unpublished</strong>. Students cannot see it until you publish the course and its modules.</div>
<?php endif; ?>
<?php if ($isGuest): ?>
  <div class="instructor-notice" style="background:#f4f4f5;border-color:#d4d4d8;color:#52525b;">You are enrolled as a <strong>guest</strong> — you can view published content but cannot submit work.</div>
<?php endif; ?>
<div class="course-page">
  <?php foreach ($modules as $m):
    $items = fetch_module_items($pdo, (int) $m['id'], $user, $m);
    $modPublished = module_is_published($m);
  ?>
    <section class="module-block <?= $canViewUnpublished && !$modPublished ? 'module-unpublished' : '' ?>">
      <div class="module-head">
        <?php if ($canViewUnpublished): ?>
          <?= publish_status_badge($modPublished) ?>
        <?php endif; ?>
        ◎ <?= e($m['title']) ?>
        <?php if ($canViewUnpublished && !$modPublished): ?>
          <span class="unpublished-label">unpublished</span>
        <?php endif; ?>
      </div>
      <div class="module-items">
        <?php if (!$items): ?>
          <div class="module-item"><span style="color:#71717a;font-style:italic;">No items in this module yet.</span></div>
        <?php endif; ?>
        <?php foreach ($items as $it):
          $href = item_link($courseId, $it);
          $icon = match ($it['item_type']) {
            'assignment' => '📋', 'quiz' => '❓', 'discussion' => '💬',
            'page' => '📄', 'file' => '📎', 'external' => '🔗', 'lti' => '🔌', default => '•',
          };
          $openNewTab = in_array($it['item_type'], ['external', 'lti'], true);
          $itemPublished = item_is_published($it);
          $rowClass = $canViewUnpublished && !$itemPublished ? 'module-item item-unpublished' : 'module-item';
        ?>
          <?php if ($href): ?>
            <a class="<?= $rowClass ?>" href="<?= e($href) ?>" <?= $openNewTab ? 'target="_blank" rel="noopener"' : '' ?>>
              <?php if ($canViewUnpublished): ?><span class="publish-inline"><?= publish_status_badge($itemPublished) ?></span><?php endif; ?>
              <span class="module-item-icon"><?= $icon ?></span>
              <div>
                <div class="module-item-title"><?= e($it['title']) ?></div>
                <div class="module-item-meta"><?= e(item_label($it)) ?></div>
              </div>
            </a>
          <?php else: ?>
            <div class="<?= $rowClass ?>">
              <?php if ($canViewUnpublished): ?><span class="publish-inline"><?= publish_status_badge($itemPublished) ?></span><?php endif; ?>
              <span class="module-item-icon"><?= $icon ?></span>
              <div>
                <div class="module-item-title"><?= e($it['title']) ?></div>
                <div class="module-item-meta"><?= e(item_label($it)) ?></div>
              </div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>
  <?php if (!$modules): ?>
    <?php
    $moduleActions = [];
    if ($canEditContent) {
        $moduleActions[] = ['label' => 'Add modules', 'href' => url('admin/modules.php?course_id=' . $courseId), 'primary' => true];
        $moduleActions[] = ['label' => 'Import IMS package', 'href' => url('admin/import.php')];
    }
    render_empty_state(
        $canViewUnpublished ? 'No modules in this course yet.' : 'No published modules yet.',
        $canViewUnpublished
            ? 'Build your course structure or import content to get started.'
            : 'Check back when your instructor publishes content.',
        $moduleActions
    );
    ?>
  <?php endif; ?>
</div>
<?php
render_course_shell_end();
render_app_shell_end();