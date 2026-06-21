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
        handle_ref_publish_post($pdo, $courseId, '/admin/assignments.php?course_id=' . $courseId, (int) $user['id']);
    }

    if (isset($_POST['delete_id'])) {
        $pdo->prepare('DELETE FROM assignments WHERE id = ? AND course_id = ?')->execute([(int) $_POST['delete_id'], $courseId]);
        flash('success', 'Assignment deleted.');
        redirect('/admin/assignments.php?course_id=' . $courseId);
    }

    if (isset($_POST['update_id'])) {
        $id = (int) $_POST['update_id'];
        $groupId = ($_POST['group_id'] ?? '') !== '' ? (int) $_POST['group_id'] : null;
        $rubricId = ($_POST['rubric_id'] ?? '') !== '' ? (int) $_POST['rubric_id'] : null;
        $descFormat = ($_POST['description_format'] ?? 'text') === 'html' ? 'html' : 'text';
        $pdo->prepare(
            'UPDATE assignments SET title = ?, description = ?, description_format = ?, due_at = ?, points = ?, lock_after_due = ?, group_id = ?, rubric_id = ? WHERE id = ? AND course_id = ?'
        )->execute([
            trim($_POST['title'] ?? ''),
            trim($_POST['description'] ?? ''),
            $descFormat,
            ($_POST['due_at'] ?? '') ?: null,
            (float) ($_POST['points'] ?? 100),
            isset($_POST['lock_after_due']) ? 1 : 0,
            $groupId,
            $rubricId,
            $id,
            $courseId,
        ]);
        if (isset($_POST['remove_attachment'])) {
            $pdo->prepare('UPDATE assignments SET attachment_path = NULL, attachment_name = NULL WHERE id = ? AND course_id = ?')
                ->execute([$id, $courseId]);
        }
        if (!empty($_FILES['attachment_file']['tmp_name'])) {
            $upload = save_assignment_attachment($pdo, $courseId, $id, $_FILES['attachment_file']);
            if (!$upload['ok']) {
                flash('error', $upload['error']);
                redirect('/admin/assignments.php?course_id=' . $courseId . '&edit=' . $id);
            }
        }
        flash('success', 'Assignment updated.');
        redirect('/admin/assignments.php?course_id=' . $courseId);
    }

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $descFormat = ($_POST['description_format'] ?? 'text') === 'html' ? 'html' : 'text';
    $points = (float) ($_POST['points'] ?? 100);
    $due = $_POST['due_at'] ?? null;
    $lockAfterDue = isset($_POST['lock_after_due']) ? 1 : 0;
    $groupId = ($_POST['group_id'] ?? '') !== '' ? (int) $_POST['group_id'] : null;
    $rubricId = ($_POST['rubric_id'] ?? '') !== '' ? (int) $_POST['rubric_id'] : null;
    if ($title && !isset($_POST['update_id'])) {
        $pdo->prepare('INSERT INTO assignments (course_id, title, description, description_format, due_at, points, lock_after_due, group_id, rubric_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$courseId, $title, $description, $descFormat, $due ?: null, $points, $lockAfterDue, $groupId, $rubricId]);
        $newId = (int) $pdo->lastInsertId();
        if (!empty($_FILES['attachment_file']['tmp_name']) && $newId > 0) {
            $upload = save_assignment_attachment($pdo, $courseId, $newId, $_FILES['attachment_file']);
            if (!$upload['ok']) {
                flash('error', $upload['error']);
                redirect('/admin/assignments.php?course_id=' . $courseId . '&edit=' . $newId);
            }
        }
        flash('success', 'Assignment created. Use Publish or Add to module below.');
    }
    redirect('/admin/assignments.php?course_id=' . $courseId);
}

$list = [];
$editing = null;
$groups = [];
$rubrics = [];
if ($courseId) {
    $groups = assignment_groups_for_course($pdo, $courseId);
    $rubrics = $pdo->prepare('SELECT id, title FROM rubrics WHERE course_id = ? ORDER BY title');
    $rubrics->execute([$courseId]);
    $rubrics = $rubrics->fetchAll();
    $s = $pdo->prepare('SELECT * FROM assignments WHERE course_id = ? ORDER BY due_at, title');
    $s->execute([$courseId]);
    $list = $s->fetchAll();
    if ($editId) {
        foreach ($list as $a) {
            if ((int) $a['id'] === $editId) {
                $editing = $a;
                break;
            }
        }
    }
}

render_head('Assignments');
render_app_shell_start($user, 'admin', '/admin/index.php');
?>
<?php render_page_header('Assignments', 'Teach'); ?>
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
    Use <strong>Go live</strong> or <strong>Add to module</strong> on each assignment so students can see and submit it.
  </p>

  <h2 class="section-title">Existing assignments (<?= count($list) ?>)</h2>
  <?php if ($list): ?>
    <div class="panel" style="margin-bottom:28px;">
      <?php foreach ($list as $a): ?>
        <div class="panel-row admin-quiz-row-wrap">
          <div class="admin-quiz-row">
            <div class="admin-quiz-row-main">
              <strong><?= e($a['title']) ?></strong>
              <span class="admin-quiz-meta">
                <?= e((string)$a['points']) ?> pts · Due <?= e(format_datetime($a['due_at'])) ?>
                <?php if ($a['lock_after_due']): ?> · locks after due<?php endif; ?>
              </span>
            </div>
            <div class="admin-quiz-row-actions">
              <a class="btn btn-sm" href="<?= url('admin/assignments.php?course_id=' . $courseId . '&edit=' . $a['id']) ?>">Edit</a>
              <form method="post" onsubmit="return confirm('Delete this assignment and all submissions?');">
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <input type="hidden" name="delete_id" value="<?= (int)$a['id'] ?>">
                <button class="btn btn-sm btn-outline" type="submit">Delete</button>
              </form>
            </div>
          </div>
          <?php render_ref_publish_bar($pdo, $courseId, 'assignment', (int) $a['id'], $a['title'], ''); ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <?php render_empty_state('No assignments yet.', 'Create one below, then use Go live or Add to module.', [
        ['label' => 'New assignment form', 'href' => '#assignment-form'],
    ]); ?>
  <?php endif; ?>

  <div class="content-box" id="assignment-form" style="margin-bottom:24px;background:#fafafa;">
    <h3 style="margin:0 0 12px;"><?= $editing ? 'Edit assignment' : 'New assignment' ?></h3>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="course_id" value="<?= $courseId ?>">
      <?php if ($editing): ?>
        <input type="hidden" name="update_id" value="<?= (int)$editing['id'] ?>">
      <?php endif; ?>
      <div class="form-group"><label>Title</label><input name="title" required value="<?= e($editing['title'] ?? '') ?>"></div>
      <div class="form-group">
        <label>Description</label>
        <textarea name="description" rows="6" data-rich-editor><?= e($editing['description'] ?? '') ?></textarea>
        <input type="hidden" name="description_format" value="<?= e(($editing['description_format'] ?? '') === 'html' ? 'html' : 'text') ?>" data-rich-format>
      </div>
      <div class="form-group">
        <label>Assignment file (optional)</label>
        <input type="file" name="attachment_file" accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.zip,.png,.jpg,.jpeg,.gif,.webp">
        <p style="font-size:12px;color:#71717a;margin:6px 0 0;">Upload a worksheet, rubric PDF, or other document students should read before submitting.</p>
        <?php if ($editing && !empty($editing['attachment_path'])): ?>
          <p style="font-size:13px;margin:8px 0 0;">
            Current file:
            <a href="<?= url('download.php?assignment_id=' . (int)$editing['id']) ?>"><?= e($editing['attachment_name'] ?? 'Download') ?></a>
          </p>
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;margin-top:8px;">
            <input type="checkbox" name="remove_attachment" value="1">
            Remove current file
          </label>
        <?php endif; ?>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label>Points</label><input name="points" type="number" step="0.1" value="<?= e((string)($editing['points'] ?? 100)) ?>"></div>
        <div class="form-group"><label>Due date</label><input name="due_at" type="datetime-local" value="<?= $editing && $editing['due_at'] ? e(date('Y-m-d\TH:i', strtotime($editing['due_at']))) : '' ?>"></div>
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
      <?php if ($rubrics): ?>
        <div class="form-group">
          <label>Rubric</label>
          <select name="rubric_id">
            <option value="">— None —</option>
            <?php foreach ($rubrics as $r): ?>
              <option value="<?= (int)$r['id'] ?>" <?= (int)($editing['rubric_id'] ?? 0) === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <label style="display:flex;align-items:center;gap:8px;font-size:14px;margin:12px 0;">
        <input type="checkbox" name="lock_after_due" value="1" <?= ($editing['lock_after_due'] ?? 0) ? 'checked' : '' ?>>
        Lock submissions after due date
      </label>
      <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Create assignment' ?></button>
      <?php if ($editing): ?>
        <a class="btn btn-outline" href="<?= url('admin/assignments.php?course_id=' . $courseId) ?>" style="margin-left:8px;">Cancel</a>
      <?php endif; ?>
    </form>
  </div>
</div>
<?php
require_once dirname(__DIR__, 2) . '/includes/rich_editor.php';
render_rich_editor_assets();
render_app_shell_end();
?>