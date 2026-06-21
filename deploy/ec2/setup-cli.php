#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * CLI database bootstrap for EC2 / server installs (same flow as setup.php).
 * Usage: php deploy/ec2/setup-cli.php
 */

$root = dirname(__DIR__, 2);
if (!is_file($root . '/config.php')) {
    fwrite(STDERR, "config.php not found in {$root}\n");
    exit(1);
}

require_once $root . '/includes/install_runner.php';

$config = require $root . '/config.php';
$local = $root . '/config.local.php';
if (is_file($local)) {
    $config = array_replace_recursive($config, require $local);
}

$GLOBALS['config'] = $config;

[$messages, $errors] = install_run_database_setup($config);

foreach ($messages as $m) {
    echo "[ok] {$m}\n";
}
if ($errors) {
    foreach ($errors as $e) {
        fwrite(STDERR, "[error] {$e}\n");
    }
    exit(1);
}

echo "[done] Database ready. Import curriculum via Teach → Import IMS after first login.\n";
exit(0);