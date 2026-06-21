<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/notifications.php';
$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all_read'])) {
    mark_all_notifications_read($pdo, $user['id']);
    flash('success', 'All notifications marked read.');
    redirect('/notifications.php');
}

if (isset($_GET['read'])) {
    mark_notification_read($pdo, (int) $_GET['read'], $user['id']);
    $stmt = $pdo->prepare('SELECT link FROM notifications WHERE id = ? AND user_id = ?');
    $stmt->execute([(int) $_GET['read'], $user['id']]);
    $link = $stmt->fetchColumn();
    $dest = notification_follow_path(is_string($link) ? $link : null);
    if (str_starts_with($dest, 'http://') || str_starts_with($dest, 'https://')) {
        header('Location: ' . $dest);
        exit;
    }
    redirect($dest);
}

$list = notifications_for_user($pdo, $user['id'], 50);

render_head('Notifications');
render_app_shell_start($user, 'dashboard', '/dashboard.php');
render_page_header('Notifications', null);
?>
<div class="page-body" style="max-width:640px;">
  <?php if ($list): ?>
    <form method="post" style="margin-bottom:16px;">
      <input type="hidden" name="mark_all_read" value="1">
      <?php render_csrf_field(); ?>
      <button class="btn btn-sm btn-outline" type="submit">Mark all read</button>
    </form>
  <?php endif; ?>

  <div class="panel">
    <?php foreach ($list as $n): ?>
      <a class="panel-row notification-row<?= $n['read_at'] ? '' : ' notification-unread' ?>" href="<?= url('notifications.php?read=' . (int)$n['id']) ?>">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
          <div>
            <span class="notification-kind-badge"><?= e(notification_kind_label((string) $n['kind'])) ?></span>
            <strong><?= e($n['title']) ?></strong>
          </div>
          <span style="font-size:11px;color:#71717a;white-space:nowrap;" title="<?= e(format_datetime($n['created_at'], 'M j, Y g:ia')) ?>"><?= e(time_ago($n['created_at'])) ?></span>
        </div>
        <?php if ($n['course_code']): ?>
          <span class="search-result-meta"><?= e($n['course_code']) ?></span>
        <?php endif; ?>
        <?php if ($n['body']): ?>
          <p style="margin:4px 0 0;font-size:14px;color:#52525b;"><?= e($n['body']) ?></p>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
    <?php if (!$list): ?>
      <div class="panel-row" style="color:#71717a;text-align:center;">No notifications yet.</div>
    <?php endif; ?>
  </div>
</div>
<?php render_app_shell_end(); ?>