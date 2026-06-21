<?php
require __DIR__ . '/includes/bootstrap.php';

$token = $_GET['token'] ?? $_POST['token'] ?? '';
$error = null;

$stmt = $pdo->prepare('SELECT * FROM users WHERE password_reset_token = ? AND password_reset_expires > ' . db_now_sql());
$stmt->execute([$token]);
$account = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $account) {
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if (strlen($new) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $pdo->prepare('UPDATE users SET password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $account['id']]);
        flash('success', 'Password reset. You can log in now.');
        redirect('/login.php');
    }
}

render_head('Reset password');
render_auth_shell_start('/login.php');
?>
<div class="auth-page">
  <div class="auth-card">
    <h1>Set new password</h1>
    <?php if (!$account): ?>
      <div class="flash flash-error" style="border-radius:6px;">Invalid or expired reset link.</div>
      <p style="text-align:center;margin-top:16px;"><a href="<?= url('forgot-password.php') ?>">Request a new link</a></p>
    <?php else: ?>
      <?php if ($error): ?>
        <div class="flash flash-error" style="border-radius:6px;"><?= e($error) ?></div>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="form-group"><label>New password</label><input type="password" name="new_password" required minlength="6"></div>
        <div class="form-group"><label>Confirm password</label><input type="password" name="confirm_password" required minlength="6"></div>
        <button class="btn" type="submit" style="width:100%;">Reset password</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php render_auth_shell_end(); ?>