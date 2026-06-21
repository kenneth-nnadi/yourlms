<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$configLocal = __DIR__ . '/config.local.php';
if (is_file($configLocal)) {
    $config = array_replace_recursive($config, require $configLocal);
}
$db = $config['db'];

$messages = [];
$errors = [];
$ran = false;

$runInstall = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_install']))
    || ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['auto']) && !is_file(__DIR__ . '/.setup-complete'));

if ($runInstall && !is_file(__DIR__ . '/.setup-complete')) {
    $ran = true;
    try {
        $sharedHosting = ($config['install_mode'] ?? '') === 'shared' && !empty($db['name']);
        if ($sharedHosting) {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $db['host'],
                (int) $db['port'],
                $db['name'],
                $db['charset']
            );
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;charset=%s', $db['host'], $db['port'], $db['charset']);
        }
        $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        $runSqlFile = static function (PDO $pdo, string $path): void {
            $sql = file_get_contents($path);
            $sql = preg_replace('/--.*$/m', '', $sql) ?? $sql;
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt !== '') {
                    try {
                        $pdo->exec($stmt);
                    } catch (PDOException $e) {
                        if (!str_contains($e->getMessage(), 'already exists')) {
                            throw $e;
                        }
                    }
                }
            }
        };

        $tablesOnly = __DIR__ . '/database/schema-tables-only.sql';
        if ($sharedHosting && is_file($tablesOnly)) {
            $runSqlFile($pdo, $tablesOnly);
            $messages[] = 'Database tables are ready.';
        } else {
            $runSqlFile($pdo, __DIR__ . '/database/schema.sql');
            $messages[] = 'Database created successfully.';
        }

        require_once __DIR__ . '/includes/migrations.php';
        run_migrations($pdo);
        $messages[] = 'Latest features applied.';

        $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count === 0) {
            $runSqlFile($pdo, __DIR__ . '/database/seed.sql');
            $messages[] = 'Demo instructor and student accounts created.';
        }

        if (!is_dir($config['upload_dir'])) {
            mkdir($config['upload_dir'], 0755, true);
        }
        @chmod($config['upload_dir'], 0777);
        $messages[] = 'Uploads folder is ready.';

        file_put_contents(__DIR__ . '/.setup-complete', date('c') . "\n");
        $messages[] = 'Installation complete!';
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$complete = is_file(__DIR__ . '/.setup-complete');
$base = rtrim($config['base_url'] ?? '', '/') ?: '';
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
  <div class="auth-card" style="max-width:560px;">
    <div class="auth-logo site-brand-initials-wrap"><span class="site-brand-initials">YL</span></div>
    <h1>Install <?= htmlspecialchars($config['app_name'] ?? 'YourLMS') ?></h1>
    <p class="auth-tagline">One-click setup — no command line required</p>

    <?php if ($complete && !$ran): ?>
      <div class="flash flash-success" style="border-radius:8px;margin-bottom:12px;">YourLMS is already installed.</div>
      <a class="btn" href="<?= htmlspecialchars($base . '/login.php') ?>" style="display:block;text-align:center;">Go to login</a>
      <a class="btn btn-outline" href="<?= htmlspecialchars($base . '/getting-started.php') ?>" style="display:block;text-align:center;margin-top:10px;">Getting started guide</a>
    <?php elseif ($complete && $ran): ?>
      <?php foreach ($messages as $m): ?>
        <div class="flash flash-success" style="border-radius:8px;margin-bottom:8px;"><?= htmlspecialchars($m) ?></div>
      <?php endforeach; ?>
      <p style="font-size:15px;line-height:1.6;color:#334155;">
        You are on a <strong>clean slate</strong> — no courses are pre-loaded. Log in and follow the getting-started guide to import or build your first course.
      </p>
      <p style="font-size:14px;color:#475569;">
        <strong>Instructor:</strong> <code>instructor@yourlms.test</code><br>
        <strong>Student:</strong> <code>student@yourlms.test</code><br>
        <strong>Password:</strong> <code>password123</code>
      </p>
      <p style="font-size:13px;color:#b45309;background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;padding:10px 12px;">
        Change demo passwords before sharing this server with others.
      </p>
      <a class="btn" href="<?= htmlspecialchars($base . '/getting-started.php') ?>" style="display:block;text-align:center;margin-top:16px;">Open getting started →</a>
    <?php else: ?>
      <ol style="font-size:14px;color:#475569;line-height:1.7;padding-left:20px;margin:0 0 20px;">
        <li>Start <strong>Apache</strong> and <strong>MySQL</strong> in XAMPP (or your hosting panel).</li>
        <li>Click <strong>Install now</strong> below — we create the database and demo accounts.</li>
        <li>Log in and import your Canvas course or build from scratch.</li>
      </ol>
      <?php foreach ($errors as $e): ?>
        <div class="flash flash-error" style="border-radius:8px;margin-bottom:8px;"><?= htmlspecialchars($e) ?></div>
        <p style="font-size:13px;color:#71717a;">Make sure MySQL is running. Default XAMPP user is <code>root</code> with an empty password.</p>
      <?php endforeach; ?>
      <?php if (!$errors): ?>
        <form method="post">
          <input type="hidden" name="run_install" value="1">
          <button class="btn" type="submit" style="width:100%;font-size:16px;padding:14px;">Install now</button>
        </form>
        <p style="font-size:12px;color:#94a3b8;margin-top:16px;text-align:center;">
          Shared hosting without MySQL? Use <a href="<?= htmlspecialchars($base . '/install.php') ?>">install.php</a> (SQLite).
        </p>
      <?php else: ?>
        <form method="post" style="margin-top:12px;">
          <input type="hidden" name="run_install" value="1">
          <button class="btn btn-outline" type="submit" style="width:100%;">Try again</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</body>
</html>