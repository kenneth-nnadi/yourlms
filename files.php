<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$courseId = (int) ($_GET['course_id'] ?? 0);
$course = require_course_access($pdo, $courseId, $user);
$cfg = config();
$canManage = user_can_manage_course_as_staff($pdo, $courseId, $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    if (isset($_POST['upload_file']) && isset($_FILES['material'])) {
        $title = trim($_POST['title'] ?? '') ?: ($_FILES['material']['name'] ?? 'Untitled');
        $result = handle_upload($_FILES['material'], $cfg['upload_dir'] . '/course_' . $courseId, $cfg['upload_max_mb']);
        if ($result['ok']) {
            $pdo->prepare('INSERT INTO course_files (course_id, title, file_path, mime_type, uploaded_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$courseId, $title, 'course_' . $courseId . '/' . $result['path'], $result['mime'], $user['id']]);
            flash('success', 'File uploaded.');
        } else {
            flash('error', $result['error']);
        }
        redirect("files.php?course_id={$courseId}");
    }

    if (isset($_POST['delete_file'])) {
        $fileId = (int) $_POST['file_id'];
        $stmt = $pdo->prepare('SELECT * FROM course_files WHERE id = ? AND course_id = ?');
        $stmt->execute([$fileId, $courseId]);
        $row = $stmt->fetch();
        if ($row) {
            $full = $cfg['upload_dir'] . '/' . $row['file_path'];
            if (is_file($full)) {
                unlink($full);
            }
            $pdo->prepare('DELETE FROM course_files WHERE id = ?')->execute([$fileId]);
            flash('success', 'File deleted.');
        }
        redirect("files.php?course_id={$courseId}");
    }
}

$files = $pdo->prepare('SELECT * FROM course_files WHERE course_id = ? ORDER BY created_at DESC');
$files->execute([$courseId]);
$files = $files->fetchAll();

$itemFiles = $pdo->prepare("SELECT * FROM module_items mi JOIN modules m ON m.id = mi.module_id WHERE m.course_id = ? AND mi.item_type = 'file' ORDER BY mi.created_at DESC");
$itemFiles->execute([$courseId]);
$itemFiles = $itemFiles->fetchAll();

render_head('Files');
render_app_shell_start($user, 'courses', "course.php?id={$courseId}");
render_course_shell_start($course, 'files', $courseId);
render_course_header('Course Files', course_header_actions($pdo, $courseId, $user));
?>
<div class="course-page">
  <?php if ($canManage): ?>
    <?php
    $storageFolder = 'uploads/course_' . $courseId . '/';
    $uploadsOk = upload_dir_writable($cfg);
    ?>
    <div class="content-box" style="margin-bottom:24px;background:#fafafa;">
      <h3 style="margin:0 0 12px;font-weight:600;">Upload material</h3>
      <p style="font-size:13px;color:#71717a;margin:0 0 16px;line-height:1.5;">
        Files are saved on the server in <code><?= e($storageFolder) ?></code> and listed below.
        They are <strong>not</strong> added to course modules automatically — use <a href="<?= url('admin/modules.php?course_id=' . $courseId) ?>">Modules</a> to link files for students.
      </p>
      <?php if (!$uploadsOk): ?>
        <div class="flash flash-error" style="border-radius:6px;margin-bottom:12px;">
          The <code>uploads/</code> folder is not writable. On XAMPP run <code>chmod -R 777 uploads</code> inside your project folder, then try again.
        </div>
      <?php endif; ?>
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="upload_file" value="1">
        <?php render_csrf_field(); ?>
        <div class="form-group"><input type="text" name="title" placeholder="Display title (optional)"></div>
        <div class="form-group"><input type="file" name="material" required></div>
        <button class="btn" type="submit"<?= $uploadsOk ? '' : ' disabled' ?>>Upload</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($files): ?>
    <h2 class="section-title">Uploaded files</h2>
    <div class="panel">
      <?php foreach ($files as $f): ?>
        <div class="panel-row" style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
          <div>
            <strong><?= e($f['title']) ?></strong>
            <div style="font-size:12px;color:#71717a;"><?= e(format_datetime($f['created_at'])) ?></div>
          </div>
          <div style="display:flex;gap:8px;">
            <a class="btn btn-sm btn-outline" href="<?= url('download.php?file_id=' . $f['id']) ?>">Download</a>
            <?php if ($canManage): ?>
              <form method="post" onsubmit="return confirm('Delete this file?');">
                <input type="hidden" name="delete_file" value="1">
                <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>">
                <button class="btn btn-sm btn-outline" type="submit">Delete</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php elseif (!$itemFiles): ?>
    <?php render_empty_state(
        'No files yet.',
        $canManage ? 'Upload course materials above or attach files to modules.' : 'Your instructor has not shared any files yet.',
        $canManage ? [['label' => 'Edit modules', 'href' => url('admin/modules.php?course_id=' . $courseId), 'primary' => true]] : []
    ); ?>
  <?php else: ?>
    <p style="font-size:14px;color:#71717a;">No direct uploads yet — see module files below.</p>
  <?php endif; ?>

  <?php if ($itemFiles): ?>
    <h2 class="section-title spaced">Module files</h2>
    <div class="panel">
      <?php foreach ($itemFiles as $it): ?>
        <div class="panel-row" style="display:flex;justify-content:space-between;align-items:center;">
          <strong><?= e($it['title']) ?></strong>
          <a class="btn btn-sm btn-outline" href="<?= url('download.php?item_id=' . $it['id']) ?>">Download</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php
render_course_shell_end();
render_app_shell_end();