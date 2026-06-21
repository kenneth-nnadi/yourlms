<?php
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/course_import.php';
$user = require_teach_access($pdo);

$courses = teach_admin_course_options($pdo, $user);
$lastReport = $_SESSION['import_report'] ?? null;
unset($_SESSION['import_report']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cleanup = null;
    try {
        $mode = $_POST['import_mode'] ?? 'new';
        $targetCourseId = $mode === 'existing' ? (int) ($_POST['target_course_id'] ?? 0) : null;
        $replaceExisting = $mode === 'existing' && isset($_POST['replace_content']);

        if ($targetCourseId) {
            require_course_content_editor($pdo, $targetCourseId, $user);
        }

        if (empty($_FILES['package']['tmp_name'])) {
            throw new RuntimeException('Please choose a .json or .zip file.');
        }

        $parsed = parse_course_import_upload($config, $_FILES['package']);
        $cleanup = $parsed['cleanup'] ?? null;
        $report = import_course_json(
            $pdo,
            $parsed['data'],
            $config,
            $user['id'],
            $targetCourseId,
            $replaceExisting,
            $parsed['files_root'] ?? null
        );
        $_SESSION['import_report'] = $report;
        $course = $pdo->prepare('SELECT code, name FROM courses WHERE id = ?');
        $course->execute([$report['course_id']]);
        $c = $course->fetch();
        flash('success', 'Import complete — ' . ($c['code'] ?? 'course') . ' (' . ($c['name'] ?? '') . ').');
    } catch (Throwable $e) {
        flash('error', 'Import failed: ' . $e->getMessage());
    } finally {
        if ($cleanup && is_dir($cleanup)) {
            $rm = static function (string $dir) use (&$rm): void {
                foreach (scandir($dir) ?: [] as $entry) {
                    if ($entry === '.' || $entry === '..') {
                        continue;
                    }
                    $path = $dir . '/' . $entry;
                    is_dir($path) ? $rm($path) : unlink($path);
                }
                rmdir($dir);
            };
            $rm($cleanup);
        }
    }
    redirect('/admin/import-json.php');
}

render_head('Import JSON backup');
render_app_shell_start($user, 'admin', '/admin/index.php');
?>
<?php render_page_header('Import JSON backup', 'Teach'); ?>
<div class="page-body">
  <p style="color:#71717a;max-width:720px;">
    Restore a course from an <code>open-lms-course-v1</code> export. Upload a <strong>.zip</strong> from
    <a href="<?= url('admin/export.php') ?>">Export course</a> (includes files) or a standalone <strong>.json</strong> (metadata only).
  </p>

  <?php if ($lastReport): ?>
    <div class="content-box import-report" style="margin-bottom:24px;background:#f0fdf4;border-color:#bbf7d0;">
      <h3 style="margin:0 0 12px;">Last import summary</h3>
      <p style="margin:0 0 8px;font-size:14px;">
        Course ID <strong><?= (int)$lastReport['course_id'] ?></strong>
        <?php if (!empty($lastReport['replaced'])): ?> · replaced existing content<?php endif; ?>
        · <a href="<?= url('course.php?id=' . (int)$lastReport['course_id']) ?>">Open course</a>
        · <a href="<?= url('admin/modules.php?course_id=' . (int)$lastReport['course_id']) ?>">Edit modules</a>
      </p>
      <?php if (!empty($lastReport['stats'])): ?>
        <ul class="import-report-stats">
          <?php foreach ($lastReport['stats'] as $key => $val): ?>
            <li><span><?= e(ucfirst((string)$key)) ?></span><strong><?= (int)$val ?></strong></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="content-box" style="margin:24px 0;background:#fafafa;">
    <form method="post" enctype="multipart/form-data">
      <?php render_csrf_field(); ?>
      <div class="form-group">
        <label>Export file (.zip or .json)</label>
        <input type="file" name="package" accept=".zip,.json,application/json" required>
      </div>

      <div class="form-group">
        <label>Import mode</label>
        <select name="import_mode" id="import-mode">
          <option value="new">Create new course (recommended)</option>
          <option value="existing">Import into existing course</option>
        </select>
      </div>

      <div id="existing-course-options" style="display:none;margin-bottom:16px;">
        <div class="form-group">
          <label>Target course</label>
          <select name="target_course_id">
            <?php foreach ($courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= e($c['code'] . ' — ' . $c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-size:14px;">
          <input type="checkbox" name="replace_content" value="1">
          Replace this course's modules, assignments, quizzes, and discussions (enrollments are kept)
        </label>
      </div>

      <button class="btn" type="submit">Import backup</button>
    </form>
  </div>
</div>
<script>
document.getElementById('import-mode')?.addEventListener('change', function () {
  document.getElementById('existing-course-options').style.display = this.value === 'existing' ? 'block' : 'none';
});
</script>
<?php render_app_shell_end(); ?>