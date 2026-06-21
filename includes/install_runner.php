<?php
declare(strict_types=1);

require_once __DIR__ . '/sql_compat.php';
require_once __DIR__ . '/db.php';

function install_lock_file(): string
{
    return dirname(__DIR__) . '/.install-lock';
}

function install_is_locked(): bool
{
    return is_file(install_lock_file());
}

function install_detect_base_url(): string
{
    $path = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
    $path = str_replace('\\', '/', $path);
    if ($path === '/' || $path === '.') {
        return '';
    }
    return rtrim($path, '/');
}

function install_check_environment(?array $config = null): array
{
    $errors = [];
    if (PHP_VERSION_ID < 80100) {
        $errors[] = 'PHP 8.1 or newer is required (this server has ' . PHP_VERSION . ').';
    }
    foreach (['json', 'mbstring'] as $ext) {
        if (!extension_loaded($ext)) {
            $errors[] = "Missing PHP extension: {$ext}";
        }
    }
    if (db_is_sqlite($config)) {
        if (!extension_loaded('pdo_sqlite')) {
            $errors[] = 'Missing PHP extension: pdo_sqlite (required for file storage).';
        }
    } elseif (!extension_loaded('pdo_mysql')) {
        $errors[] = 'Missing PHP extension: pdo_mysql';
    }
    $uploads = dirname(__DIR__) . '/uploads';
    if (!is_dir($uploads)) {
        @mkdir($uploads, 0755, true);
    }
    if (!is_writable($uploads)) {
        $errors[] = 'The uploads/ folder must be writable by the web server.';
    }
    $root = dirname(__DIR__);
    if (!is_writable($root)) {
        $errors[] = 'The YourLMS folder must be writable so setup can save config.local.php (on Mac XAMPP, try chmod 775 on the folder).';
    }
    if (db_is_sqlite($config)) {
        $dataDir = dirname(db_sqlite_path($config));
        if (!is_dir($dataDir)) {
            @mkdir($dataDir, 0755, true);
        }
        if (!is_writable($dataDir)) {
            $errors[] = 'The data/ folder must be writable (SQLite database file).';
        }
    }
    return $errors;
}

function install_write_config_local(array $overrides): bool
{
    $path = dirname(__DIR__) . '/config.local.php';
    $export = var_export($overrides, true);
    return file_put_contents(
        $path,
        "<?php\n\ndeclare(strict_types=1);\n\nreturn {$export};\n"
    ) !== false;
}

function install_merge_config_local(array $overrides): bool
{
    $existing = [];
    $path = dirname(__DIR__) . '/config.local.php';
    if (is_file($path)) {
        $loaded = require $path;
        if (is_array($loaded)) {
            $existing = $loaded;
        }
    }
    $merged = array_replace_recursive($existing, $overrides);
    if (array_key_exists('base_url', $merged) && ($merged['base_url'] === null || $merged['base_url'] === '')) {
        unset($merged['base_url']);
    }
    return install_write_config_local($merged);
}

function install_sanitize_db_name(string $name): string
{
    return preg_replace('/[^a-zA-Z0-9_]/', '', $name) ?? '';
}

/** @return array{0: list<string>, 1: list<string>} */
function install_mysql_setup(array $config): array
{
    $messages = [];
    $errors = [];
    $db = $config['db'] ?? [];
    $dbName = install_sanitize_db_name((string) ($db['name'] ?? 'yourlms'));
    if ($dbName === '') {
        return [[], ['Enter a database name (letters, numbers, and underscores only).']];
    }
    if (($db['user'] ?? '') === '') {
        return [[], ['Enter a MySQL username.']];
    }

    try {
        $host = (string) ($db['host'] ?? '127.0.0.1');
        $port = (int) ($db['port'] ?? 3306);
        $charset = (string) ($db['charset'] ?? 'utf8mb4');
        $user = (string) $db['user'];
        $pass = (string) ($db['pass'] ?? '');

        $serverDsn = sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset);
        $server = new PDO($serverDsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $server->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $dbDsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbName, $charset);
        $pdo = new PDO($dbDsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        db_apply_timezone($pdo, $config);

        $tablesOnly = dirname(__DIR__) . '/database/schema-tables-only.sql';
        if (!is_file($tablesOnly)) {
            throw new RuntimeException('Missing database/schema-tables-only.sql');
        }
        install_run_sql_file($pdo, $tablesOnly);
        $messages[] = "Database «{$dbName}» is ready.";

        require_once __DIR__ . '/migrations.php';
        run_migrations($pdo, $config);
        $messages[] = 'Latest features applied.';

        $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count === 0) {
            install_run_sql_file($pdo, dirname(__DIR__) . '/database/seed.sql');
            $messages[] = 'Demo instructor and student accounts created.';
        }

        $uploadDir = $config['upload_dir'] ?? (dirname(__DIR__) . '/uploads');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        @chmod($uploadDir, 0777);
        $messages[] = 'Uploads folder is ready.';

        $localOverrides = [
            'db' => [
                'host' => $host,
                'port' => $port,
                'name' => $dbName,
                'user' => $user,
                'pass' => $pass,
                'charset' => $charset,
            ],
        ];
        if (!empty($config['base_url'])) {
            $localOverrides['base_url'] = $config['base_url'];
        }
        if (!install_merge_config_local($localOverrides)) {
            throw new RuntimeException('Could not write config.local.php — the YourLMS folder must be writable by the web server.');
        }
        $messages[] = 'Database credentials saved to config.local.php.';

        file_put_contents(dirname(__DIR__) . '/.setup-complete', date('c') . "\n");
        $messages[] = 'Installation complete!';
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }

    return [$messages, $errors];
}

function install_run_sql_file(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    $sql = preg_replace('/--.*$/m', '', $sql) ?? $sql;
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt === '' || str_starts_with(strtoupper($stmt), 'PRAGMA')) {
            if ($stmt !== '') {
                $pdo->exec($stmt);
            }
            continue;
        }
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            if (!str_contains($e->getMessage(), 'already exists')) {
                throw $e;
            }
        }
    }
}

function install_run_database_setup(array $config): array
{
    $messages = [];
    $errors = [];
    $GLOBALS['config'] = $config;

    try {
        $pdo = db_connect($config);
    } catch (Throwable $e) {
        return [[], ['Database connection failed: ' . $e->getMessage()]];
    }

    $sqlite = db_is_sqlite($config);
    $schemaFile = $sqlite
        ? dirname(__DIR__) . '/database/schema-sqlite.sql'
        : (($config['install_mode'] ?? '') === 'shared'
            ? dirname(__DIR__) . '/database/schema-tables-only.sql'
            : dirname(__DIR__) . '/database/schema.sql');

    try {
        install_run_sql_file($pdo, $schemaFile);
        $messages[] = $sqlite
            ? 'File database created at data/yourlms.sqlite'
            : 'Database tables created.';
    } catch (Throwable $e) {
        return [[], [$e->getMessage()]];
    }

    require_once __DIR__ . '/migrations.php';
    run_migrations($pdo, $config);
    if (!$sqlite) {
        $messages[] = 'Migrations applied.';
    }

    try {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count === 0) {
            install_run_sql_file($pdo, dirname(__DIR__) . '/database/seed.sql');
            $messages[] = 'Demo accounts created (instructor@yourlms.test / password123).';
        } else {
            $messages[] = 'Database already has users — seed skipped.';
        }
    } catch (Throwable $e) {
        $errors[] = 'Seed failed: ' . $e->getMessage();
    }

    return [$messages, $errors];
}

function install_finalize(): void
{
    file_put_contents(install_lock_file(), date('c') . "\n");
    file_put_contents(dirname(__DIR__) . '/.setup-complete', date('c') . "\n");
}