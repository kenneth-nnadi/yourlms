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

    if (isset($_POST['create_rubric'])) {
        $title = trim($_POST['title'] ?? '');
        if ($title !== '') {
            $pdo->prepare('INSERT INTO rubrics (course_id, title) VALUES (?, ?)')->execute([$courseId, $title]);
            $rid = (int) $pdo->lastInsertId();
            foreach (array_filter(array_map('trim', $_POST['criteria'] ?? [])) as $i => $desc) {
                $pts = (float) ($_POST['criteria_points'][$i] ?? 0);
                $pdo->prepare('INSERT INTO rubric_criteria (rubric_id, description, points, position) VALUES (?, ?, ?, ?)')
                    ->execute([$rid, $desc, $pts, $i]);
            }
            flash('success', 'Rubric created.');
        }
    }

    if (isset($_POST['delete_rubric'])) {
        $pdo->prepare('DELETE FROM rubrics WHERE id = ? AND course_id = ?')->execute([(int) $_POST['rubric_id'], $courseId]);
        flash('success', 'Rubric deleted.');
    }

    redirect('/admin/rubrics.php?course_id=' . $courseId);
}

$rubrics = [];
if ($courseId) {
    $stmt = $pdo->prepare('SELECT * FROM rubrics WHERE course_id = ? ORDER BY title');
    $stmt->execute([$courseId]);
    $rubrics = $stmt->fetchAll();
    foreach ($rubrics as &$r) {
        $c = $pdo->prepare('SELECT * FROM rubric_criteria WHERE rubric_id = ? ORDER BY position');
        $c->execute([(int) $r['id']]);
        $r['criteria'] = $c->fetchAll();
    }
    unset($r);
}

render_head('Rubrics');
render_app_shell_start($user, 'admin', '/admin/index.php');
?>
<?php render_page_header('Rubrics', 'Teach'); ?>
<div class="page-body" style="max-width:720px;">
  <form method="get" style="margin-bottom:20px;">
    <select name="course_id" onchange="this.form.submit()">
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $courseId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php foreach ($rubrics as $r): ?>
    <div class="content-box" style="margin-bottom:16px;">
      <div style="display:flex;justify-content:space-between;align-items:center;">
        <strong><?= e($r['title']) ?></strong>
        <form method="post" onsubmit="return confirm('Delete rubric?');">
          <input type="hidden" name="course_id" value="<?= $courseId ?>">
          <input type="hidden" name="delete_rubric" value="1">
          <input type="hidden" name="rubric_id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-sm btn-outline" type="submit">Delete</button>
        </form>
      </div>
      <ul style="margin:12px 0 0;padding-left:20px;font-size:14px;">
        <?php foreach ($r['criteria'] as $c): ?>
          <li><?= e($c['description']) ?> — <?= e((string)$c['points']) ?> pts</li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endforeach; ?>

  <div class="content-box" style="background:#fafafa;">
    <h3 style="margin:0 0 12px;">New rubric</h3>
    <form method="post">
      <input type="hidden" name="course_id" value="<?= $courseId ?>">
      <input type="hidden" name="create_rubric" value="1">
      <div class="form-group"><label>Title</label><input name="title" required></div>
      <?php for ($i = 0; $i < 4; $i++): ?>
        <div style="display:flex;gap:12px;margin-bottom:8px;">
          <input name="criteria[]" placeholder="Criterion <?= $i + 1 ?>" style="flex:1;">
          <input name="criteria_points[]" type="number" step="0.1" placeholder="Pts" style="width:80px;">
        </div>
      <?php endfor; ?>
      <button class="btn" type="submit">Create rubric</button>
    </form>
    <p style="font-size:13px;color:#71717a;margin:12px 0 0;">Attach rubrics to assignments on the <a href="<?= url('admin/assignments.php?course_id=' . $courseId) ?>">Assignments</a> page.</p>
  </div>
</div>
<?php render_app_shell_end(); ?>