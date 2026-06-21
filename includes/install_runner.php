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
    if (db_is_sqlite($config)) {
        $dataDir = dirname(db_sqlite_path($config ?? config()));
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