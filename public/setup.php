<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/install_runner.php';

$config = require dirname(__DIR__) . '/config.php';
$configLocal = dirname(__DIR__) . '/config.local.php';
if (is_file($configLocal)) {
    $config = array_replace_recursive($config, require $configLocal);
}

$db = $config['db'] ?? [];
$messages = [];
$errors = [];
$ran = false;

$form = [
    'db_host' => (string) ($db['host'] ?? '127.0.0.1'),
    'db_port' => (string) ($db['port'] ?? '3306'),
    'db_name' => (string) ($db['name'] ?? 'yourlms'),
    'db_user' => (string) ($db['user'] ?? 'root'),
    'db_pass' => (string) ($db['pass'] ?? ''),
    'base_url' => (string) ($config['base_url'] ?? install_detect_base_url() ?: '/yourlms'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_install']) && !is_file(dirname(__DIR__) . '/.setup-complete')) {
    $ran = true;
    $form = [
        'db_host' => trim($_POST['db_host'] ?? $form['db_host']),
        'db_port' => trim($_POST['db_port'] ?? $form['db_port']),
        'db_name' => trim($_POST['db_name'] ?? $form['db_name']),
        'db_user' => trim($_POST['db_user'] ?? $form['db_user']),
        'db_pass' => (string) ($_POST['db_pass'] ?? $form['db_pass']),
        'base_url' => trim($_POST['base_url'] ?? $form['base_url']),
    ];
    $installConfig = array_replace_recursive($config, [
        'base_url' => $form['base_url'] !== '' ? $form['base_url'] : ($config['base_url'] ?? '/yourlms'),
        'db' => [
            'host' => $form['db_host'],
            'port' => (int) $form['db_port'],
            'name' => $form['db_name'],
            'user' => $form['db_user'],
            'pass' => $form['db_pass'],
            'charset' => $db['charset'] ?? 'utf8mb4',
        ],
    ]);
    $envErrors = install_check_environment($installConfig);
    if ($envErrors) {
        $errors = array_merge($errors, $envErrors);
    } else {
        [$messages, $setupErrors] = install_mysql_setup($installConfig);
        $errors = array_merge($errors, $setupErrors);
    }
}

$complete = is_file(dirname(__DIR__) . '/.setup-complete');
$base = rtrim($form['base_url'] !== '' ? $form['base_url'] : ($config['base_url'] ?? ''), '/') ?: '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Install <?= htmlspecialchars($config['app_name'] ?? 'YourLMS') ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars($base . '/assets/css/style.css') ?>">
</head>
<body class="auth-page">
  <div class="auth-card" style="max-width:600px;">
    <div class="auth-logo site-brand-initials-wrap"><span class="site-brand-initials">YL</span></div>
    <h1>Install <?= htmlspecialchars($config['app_name'] ?? 'YourLMS') ?></h1>
    <p class="auth-tagline">One-click setup — no command line required</p>

    <?php if ($complete && !$ran): ?>
      <div class="flash flash-success" style="border-radius:8px;margin-bottom:12px;">YourLMS is already installed.</div>
      <p style="font-size:14px;color:#475569;">To change database settings later, edit <code>config.local.php</code> in this folder.</p>
      <a class="btn" href="<?= htmlspecialchars($base . '/login.php') ?>" style="display:block;text-align:center;">Go to login</a>
      <a class="btn btn-outline" href="<?= htmlspecialchars($base . '/getting-started.php') ?>" style="display:block;text-align:center;margin-top:10px;">Getting started guide</a>
    <?php elseif ($complete && $ran): ?>
      <?php foreach ($messages as $m): ?>
        <div class="flash flash-success" style="border-radius:8px;margin-bottom:8px;"><?= htmlspecialchars($m) ?></div>
      <?php endforeach; ?>
      <?php foreach ($errors as $e): ?>
        <div class="flash flash-error" style="border-radius:8px;margin-bottom:8px;"><?= htmlspecialchars($e) ?></div>
      <?php endforeach; ?>
      <?php if (!$errors): ?>
        <p style="font-size:15px;line-height:1.6;color:#334155;">
          You are on a <strong>clean slate</strong> — no courses are pre-loaded. Log in and follow the getting-started guide.
        </p>
        <p style="font-size:14px;color:#475569;">
          <strong>Instructor:</strong> <code>instructor@yourlms.test</code><br>
          <strong>Student:</strong> <code>student@yourlms.test</code><br>
          <strong>Password:</strong> <code>password123</code>
        </p>
        <p style="font-size:13px;color:#475569;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;">
          <strong>Database saved.</strong> Credentials are stored in <code>config.local.php</code>. To change them later, edit that file and restart Apache if needed.
        </p>
        <p style="font-size:13px;color:#b45309;background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:10px 12px;">
          Change demo passwords before sharing this server with others.
        </p>
        <a class="btn" href="<?= htmlspecialchars($base . '/getting-started.php') ?>" style="display:block;text-align:center;margin-top:16px;">Open getting started →</a>
      <?php endif; ?>
    <?php else: ?>
      <ol style="font-size:14px;color:#475569;line-height:1.7;padding-left:20px;margin:0 0 20px;">
        <li>Start <strong>Apache</strong> and <strong>MySQL</strong> in XAMPP.</li>
        <li>Enter your database details below (XAMPP defaults are pre-filled).</li>
        <li>Click <strong>Install now</strong> — setup configures PHP for <strong>up to 1 GB</strong> course imports automatically.</li>
      </ol>
      <?php foreach ($errors as $e): ?>
        <div class="flash flash-error" style="border-radius:8px;margin-bottom:8px;"><?= htmlspecialchars($e) ?></div>
      <?php endforeach; ?>
      <form method="post" class="setup-install-form">
        <input type="hidden" name="run_install" value="1">
        <fieldset style="border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin:0 0 16px;">
          <legend style="font-size:13px;font-weight:600;padding:0 6px;">MySQL database</legend>
          <div class="form-group">
            <label>Database name</label>
            <input name="db_name" required value="<?= htmlspecialchars($form['db_name']) ?>" placeholder="yourlms">
            <p style="font-size:12px;color:#94a3b8;margin:6px 0 0;">Created automatically if it does not exist.</p>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
              <label>Username</label>
              <input name="db_user" required value="<?= htmlspecialchars($form['db_user']) ?>" autocomplete="off">
            </div>
            <div class="form-group">
              <label>Password</label>
              <input type="password" name="db_pass" value="<?= htmlspecialchars($form['db_pass']) ?>" autocomplete="new-password" placeholder="(empty on XAMPP)">
            </div>
          </div>
          <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;">
            <div class="form-group">
              <label>Host</label>
              <input name="db_host" required value="<?= htmlspecialchars($form['db_host']) ?>">
            </div>
            <div class="form-group">
              <label>Port</label>
              <input name="db_port" required value="<?= htmlspecialchars($form['db_port']) ?>">
            </div>
          </div>
        </fieldset>
        <div class="form-group">
          <label>Site address path</label>
          <input name="base_url" required value="<?= htmlspecialchars($form['base_url']) ?>" placeholder="/yourlms">
          <p style="font-size:12px;color:#94a3b8;margin:6px 0 0;">Usually <code>/yourlms</code> — must match the folder name inside <code>htdocs</code>.</p>
        </div>
        <button class="btn" type="submit" style="width:100%;font-size:16px;padding:14px;">Install now</button>
      </form>
      <p style="font-size:12px;color:#94a3b8;margin-top:16px;text-align:center;">
        Shared hosting without MySQL? Use <a href="<?= htmlspecialchars($base . '/install.php') ?>">install.php</a> (SQLite).<br>
        After install, change database settings anytime in <code>config.local.php</code>.
      </p>
    <?php endif; ?>
  </div>
</body>
</html>