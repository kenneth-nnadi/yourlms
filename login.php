<?php
require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('/dashboard.php');
}

$error = null;
$allowSignup = self_registration_allowed();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'login';
    if ($action === 'login') {
        $rateError = check_login_rate_limit();
        if ($rateError) {
            $error = $rateError;
        } elseif (login_user($pdo, $_POST['email'] ?? '', $_POST['password'] ?? '')) {
            redirect('/dashboard.php');
        } else {
            record_login_failure();
            $error = 'Invalid email or password.';
        }
    } elseif (!$allowSignup) {
        $error = 'Self-registration is disabled. Ask an instructor for an account.';
    } else {
        $error = register_user(
            $pdo,
            $_POST['email'] ?? '',
            $_POST['password'] ?? '',
            $_POST['full_name'] ?? '',
            $_POST['role'] ?? 'student'
        );
        if ($error === null) {
            flash('success', 'Account created! Welcome.');
            redirect('/dashboard.php');
        }
    }
}

$cfg = config();
render_head('Sign in');
render_auth_shell_start('/login.php');
?>
<div class="auth-page" id="main-content" tabindex="-1">
  <div class="auth-card">
    <div class="auth-logo-wrap">
      <?php render_brand_mark('auth-logo-mark'); ?>
    </div>
    <h1><?= e($cfg['app_name']) ?></h1>
    <p class="auth-tagline"><?= e($cfg['app_tagline']) ?></p>

    <?php if ($error): ?>
      <div class="flash flash-error" style="border-radius:6px;margin-bottom:16px;"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($allowSignup): ?>
    <div class="tabs" role="tablist">
      <button type="button" class="tab active" data-tab="signin">Log in</button>
      <button type="button" class="tab" data-tab="signup">Create account</button>
    </div>
    <?php endif; ?>

    <div id="signin" class="tab-panel active">
      <form method="post">
        <input type="hidden" name="action" value="login">
        <div class="form-group">
          <label>Email or username</label>
          <input type="text" name="email" required autocomplete="username" value="<?= e($_POST['email'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label>Password</label>
          <input type="password" name="password" required>
        </div>
        <button class="btn" type="submit" style="width:100%;">Log in</button>
      </form>
      <p style="text-align:center;margin-top:12px;font-size:13px;"><a href="<?= url('forgot-password.php') ?>">Forgot password?</a></p>
      <p style="text-align:center;margin-top:8px;font-size:12px;color:#94a3b8;">
        <a href="<?= url('getting-started.php') ?>">Getting started guide</a>
        · <a href="<?= url('help.php?doc=install') ?>">Install help</a>
      </p>
    </div>

    <?php if ($allowSignup): ?>
    <div id="signup" class="tab-panel">
      <form method="post">
        <input type="hidden" name="action" value="register">
        <div class="form-group">
          <label>Full name</label>
          <input type="text" name="full_name" required>
        </div>
        <div class="form-group">
          <label>Email</label>
          <input type="email" name="email" required>
        </div>
        <div class="form-group">
          <label>Password</label>
          <input type="password" name="password" required minlength="6">
        </div>
        <div class="form-group">
          <label>I am a…</label>
          <div class="radio-row">
            <label><input type="radio" name="role" value="student" checked> Student</label>
            <label><input type="radio" name="role" value="guest"> Guest (observer)</label>
          </div>
          <p style="font-size:12px;color:#71717a;margin:8px 0 0;">Instructors and TAs are added by your course admin.</p>
        </div>
        <button class="btn" type="submit" style="width:100%;">Create account</button>
      </form>
    </div>
    <?php else: ?>
      <p style="font-size:13px;color:#71717a;text-align:center;margin-top:16px;">New accounts are created by your instructor. Use the credentials they provide to log in.</p>
    <?php endif; ?>
  </div>
</div>
<?php if ($allowSignup): ?>
<script>
document.querySelectorAll('.tab').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(btn.dataset.tab).classList.add('active');
  });
});
</script>
<?php endif; ?>
<?php render_auth_shell_end(); ?>
</body></html>