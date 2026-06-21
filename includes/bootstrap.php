<?php
declare(strict_types=1);

$config = require dirname(__DIR__) . '/config.php';

$configLocal = dirname(__DIR__) . '/config.local.php';
if (is_file($configLocal)) {
    $localOverrides = require $configLocal;
    if (is_array($localOverrides)) {
        $config = array_replace_recursive($config, $localOverrides);
    }
}

$brandingLocal = __DIR__ . '/branding.local.php';
if (is_file($brandingLocal)) {
    $brandingOverrides = require $brandingLocal;
    if (is_array($brandingOverrides)) {
        $config = array_replace_recursive($config, $brandingOverrides);
    }
}

require_once __DIR__ . '/security.php';
send_security_headers();

$sessionCfg = $config['session'] ?? [];
ini_set('session.cookie_httponly', '1');
ini_set('session.use_strict_mode', '1');
$sameSite = $sessionCfg['samesite'] ?? 'Lax';
if (in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
    ini_set('session.cookie_samesite', $sameSite);
}
$sessionSecure = !empty($sessionCfg['secure']);
if (!$sessionSecure && ($sessionCfg['auto_secure'] ?? true) && request_is_https()) {
    $sessionSecure = true;
}
if ($sessionSecure) {
    ini_set('session.cookie_secure', '1');
}

session_start();

require_once __DIR__ . '/sql_compat.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/user_roles.php';
require_once __DIR__ . '/student_preview.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/brand.php';

$pdo = db_connect($config);
run_migrations($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !defined('API_REQUEST')) {
    verify_csrf();
}

ensure_upload_dir($config['upload_dir']);

if (!is_file(dirname(__DIR__) . '/.upload-limits-applied') && is_file(dirname(__DIR__) . '/.setup-complete')) {
    require_once __DIR__ . '/install_php.php';
    install_configure_upload_limits();
}