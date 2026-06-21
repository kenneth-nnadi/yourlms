<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/mail.php';

if (current_user()) {
    redirect('/profile.php');
}

$resetLink = null;
$emailSent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rateError = check_password_reset_rate_limit();
    if ($rateError) {
        flash('error', $rateError);
        redirect('/forgot-password.php');
    }
    record_password_reset_attempt();
    $email = strtolower(trim($_POST['email'] ?? ''));
    $stmt = $pdo->prepare('SELECT id, full_name, admin_managed_password FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if ($row && empty($row['admin_managed_password'])) {
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + 3600);
        $pdo->prepare('UPDATE users SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?')
            ->execute([$token, $expires, $row['id']]);
        $resetLink = url('reset-password.php?token=' . $token);
        $appName = $config['app_name'] ?? 'YourLMS';
        $body = "Hello {$row['full_name']},\n\n";
        $body .= "You requested a password reset for {$appName}.\n\n";
        $body .= "Reset your password using this link (valid for 1 hour):\n{$resetLink}\n\n";
        $body .= "If you did not request this, you can ignore this email.\n";
        $emailSent = send_mail($config, $email, "{$appName} password reset", $body);
    }
    if ($emailSent) {
        flash('success', 'If that email is registered, a reset link has been sent.');
    } else {
        flash('success', 'If that email is registered, a reset link has been generated.');
    }
}

render_head('Forgot password');
render_auth_shell_start('/login.php');
?>
<div class="auth-page">
  <div class="auth-card">
    <h1>Reset password</h1>
    <p class="auth-tagline">Enter your email to receive a reset link.</p>
    <?php render_flash(); ?>
    <form method="post">
      <div class="form-group"><label>Email</label><input type="email" name="email" required></div>
      <button class="btn" type="submit" style="width:100%;">Send reset link</button>
    </form>
    <?php if ($resetLink && !$emailSent): ?>
      <div class="content-box" style="margin-top:16px;font-size:13px;">
        <strong>Demo reset link</strong> (SMTP not configured in <code>config.php</code>):<br>
        <a href="<?= e($resetLink) ?>"><?= e($resetLink) ?></a>
      </div>
    <?php endif; ?>
    <p style="text-align:center;margin-top:16px;font-size:14px;"><a href="<?= url('login.php') ?>">Back to login</a></p>
  </div>
</div>
<?php render_auth_shell_end(); ?>