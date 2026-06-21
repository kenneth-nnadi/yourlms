<?php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/admin_helpers.php';
$user = require_teach_access($pdo);

$courses = teach_admin_courses($pdo, $user);
$courseId = (int) ($_GET['course_id'] ?? ($courses[0]['id'] ?? 0));
if ($courseId) {
    require_course_content_editor($pdo, $courseId, $user);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $courseId = (int) ($_POST['course_id'] ?? 0);
    require_course_content_editor($pdo, $courseId, $user);
    if (isset($_POST['add_tool'])) {
        $pdo->prepare('INSERT INTO external_tools (course_id, name, launch_url, consumer_key, shared_secret, custom_params) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([
                $courseId,
                trim($_POST['name'] ?? ''),
                trim($_POST['launch_url'] ?? ''),
                trim($_POST['consumer_key'] ?? ''),
                trim($_POST['shared_secret'] ?? ''),
                trim($_POST['custom_params'] ?? '') ?: null,
            ]);
        flash('success', 'External tool added.');
    }
    if (isset($_POST['delete_id'])) {
        $pdo->prepare('DELETE FROM external_tools WHERE id = ? AND course_id = ?')->execute([(int) $_POST['delete_id'], $courseId]);
        flash('success', 'Tool removed.');
    }
    redirect('/admin/lti-tools.php?course_id=' . $courseId);
}

$tools = [];
if ($courseId) {
    $stmt = $pdo->prepare('SELECT * FROM external_tools WHERE course_id = ? ORDER BY name');
    $stmt->execute([$courseId]);
    $tools = $stmt->fetchAll();
}

render_head('External tools');
render_app_shell_start($user, 'admin', '/admin/index.php');
?>
<?php render_page_header('LTI / external tools', 'Teach'); ?>
<div class="page-body" style="max-width:720px;">
  <p style="color:#71717a;">Basic LTI 1.0 launches for external learning tools. Add the tool in your provider, then launch from a course.</p>
  <form method="get" style="margin-bottom:20px;">
    <select name="course_id" onchange="this.form.submit()">
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $courseId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php foreach ($tools as $t): ?>
    <div class="panel-row" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
      <div>
        <strong><?= e($t['name']) ?></strong>
        <div style="font-size:12px;color:#71717a;"><?= e($t['launch_url']) ?></div>
      </div>
      <div style="display:flex;gap:8px;">
        <a class="btn btn-sm" href="<?= url('lti-launch.php?course_id=' . $courseId . '&tool_id=' . (int)$t['id']) ?>" target="_blank">Launch</a>
        <form method="post">
          <input type="hidden" name="course_id" value="<?= $courseId ?>">
          <input type="hidden" name="delete_id" value="<?= (int)$t['id'] ?>">
          <button class="btn btn-sm btn-outline" type="submit">Delete</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="content-box" style="margin-top:24px;background:#fafafa;">
    <h3 style="margin:0 0 12px;">Add tool</h3>
    <form method="post">
      <input type="hidden" name="course_id" value="<?= $courseId ?>">
      <input type="hidden" name="add_tool" value="1">
      <div class="form-group"><label>Name</label><input name="name" required></div>
      <div class="form-group"><label>Launch URL</label><input name="launch_url" type="url" required placeholder="https://…"></div>
      <div class="form-group"><label>Consumer key</label><input name="consumer_key" required></div>
      <div class="form-group"><label>Shared secret</label><input name="shared_secret" required></div>
      <div class="form-group"><label>Custom params (key=value per line)</label><textarea name="custom_params" rows="2"></textarea></div>
      <button class="btn" type="submit">Add tool</button>
    </form>
  </div>
</div>
<?php render_app_shell_end(); ?>