<?php
declare(strict_types=1);

/**
 * Shared-hosting installer — no root, no cPanel, no MySQL required.
 * Default: SQLite file in data/yourlms.sqlite
 * Install path: public_html/yourlms → base URL /yourlms
 */

require_once __DIR__ . '/includes/install_runner.php';

$defaultLocal = [
    'base_url' => '/yourlms',
    'db' => [
        'driver' => 'sqlite',
        'sqlite_path' => __DIR__ . '/data/yourlms.sqlite',
    ],
];

$config = require __DIR__ . '/config.php';
$config = array_replace_recursive($config, $defaultLocal);
$configLocal = __DIR__ . '/config.local.php';
if (is_file($configLocal)) {
    $config = array_replace_recursive($config, require $configLocal);
}

$baseUrl = $config['base_url'] ?: install_detect_base_url();
$envErrors = install_check_environment($config);
$messages = [];
$errors = $envErrors;
$done = install_is_locked();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$done && !$envErrors) {
    $baseUrl = trim($_POST['base_url'] ?? $baseUrl);

    $local = [
        'base_url' => $baseUrl,
        'db' => [
            'driver' => 'sqlite',
            'sqlite_path' => __DIR__ . '/data/yourlms.sqlite',
        ],
    ];
    if (!install_write_config_local($local)) {
        $errors[] = 'Could not write config.local.php — check folder permissions.';
    } else {
        $config = array_replace_recursive($config, $local);
        [$messages, $dbErrors] = install_run_database_setup($config);
        $errors = array_merge($errors, $dbErrors);
        if (!$dbErrors) {
            [$phpMessages, $phpErrors] = install_configure_upload_limits();
            $messages = array_merge($messages, $phpMessages);
            $errors = array_merge($errors, $phpErrors);
            if (!$phpErrors) {
                install_finalize();
                $done = true;
            }
        }
    }
}

$css = htmlspecialchars(rtrim($baseUrl, '/') . '/assets/css/style.css', ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($config['app_name'] ?? 'YourLMS') ?> — Install</title>
  <link rel="stylesheet" href="<?= $css ?>">
</head>
<body class="auth-page">
  <div class="auth-card" style="max-width:520px;">
    <h1>Install <?= htmlspecialchars($config['app_name'] ?? 'YourLMS') ?></h1>
    <p class="auth-tagline">File storage — no MySQL or cPanel required</p>

    <?php foreach ($messages as $m): ?>
      <div class="flash flash-success" style="border-radius:6px;margin-bottom:8px;"><?= htmlspecialchars($m) ?></div>
    <?php endforeach; ?>
    <?php foreach ($errors as $e): ?>
      <div class="flash flash-error" style="border-radius:6px;margin-bottom:8px;"><?= htmlspecialchars($e) ?></div>
    <?php endforeach; ?>

    <?php if ($done): ?>
      <p style="font-size:14px;line-height:1.6;">
        Installation complete. All data is stored in <code>data/yourlms.sqlite</code> (back up that file with your uploads).
        <strong>Delete or rename <code>install.php</code></strong> when done.
      </p>
      <p style="font-size:14px;">
        Demo login: <code>instructor@yourlms.test</code> / <code>password123</code>
      </p>
      <p style="font-size:13px;color:#b45309;background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:10px 12px;">
        <strong>Security:</strong> Demo passwords are for local testing only. Change or remove these accounts before exposing this server to others.
      </p>
      <a class="btn" href="<?= htmlspecialchars(rtrim($baseUrl, '/') . '/getting-started.php') ?>" style="display:block;text-align:center;margin-top:16px;">Getting started →</a>
      <a class="btn btn-outline" href="<?= htmlspecialchars(rtrim($baseUrl, '/') . '/login.php') ?>" style="display:block;text-align:center;margin-top:10px;">Go to login</a>
    <?php elseif (!$envErrors): ?>
      <p style="font-size:14px;color:#475569;line-height:1.6;margin-bottom:20px;">
        Upload this app to <code>public_html/yourlms</code>, ensure <code>data/</code> and <code>uploads/</code> are writable, then click install.
        No database server is needed — everything is stored in one SQLite file in your directory.
      </p>
      <form method="post">
        <div class="form-group">
          <label>Base URL path</label>
          <input name="base_url" value="<?= htmlspecialchars($baseUrl) ?>" required>
          <p class="text-muted" style="font-size:12px;margin:8px 0 0;">Use <code>/yourlms</code> when installed in <code>public_html/yourlms</code>.</p>
        </div>
        <button class="btn" type="submit" style="width:100%;">Create file database &amp; start</button>
      </form>
    <?php endif; ?>
  </div>
</body>
</html>