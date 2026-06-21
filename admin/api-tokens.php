<?php
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api.php';
$user = require_teach_access($pdo);

if (!user_is_site_instructor($user)) {
    flash('error', 'Only site instructors can manage API tokens.');
    redirect('/admin/index.php');
}

$newToken = $_SESSION['new_api_token'] ?? null;
unset($_SESSION['new_api_token']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_token'])) {
        $label = trim($_POST['label'] ?? 'API token');
        $created = api_create_token($pdo, $user['id'], $label);
        $_SESSION['new_api_token'] = $created['token'];
        flash('success', 'Token created. Copy it now — it will not be shown again.');
        redirect('/admin/api-tokens.php');
    }
    if (isset($_POST['revoke_id'])) {
        $pdo->prepare('DELETE FROM api_tokens WHERE id = ? AND user_id = ?')->execute([(int) $_POST['revoke_id'], $user['id']]);
        flash('success', 'Token revoked.');
        redirect('/admin/api-tokens.php');
    }
}

$tokens = $pdo->prepare('SELECT * FROM api_tokens WHERE user_id = ? ORDER BY created_at DESC');
$tokens->execute([$user['id']]);
$tokens = $tokens->fetchAll();

render_head('API tokens');
render_app_shell_start($user, 'admin', '/admin/index.php');
?>
<?php render_page_header('REST API tokens', 'Integrations'); ?>
<div class="page-body" style="max-width:720px;">
  <p style="color:#71717a;">Bearer tokens for <code>/api/index.php?route=…</code>. Endpoints: <code>courses</code>, <code>courses/{id}</code>, <code>courses/{id}/students</code>, <code>courses/{id}/grades</code>.</p>

  <?php if ($newToken): ?>
    <div class="content-box" style="background:#f0fdf4;margin-bottom:20px;">
      <strong>New token (copy now):</strong>
      <code style="display:block;margin-top:8px;word-break:break-all;"><?= e($newToken) ?></code>
    </div>
  <?php endif; ?>

  <div class="content-box" style="margin-bottom:24px;background:#fafafa;">
    <form method="post">
      <input type="hidden" name="create_token" value="1">
      <div class="form-group"><label>Label</label><input name="label" value="Integration" required></div>
      <button class="btn" type="submit">Generate token</button>
    </form>
  </div>

  <?php foreach ($tokens as $t): ?>
    <div class="panel-row" style="display:flex;justify-content:space-between;">
      <span><code><?= e($t['token_prefix']) ?>…</code> — <?= e($t['label']) ?> <span style="color:#71717a;font-size:12px;"><?= e(format_datetime($t['created_at'])) ?></span></span>
      <form method="post">
        <input type="hidden" name="revoke_id" value="<?= (int)$t['id'] ?>">
        <button class="btn btn-sm btn-outline" type="submit">Revoke</button>
      </form>
    </div>
  <?php endforeach; ?>
</div>
<?php render_app_shell_end(); ?>