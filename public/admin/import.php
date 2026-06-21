<?php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/admin_helpers.php';
require_once dirname(__DIR__, 2) . '/includes/imscc_importer.php';
$user = require_teach_access($pdo);

$courses = teach_admin_course_options($pdo, $user);
$lastReport = $_SESSION['import_report'] ?? null;
unset($_SESSION['import_report']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    @ini_set('memory_limit', '1280M');
    @ini_set('max_execution_time', '600');
    try {
        $mode = $_POST['import_mode'] ?? 'new';
        $targetCourseId = $mode === 'existing' ? (int) ($_POST['target_course_id'] ?? 0) : null;
        $replaceExisting = $mode === 'existing' && isset($_POST['replace_content']);

        if ($targetCourseId) {
            require_course_content_editor($pdo, $targetCourseId, $user);
        }

        $zipPath = null;
        if (!empty($_FILES['imscc']['tmp_name'])) {
            $maxMb = (int) ($config['upload_max_mb'] ?? 1024);
            $file = $_FILES['imscc'];
            if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
                throw new RuntimeException(
                    'File exceeds server limit (' . (ini_get('upload_max_filesize') ?: 'unknown') . '). '
                    . 'Re-run setup or contact your host to raise upload_max_filesize and post_max_size.'
                );
            }
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Upload failed (error code ' . (int) $file['error'] . ').');
            }
            if ($file['size'] > $maxMb * 1024 * 1024) {
                throw new RuntimeException("File exceeds the {$maxMb} MB application limit.");
            }
            $zipPath = $config['upload_dir'] . '/import_upload_' . bin2hex(random_bytes(4)) . '.zip';
            if (!move_uploaded_file($file['tmp_name'], $zipPath)) {
                throw new RuntimeException('Could not save uploaded file — check that uploads/ is writable.');
            }
        }

        if ($zipPath) {
            $report = import_imscc_zip($pdo, $zipPath, $config, $user['id'], $targetCourseId, $replaceExisting);
            $_SESSION['import_report'] = $report;
            $course = $pdo->prepare('SELECT code, name FROM courses WHERE id = ?');
            $course->execute([$report['course_id']]);
            $c = $course->fetch();
            flash('success', 'Import complete — ' . ($c['code'] ?? 'course') . ' (' . ($c['name'] ?? '') . ').');
        }
    } catch (Throwable $e) {
        flash('error', 'Import failed: ' . $e->getMessage());
    }
    redirect('/admin/import.php');
}

render_head('Import Curriculum');
render_app_shell_start($user, 'admin', '/admin/index.php');
?>
<?php render_page_header('Import IMS Common Cartridge', 'Teach'); ?>
<div class="page-body">
  <p style="color:#71717a;max-width:720px;">
    Upload an IMS Common Cartridge <code>.zip</code> package. Imports create a <strong>new course</strong> by default —
    other courses and enrollments are left untouched.
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
        <label>IMS CC zip file</label>
        <input type="file" name="imscc" accept=".zip,.imscc" required>
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

      <button class="btn" type="submit">Import package</button>
    </form>
  </div>

  <p style="font-size:13px;color:#71717a;margin-top:20px;">
    In Canvas: <strong>Settings → Export Course Contents</strong> to download an IMS Common Cartridge <code>.zip</code>, then upload it here.
    Server upload limit: <strong><?= e(ini_get('upload_max_filesize') ?: 'unknown') ?></strong>
    (app limit <?= (int) ($config['upload_max_mb'] ?? 1024) ?> MB).
  </p>
</div>
<script>
document.getElementById('import-mode')?.addEventListener('change', function () {
  document.getElementById('existing-course-options').style.display = this.value === 'existing' ? 'block' : 'none';
});
</script>
<?php render_app_shell_end(); ?>