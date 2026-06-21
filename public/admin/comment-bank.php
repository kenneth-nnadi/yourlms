<?php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/admin_helpers.php';
$user = require_teach_access($pdo);

$courses = teach_admin_courses($pdo, $user);
$courseId = (int) ($_GET['course_id'] ?? ($courses[0]['id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $courseId = (int) ($_POST['course_id'] ?? 0);
    if (isset($_POST['add_comment'])) {
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        if ($title && $body) {
            $pdo->prepare('INSERT INTO comment_bank (course_id, user_id, title, body) VALUES (?, ?, ?, ?)')
                ->execute([$courseId ?: null, $user['id'], $title, $body]);
            flash('success', 'Comment saved to bank.');
        }
    }
    if (isset($_POST['delete_id'])) {
        $pdo->prepare('DELETE FROM comment_bank WHERE id = ? AND user_id = ?')->execute([(int) $_POST['delete_id'], $user['id']]);
        flash('success', 'Comment removed.');
    }
    redirect('/admin/comment-bank.php?course_id=' . $courseId);
}

$stmt = $pdo->prepare('SELECT * FROM comment_bank WHERE user_id = ? AND (course_id IS NULL OR course_id = ?) ORDER BY title');
$stmt->execute([$user['id'], $courseId ?: 0]);
$comments = $stmt->fetchAll();

render_head('Comment bank');
render_app_shell_start($user, 'admin', '/admin/index.php');
?>
<?php render_page_header('Comment bank', 'Grading'); ?>
<div class="page-body" style="max-width:640px;">
  <form method="get" style="margin-bottom:20px;">
    <select name="course_id" onchange="this.form.submit()">
      <option value="0">All courses</option>
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $courseId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php foreach ($comments as $c): ?>
    <div class="panel-row" style="display:flex;justify-content:space-between;gap:12px;">
      <div><strong><?= e($c['title']) ?></strong><p style="margin:4px 0 0;font-size:14px;color:#52525b;"><?= e($c['body']) ?></p></div>
      <form method="post">
        <input type="hidden" name="course_id" value="<?= $courseId ?>">
        <input type="hidden" name="delete_id" value="<?= (int)$c['id'] ?>">
        <button class="btn btn-sm btn-outline" type="submit">Delete</button>
      </form>
    </div>
  <?php endforeach; ?>

  <div class="content-box" style="margin-top:24px;background:#fafafa;">
    <h3 style="margin:0 0 12px;">Add reusable comment</h3>
    <form method="post">
      <input type="hidden" name="course_id" value="<?= $courseId ?>">
      <input type="hidden" name="add_comment" value="1">
      <div class="form-group"><label>Short title</label><input name="title" required placeholder="e.g. Good thesis"></div>
      <div class="form-group"><label>Comment text</label><textarea name="body" rows="3" required></textarea></div>
      <button class="btn" type="submit">Save</button>
    </form>
  </div>
</div>
<?php render_app_shell_end(); ?>