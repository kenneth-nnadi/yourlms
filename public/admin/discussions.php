<?php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/admin_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/ref_publish_ui.php';
$user = require_teach_access($pdo);

$courses = array_map(
    fn($c) => ['id' => $c['id'], 'code' => $c['code']],
    teach_admin_courses($pdo, $user)
);
$courseId = (int) ($_GET['course_id'] ?? ($courses[0]['id'] ?? 0));
$editId = (int) ($_GET['edit'] ?? 0);

if ($courseId) {
    require_course_content_editor($pdo, $courseId, $user);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $courseId = (int) ($_POST['course_id'] ?? 0);
    require_course_content_editor($pdo, $courseId, $user);

    if (isset($_POST['publish_ref']) || isset($_POST['go_live_ref']) || isset($_POST['unpublish_ref']) || isset($_POST['add_ref_to_module'])) {
        handle_ref_publish_post($pdo, $courseId, '/admin/discussions.php?course_id=' . $courseId, (int) $user['id']);
    }

    if (isset($_POST['delete_id'])) {
        $pdo->prepare('DELETE FROM discussions WHERE id = ? AND course_id = ?')->execute([(int) $_POST['delete_id'], $courseId]);
        flash('success', 'Discussion deleted.');
        redirect('/admin/discussions.php?course_id=' . $courseId);
    }

    if (isset($_POST['update_id'])) {
        $pts = ($_POST['points'] ?? '') !== '' ? (float) $_POST['points'] : null;
        $groupId = ($_POST['group_id'] ?? '') !== '' ? (int) $_POST['group_id'] : null;
        $promptFormat = content_format_value($_POST['prompt_format'] ?? 'text');
        $pdo->prepare('UPDATE discussions SET title = ?, prompt = ?, prompt_format = ?, points = ?, group_id = ? WHERE id = ? AND course_id = ?')
            ->execute([trim($_POST['title'] ?? ''), trim($_POST['prompt'] ?? ''), $promptFormat, $pts, $groupId, (int) $_POST['update_id'], $courseId]);
        flash('success', 'Discussion updated.');
        redirect('/admin/discussions.php?course_id=' . $courseId);
    }

    $title = trim($_POST['title'] ?? '');
    $prompt = trim($_POST['prompt'] ?? '');
    if ($title && !isset($_POST['update_id'])) {
        $pts = ($_POST['points'] ?? '') !== '' ? (float) $_POST['points'] : null;
        $promptFormat = content_format_value($_POST['prompt_format'] ?? 'text');
        $groupId = ($_POST['group_id'] ?? '') !== '' ? (int) $_POST['group_id'] : null;
        $pdo->prepare('INSERT INTO discussions (course_id, title, prompt, prompt_format, points, group_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$courseId, $title, $prompt, $promptFormat, $pts, $groupId, $user['id']]);
        flash('success', 'Discussion created. Use Go live or Add to module below.');
    }
    redirect('/admin/discussions.php?course_id=' . $courseId);
}

$list = [];
$editing = null;
$groups = [];
if ($courseId) {
    $groups = assignment_groups_for_course($pdo, $courseId);
    $s = $pdo->prepare('SELECT * FROM discussions WHERE course_id = ? ORDER BY created_at DESC');
    $s->execute([$courseId]);
    $list = $s->fetchAll();
    if ($editId) {
        foreach ($list as $d) {
            if ((int) $d['id'] === $editId) {
                $editing = $d;
                break;
            }
        }
    }
}

render_head('Discussions');
render_app_shell_start($user, 'admin', '/admin/index.php');
?>
<?php render_page_header('Discussions', 'Teach'); ?>
<div class="page-body">
  <form method="get" style="margin-bottom:20px;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
    <div>
      <label style="font-size:12px;font-weight:600;color:#71717a;">Course</label>
      <select name="course_id" onchange="this.form.submit()" style="max-width:320px;">
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $courseId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($courseId): ?>
      <?php render_admin_preview_link($courseId); ?>
    <?php endif; ?>
  </form>

  <p style="font-size:14px;color:#71717a;margin:0 0 20px;">
    Use <strong>Go live</strong> or <strong>Add to module</strong> on each discussion so students can see and reply.
  </p>

  <h2 class="section-title">Existing discussions (<?= count($list) ?>)</h2>
  <?php if ($list): ?>
    <div class="panel" style="margin-bottom:28px;">
      <?php foreach ($list as $d): ?>
        <div class="panel-row admin-quiz-row-wrap">
          <div class="admin-quiz-row">
            <div class="admin-quiz-row-main">
              <strong><?= e($d['title']) ?></strong>
              <span class="admin-quiz-meta">ID <?= (int)$d['id'] ?></span>
            </div>
            <div class="admin-quiz-row-actions">
              <a class="btn btn-sm" href="<?= url('admin/discussions.php?course_id=' . $courseId . '&edit=' . $d['id']) ?>">Edit</a>
              <form method="post" onsubmit="return confirm('Delete this discussion and all posts?');">
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <input type="hidden" name="delete_id" value="<?= (int)$d['id'] ?>">
                <button class="btn btn-sm btn-outline" type="submit">Delete</button>
              </form>
            </div>
          </div>
          <?php render_ref_publish_bar($pdo, $courseId, 'discussion', (int) $d['id'], $d['title'], ''); ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <?php render_empty_state('No discussions yet.', 'Create a prompt below, then publish it to a module.', [
        ['label' => 'New discussion form', 'href' => '#discussion-form', 'primary' => false],
    ]); ?>
  <?php endif; ?>

  <div class="content-box" id="discussion-form" style="margin-bottom:24px;background:#fafafa;">
    <h3 style="margin:0 0 12px;"><?= $editing ? 'Edit discussion' : 'New discussion' ?></h3>
    <form method="post">
      <input type="hidden" name="course_id" value="<?= $courseId ?>">
      <?php if ($editing): ?>
        <input type="hidden" name="update_id" value="<?= (int)$editing['id'] ?>">
      <?php endif; ?>
      <div class="form-group"><label>Title</label><input name="title" required value="<?= e($editing['title'] ?? '') ?>"></div>
      <div class="form-group">
        <label>Prompt</label>
        <textarea name="prompt" rows="4" data-rich-editor><?= e($editing['prompt'] ?? '') ?></textarea>
        <input type="hidden" name="prompt_format" value="<?= e(($editing['prompt_format'] ?? '') === 'html' ? 'html' : 'text') ?>" data-rich-format>
      </div>
      <?php if ($groups): ?>
        <div class="form-group">
          <label>Assignment group</label>
          <select name="group_id">
            <option value="">— None —</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= (int)$g['id'] ?>" <?= (int)($editing['group_id'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?> (<?= e((string)$g['weight']) ?>%)</option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="form-group"><label>Points (optional — enables grading)</label><input name="points" type="number" step="0.1" value="<?= e((string)($editing['points'] ?? '')) ?>" placeholder="Leave blank if ungraded"></div>
      <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Create discussion' ?></button>
      <?php if ($editing): ?>
        <a class="btn btn-outline" href="<?= url('admin/discussions.php?course_id=' . $courseId) ?>" style="margin-left:8px;">Cancel</a>
      <?php endif; ?>
    </form>
  </div>
</div>
<?php
require_once dirname(__DIR__, 2) . '/includes/rich_editor.php';
render_rich_editor_assets();
render_app_shell_end();
?>