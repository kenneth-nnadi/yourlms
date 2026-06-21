<?php
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_helpers.php';
$user = require_teach_access($pdo);

$courses = teach_admin_courses($pdo, $user);
$courseId = (int) ($_GET['course_id'] ?? ($courses[0]['id'] ?? 0));
if ($courseId) {
    require_course_content_editor($pdo, $courseId, $user);
}

render_head('Export course');
render_app_shell_start($user, 'admin', '/admin/index.php');
?>
<?php render_page_header('Export course', 'Teach'); ?>
<div class="page-body" style="max-width:560px;">
  <p style="color:#71717a;">Download a full backup (JSON + uploaded files) or metadata-only JSON. Use <a href="<?= url('admin/import-json.php') ?>">JSON import</a> to restore round-trip backups, or <a href="<?= url('admin/import.php') ?>">IMS import</a> for Canvas-style packages.</p>

  <form method="get" style="margin-bottom:20px;">
    <label style="font-size:12px;font-weight:600;color:#71717a;">Course</label>
    <select name="course_id" onchange="this.form.submit()" style="max-width:100%;">
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $courseId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code'] . ' — ' . $c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php if ($courseId): ?>
    <div style="display:flex;flex-wrap:wrap;gap:12px;">
      <a class="btn" href="<?= url('admin/export-download.php?course_id=' . $courseId) ?>">Download ZIP (course + files)</a>
      <a class="btn btn-outline" href="<?= url('admin/export-download.php?course_id=' . $courseId . '&format=json') ?>">Download JSON only</a>
    </div>
    <p style="font-size:13px;color:#71717a;margin-top:12px;">ZIP contains <code>course.json</code> (<code>open-lms-course-v1</code>) and a <code>files/</code> folder with course uploads.</p>
  <?php endif; ?>
</div>
<?php render_app_shell_end(); ?>