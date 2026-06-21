<?php
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_helpers.php';
$user = require_teach_access($pdo);

$courses = teach_admin_courses($pdo, $user);
$courseId = (int) ($_GET['course_id'] ?? ($courses[0]['id'] ?? 0));
if ($courseId) {
    require_course_content_editor($pdo, $courseId, $user);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $courseId = (int) ($_POST['course_id'] ?? 0);
    require_course_content_editor($pdo, $courseId, $user);

    if (isset($_POST['add_group'])) {
        $name = trim($_POST['name'] ?? '');
        $weight = (float) ($_POST['weight'] ?? 0);
        if ($name !== '') {
            $pos = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM assignment_groups WHERE course_id = ?');
            $pos->execute([$courseId]);
            $pdo->prepare('INSERT INTO assignment_groups (course_id, name, weight, position) VALUES (?, ?, ?, ?)')
                ->execute([$courseId, $name, $weight, (int) $pos->fetchColumn()]);
            flash('success', 'Group added.');
        }
    }

    if (isset($_POST['update_group'])) {
        $pdo->prepare('UPDATE assignment_groups SET name = ?, weight = ? WHERE id = ? AND course_id = ?')
            ->execute([trim($_POST['name'] ?? ''), (float) ($_POST['weight'] ?? 0), (int) $_POST['group_id'], $courseId]);
        flash('success', 'Group updated.');
    }

    if (isset($_POST['delete_group'])) {
        $gid = (int) $_POST['group_id'];
        $pdo->prepare('UPDATE assignments SET group_id = NULL WHERE group_id = ? AND course_id = ?')->execute([$gid, $courseId]);
        $pdo->prepare('UPDATE quizzes SET group_id = NULL WHERE group_id = ? AND course_id = ?')->execute([$gid, $courseId]);
        $pdo->prepare('UPDATE discussions SET group_id = NULL WHERE group_id = ? AND course_id = ?')->execute([$gid, $courseId]);
        $pdo->prepare('DELETE FROM assignment_groups WHERE id = ? AND course_id = ?')->execute([$gid, $courseId]);
        flash('success', 'Group deleted.');
    }

    redirect('/admin/groups.php?course_id=' . $courseId);
}

$groups = [];
if ($courseId) {
    $groups = assignment_groups_for_course($pdo, $courseId);
}
$totalWeight = array_sum(array_map(fn($g) => (float) $g['weight'], $groups));

render_head('Assignment groups');
render_app_shell_start($user, 'admin', '/admin/index.php');
?>
<?php render_page_header('Assignment groups', 'Weighted grades'); ?>
<div class="page-body" style="max-width:640px;">
  <p style="color:#71717a;">Group assignments, quizzes, and discussions and set weights (should total 100%). Weighted totals appear on Grades and in the gradebook. Items left ungrouped count toward raw points but not toward the weighted percentage.</p>

  <form method="get" style="margin-bottom:20px;">
    <select name="course_id" onchange="this.form.submit()">
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $courseId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code'] . ' — ' . $c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php if ($groups): ?>
    <p style="font-size:14px;">Total weight: <strong><?= number_format($totalWeight, 1) ?>%</strong><?= abs($totalWeight - 100) > 0.01 ? ' <span style="color:#b45309;">(should be 100%)</span>' : '' ?></p>
    <div class="panel" style="margin-bottom:24px;">
      <?php foreach ($groups as $g): ?>
        <form method="post" class="panel-row" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
          <input type="hidden" name="course_id" value="<?= $courseId ?>">
          <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
          <input type="hidden" name="update_group" value="1">
          <div style="flex:1;min-width:160px;"><label style="font-size:12px;">Name</label><input name="name" value="<?= e($g['name']) ?>" required></div>
          <div><label style="font-size:12px;">Weight %</label><input name="weight" type="number" step="0.1" value="<?= e((string)$g['weight']) ?>" style="width:90px;"></div>
          <button class="btn btn-sm" type="submit">Save</button>
        </form>
        <form method="post" class="panel-row" style="padding-top:0;margin-top:-8px;">
          <input type="hidden" name="course_id" value="<?= $courseId ?>">
          <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
          <input type="hidden" name="delete_group" value="1">
          <button class="btn btn-sm btn-outline" type="submit" onclick="return confirm('Delete this group?');">Delete group</button>
        </form>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="content-box" style="background:#fafafa;">
    <h3 style="margin:0 0 12px;">New group</h3>
    <form method="post">
      <input type="hidden" name="course_id" value="<?= $courseId ?>">
      <input type="hidden" name="add_group" value="1">
      <div style="display:flex;gap:12px;flex-wrap:wrap;">
        <div class="form-group" style="flex:1;min-width:160px;"><label>Name</label><input name="name" required placeholder="e.g. Homework"></div>
        <div class="form-group"><label>Weight %</label><input name="weight" type="number" step="0.1" value="0" style="width:90px;"></div>
      </div>
      <button class="btn btn-sm" type="submit">Add group</button>
    </form>
  </div>
  <p style="font-size:13px;color:#71717a;margin-top:16px;">Assign groups when creating or editing <a href="<?= url('admin/assignments.php?course_id=' . $courseId) ?>">assignments</a>, <a href="<?= url('admin/quizzes.php?course_id=' . $courseId) ?>">quizzes</a>, or <a href="<?= url('admin/discussions.php?course_id=' . $courseId) ?>">discussions</a>.</p>
</div>
<?php render_app_shell_end(); ?>