<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/mail.php';
require_once dirname(__DIR__) . '/includes/branding_store.php';
$user = require_login();

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$account = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        $fullName = trim($_POST['full_name'] ?? '');
        if ($fullName !== '') {
            $pdo->prepare('UPDATE users SET full_name = ? WHERE id = ?')->execute([$fullName, $user['id']]);
            $_SESSION['user']['full_name'] = $fullName;
            flash('success', 'Profile updated.');
        }
        redirect('/profile.php');
    }

    if (isset($_POST['change_password'])) {
        if (user_has_admin_managed_password($account)) {
            flash('error', 'Your password is managed by an instructor. Ask them to reset it for you.');
            redirect('/profile.php');
        }
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (!password_verify($current, $account['password_hash'])) {
            flash('error', 'Current password is incorrect.');
        } elseif (strlen($new) < 6) {
            flash('error', 'New password must be at least 6 characters.');
        } elseif ($new !== $confirm) {
            flash('error', 'New passwords do not match.');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
            flash('success', 'Password changed.');
        }
        redirect('/profile.php');
    }

    if (user_is_site_instructor($user) && isset($_POST['upload_logo'])) {
        $err = store_branding_logo($config, $_FILES['app_logo'] ?? []);
        if ($err) {
            flash('error', $err);
        } else {
            $config['app_logo'] = load_branding_overrides()['app_logo'] ?? null;
            flash('success', 'Logo updated. It appears in the header and login page.');
        }
        redirect('/profile.php');
    }

    if (user_is_site_instructor($user) && isset($_POST['remove_logo'])) {
        $rel = app_logo_relative_path();
        if ($rel) {
            @unlink($config['upload_dir'] . '/' . $rel);
        }
        remove_branding_override_key('app_logo');
        flash('success', 'Custom logo removed — default YourLMS branding restored.');
        redirect('/profile.php');
    }

    if (user_is_site_instructor($user) && isset($_POST['send_test_email'])) {
        $to = trim($_POST['test_email'] ?? $account['email']);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid email address.');
        } elseif (!mail_is_enabled($config)) {
            flash('error', 'SMTP is not enabled. Set smtp.enabled and host in config.local.php.');
        } elseif (send_mail($config, $to, config()['app_name'] . ' test email', "This is a test message from " . config()['app_name'] . ".\n\nIf you received this, SMTP is working.")) {
            flash('success', 'Test email sent to ' . $to . '.');
        } else {
            flash('error', 'SMTP send failed. Check host, port, credentials, and firewall.');
        }
        redirect('/profile.php');
    }
}

render_head('Profile');
render_app_shell_start($user, 'dashboard', '/dashboard.php');
render_page_header('Profile & settings', user_login_label($account) ?: 'Account');
?>
<div class="page-body" style="max-width:560px;">
  <div class="content-box" style="margin-bottom:24px;">
    <h3 style="margin:0 0 16px;">Account</h3>
    <form method="post">
      <input type="hidden" name="update_profile" value="1">
      <div class="form-group"><label>Full name</label><input name="full_name" required value="<?= e($account['full_name']) ?>"></div>
      <?php if (!empty($account['username'])): ?>
        <div class="form-group"><label>Username</label><input value="<?= e($account['username']) ?>" disabled></div>
      <?php endif; ?>
      <div class="form-group"><label>Email</label><input value="<?= e($account['email'] ?? '') ?>" disabled placeholder="Not set"></div>
      <div class="form-group"><label>Role</label><input value="<?= e(account_role_label($account['role'])) ?>" disabled></div>
      <button class="btn" type="submit">Save profile</button>
    </form>
  </div>

  <div class="content-box">
    <h3 style="margin:0 0 16px;">Change password</h3>
    <?php if (user_has_admin_managed_password($account)): ?>
      <p class="text-muted" style="font-size:14px;margin:0;line-height:1.6;">
        Your password is set by an instructor. Contact them if you need it changed — self-service password change and email reset are not available for username-only accounts.
      </p>
    <?php else: ?>
      <form method="post">
        <input type="hidden" name="change_password" value="1">
        <div class="form-group"><label>Current password</label><input type="password" name="current_password" required></div>
        <div class="form-group"><label>New password</label><input type="password" name="new_password" required minlength="6"></div>
        <div class="form-group"><label>Confirm new password</label><input type="password" name="confirm_password" required minlength="6"></div>
        <button class="btn" type="submit">Change password</button>
      </form>
      <p class="text-muted" style="font-size:13px;margin:16px 0 0;">
        Forgot your password? <a href="<?= url('forgot-password.php') ?>">Request a reset link</a>
      </p>
    <?php endif; ?>
  </div>

  <?php if (user_is_site_instructor($user)): ?>
    <div class="content-box" style="margin-top:24px;">
      <h3 style="margin:0 0 12px;">App branding</h3>
      <p style="font-size:14px;color:#52525b;margin:0 0 16px;line-height:1.6;">
        Customize how <?= e(config()['app_name']) ?> looks. Name, tagline, and colors are set in <code>config.php</code>.
        The bundled <strong>YourLMS</strong> logo is the default — upload below only if you want to replace it.
      </p>
      <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">
        <?php render_brand_mark('profile-brand-preview'); ?>
        <div style="font-size:13px;color:#71717a;">
          <?php if (app_has_custom_uploaded_logo()): ?>
            Custom uploaded logo (replaces default)
          <?php elseif (app_default_logo_relative_path()): ?>
            Default YourLMS logo
          <?php elseif (app_has_emoji_icon()): ?>
            Using emoji from <code>app_icon</code>
          <?php else: ?>
            Using initials from <code>app_name</code>
          <?php endif; ?>
        </div>
      </div>
      <form method="post" enctype="multipart/form-data" style="margin-bottom:12px;">
        <input type="hidden" name="upload_logo" value="1">
        <div class="form-group">
          <label>Logo image</label>
          <input type="file" name="app_logo" accept="image/png,image/jpeg,image/webp,image/svg+xml">
          <p style="font-size:12px;color:#71717a;margin:8px 0 0;">PNG, JPEG, WebP, or SVG · max 2 MB · square works best</p>
        </div>
        <button class="btn" type="submit">Upload logo</button>
      </form>
      <?php if (app_has_custom_uploaded_logo()): ?>
        <form method="post" onsubmit="return confirm('Remove your uploaded logo and restore the default YourLMS branding?');">
          <input type="hidden" name="remove_logo" value="1">
          <button class="btn btn-outline" type="submit">Restore default logo</button>
        </form>
      <?php endif; ?>
      <table class="branding-hint-table" style="width:100%;margin-top:20px;font-size:13px;border-collapse:collapse;">
        <tr><th style="text-align:left;padding:8px 0;color:#71717a;font-weight:600;">config.php key</th><th style="text-align:left;padding:8px 0;color:#71717a;font-weight:600;">Purpose</th></tr>
        <tr><td style="padding:6px 0;"><code>app_name</code></td><td style="padding:6px 0;color:#52525b;">Header, login, page titles, emails</td></tr>
        <tr><td style="padding:6px 0;"><code>app_tagline</code></td><td style="padding:6px 0;color:#52525b;">Login subtitle</td></tr>
        <tr><td style="padding:6px 0;"><code>app_icon</code></td><td style="padding:6px 0;color:#52525b;">Emoji logo when no image uploaded</td></tr>
        <tr><td style="padding:6px 0;"><code>theme.accent</code></td><td style="padding:6px 0;color:#52525b;">Buttons, links, favicon</td></tr>
        <tr><td style="padding:6px 0;"><code>theme.nav</code></td><td style="padding:6px 0;color:#52525b;">Header background</td></tr>
        <tr><td style="padding:6px 0;"><code>theme.enable_dark_mode</code></td><td style="padding:6px 0;color:#52525b;">Show the ◐ theme toggle</td></tr>
      </table>
    </div>

    <div class="content-box" style="margin-top:24px;">
      <h3 style="margin:0 0 12px;">Email (SMTP)</h3>
      <p style="font-size:14px;color:#52525b;margin:0 0 8px;">
        Password reset emails are <?= mail_is_enabled($config) ? '<strong style="color:#16a34a;">enabled</strong>' : '<strong>disabled</strong> (demo link shown on forgot-password page)' ?>.
        <?php if (request_is_https()): ?>
          · Session cookies are <strong style="color:#16a34a;">secure</strong> (HTTPS detected).
        <?php elseif (($config['session']['auto_secure'] ?? true)): ?>
          · Session cookies use <code>auto_secure</code> — they become secure when served over HTTPS.
        <?php endif; ?>
      </p>
      <p style="font-size:13px;color:#71717a;margin:0 0 16px;">
        Copy <code>config.local.php.example</code> to <code>config.local.php</code> and set <code>smtp.enabled</code>, host, user, and password.
        When enabled, students also receive email for graded assignments, quizzes, and discussions (in-app notifications always appear).
      </p>
      <form method="post">
        <input type="hidden" name="send_test_email" value="1">
        <div class="form-group">
          <label>Send test email to</label>
          <input type="email" name="test_email" value="<?= e($account['email']) ?>" required>
        </div>
        <button class="btn btn-outline" type="submit" <?= mail_is_enabled($config) ? '' : 'disabled title="Enable SMTP in config.local.php first"' ?>>Send test email</button>
      </form>
    </div>
  <?php endif; ?>
</div>
<?php render_app_shell_end(); ?>