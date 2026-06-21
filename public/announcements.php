<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/notifications.php';
$user = require_login();

$courseId = (int) ($_GET['course_id'] ?? 0);
$course = require_course_access($pdo, $courseId, $user);
$canManage = user_can_manage_course_as_staff($pdo, $courseId, $user);
$editId = (int) ($_GET['edit'] ?? 0);
$highlightId = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    if (isset($_POST['create_announcement'])) {
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $bodyFormat = content_format_value($_POST['body_format'] ?? 'text');
        $published = isset($_POST['published']) ? 1 : 0;
        if ($title && $body) {
            $pdo->prepare('INSERT INTO announcements (course_id, title, body, body_format, published, created_by) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$courseId, $title, $body, $bodyFormat, $published, $user['id']]);
            if ($published) {
                notify_course_students(
                    $pdo,
                    $courseId,
                    'announcement',
                    'New announcement: ' . $title,
                    mb_substr(strip_tags($body), 0, 200),
                    "announcements.php?course_id={$courseId}&id=" . (int) $pdo->lastInsertId(),
                    (int) $user['id']
                );
            }
            flash('success', 'Announcement posted.');
        } else {
            flash('error', 'Title and body are required.');
        }
        redirect("announcements.php?course_id={$courseId}");
    }

    if (isset($_POST['update_announcement'])) {
        $id = (int) $_POST['announcement_id'];
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        $bodyFormat = content_format_value($_POST['body_format'] ?? 'text');
        $published = isset($_POST['published']) ? 1 : 0;
        if ($title && $body) {
            $pdo->prepare('UPDATE announcements SET title = ?, body = ?, body_format = ?, published = ? WHERE id = ? AND course_id = ?')
                ->execute([$title, $body, $bodyFormat, $published, $id, $courseId]);
            flash('success', 'Announcement updated.');
        }
        redirect("announcements.php?course_id={$courseId}");
    }

    if (isset($_POST['delete_announcement'])) {
        $pdo->prepare('DELETE FROM announcements WHERE id = ? AND course_id = ?')
            ->execute([(int) $_POST['announcement_id'], $courseId]);
        flash('success', 'Announcement deleted.');
        redirect("announcements.php?course_id={$courseId}");
    }

    if (isset($_POST['toggle_publish'])) {
        $id = (int) $_POST['announcement_id'];
        $stmt = $pdo->prepare('SELECT published FROM announcements WHERE id = ? AND course_id = ?');
        $stmt->execute([$id, $courseId]);
        $current = (int) $stmt->fetchColumn();
        $next = $current ? 0 : 1;
        $pdo->prepare('UPDATE announcements SET published = ? WHERE id = ? AND course_id = ?')
            ->execute([$next, $id, $courseId]);
        if ($next) {
            $row = $pdo->prepare('SELECT title, body FROM announcements WHERE id = ?');
            $row->execute([$id]);
            $ann = $row->fetch();
            if ($ann) {
                notify_course_students(
                    $pdo,
                    $courseId,
                    'announcement',
                    'Announcement: ' . $ann['title'],
                    mb_substr($ann['body'], 0, 200),
                    "announcements.php?course_id={$courseId}&id={$id}",
                    (int) $user['id']
                );
            }
        }
        flash('success', $current ? 'Announcement unpublished.' : 'Announcement published.');
        redirect("announcements.php?course_id={$courseId}");
    }
}

$sql = 'SELECT a.*, u.full_name AS author_name FROM announcements a LEFT JOIN users u ON u.id = a.created_by WHERE a.course_id = ?';
if (!$canManage) {
    $sql .= ' AND a.published = 1';
}
$sql .= ' ORDER BY a.created_at DESC';
$anns = $pdo->prepare($sql);
$anns->execute([$courseId]);
$announcements = $anns->fetchAll();

$editing = null;
if ($editId && $canManage) {
    $es = $pdo->prepare('SELECT * FROM announcements WHERE id = ? AND course_id = ?');
    $es->execute([$editId, $courseId]);
    $editing = $es->fetch() ?: null;
}

render_head('Announcements');
render_app_shell_start($user, 'courses', "course.php?id={$courseId}");
render_course_shell_start($course, 'announcements', $courseId);
render_course_header('Announcements', course_header_actions($pdo, $courseId, $user));
?>
<div class="course-page">
  <?php if ($canManage): ?>
    <div class="content-box" style="margin-bottom:32px;background:#fafafa;">
      <h3 style="margin:0 0 12px;font-weight:600;"><?= $editing ? 'Edit announcement' : 'New announcement' ?></h3>
      <form method="post">
        <?php if ($editing): ?>
          <input type="hidden" name="update_announcement" value="1">
          <input type="hidden" name="announcement_id" value="<?= (int)$editing['id'] ?>">
        <?php else: ?>
          <input type="hidden" name="create_announcement" value="1">
        <?php endif; ?>
        <div class="form-group"><input type="text" name="title" placeholder="Title" required value="<?= e($editing['title'] ?? '') ?>"></div>
        <div class="form-group">
          <label>Body</label>
          <textarea name="body" rows="4" placeholder="Body…" data-rich-editor data-rich-required><?= e($editing['body'] ?? '') ?></textarea>
          <input type="hidden" name="body_format" value="<?= e(($editing['body_format'] ?? '') === 'html' ? 'html' : 'text') ?>" data-rich-format>
        </div>
        <label style="display:flex;align-items:center;gap:8px;font-size:14px;margin-bottom:12px;">
          <input type="checkbox" name="published" value="1" <?= ($editing['published'] ?? 1) ? 'checked' : '' ?>>
          Publish immediately
        </label>
        <button class="btn" type="submit"><?= $editing ? 'Save changes' : 'Post' ?></button>
        <?php if ($editing): ?>
          <a class="btn btn-outline" href="<?= url("announcements.php?course_id={$courseId}") ?>" style="margin-left:8px;">Cancel</a>
        <?php endif; ?>
      </form>
    </div>
  <?php endif; ?>

  <?php foreach ($announcements as $a): ?>
    <article id="announcement-<?= (int)$a['id'] ?>" class="post-card <?= !$a['published'] ? 'item-unpublished' : '' ?><?= $highlightId === (int)$a['id'] ? ' post-card-highlight' : '' ?>">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;">
        <h3 style="margin:0;font-size:1.1rem;font-weight:600;">
          <?= e($a['title']) ?>
          <?php if ($canManage && !$a['published']): ?><span class="unpublished-label">draft</span><?php endif; ?>
        </h3>
        <span class="post-time" title="<?= e(format_datetime($a['created_at'], 'M j, Y g:ia')) ?>"><?= e(time_ago($a['created_at'])) ?></span>
      </div>
      <div class="post-time"><?= e($a['author_name'] ?? 'Instructor') ?></div>
      <div class="post-body user-html-content" style="margin-top:12px;"><?php render_rich_content($a['body'], $a['body_format'] ?? 'text'); ?></div>
      <?php if ($canManage): ?>
        <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;">
          <a class="btn btn-sm btn-outline" href="<?= url("announcements.php?course_id={$courseId}&edit={$a['id']}") ?>">Edit</a>
          <form method="post" style="display:inline;">
            <input type="hidden" name="toggle_publish" value="1">
            <input type="hidden" name="announcement_id" value="<?= (int)$a['id'] ?>">
            <button class="btn btn-sm btn-outline" type="submit"><?= $a['published'] ? 'Unpublish' : 'Publish' ?></button>
          </form>
          <form method="post" style="display:inline;" onsubmit="return confirm('Delete this announcement?');">
            <input type="hidden" name="delete_announcement" value="1">
            <input type="hidden" name="announcement_id" value="<?= (int)$a['id'] ?>">
            <button class="btn btn-sm btn-outline" type="submit">Delete</button>
          </form>
        </div>
      <?php endif; ?>
    </article>
  <?php endforeach; ?>
  <?php if (!$announcements): ?>
    <?php render_empty_state(
        'No announcements yet.',
        $canManage ? 'Post an update for your class using the form above.' : 'Your instructor has not posted any announcements.'
    ); ?>
  <?php endif; ?>
</div>
<?php
if ($canManage) {
    require_once dirname(__DIR__) . '/includes/rich_editor.php';
    render_rich_editor_assets();
}
render_course_shell_end();
render_app_shell_end();