<?php
declare(strict_types=1);

require_once __DIR__ . '/sql_compat.php';

function db_sqlite_path(array $config): string
{
    $db = $config['db'];
    $path = $db['sqlite_path'] ?? (dirname(__DIR__) . '/data/yourlms.sqlite');
    if (!str_starts_with($path, '/')) {
        $path = dirname(__DIR__) . '/' . ltrim($path, '/');
    }
    return $path;
}

function db_connect(array $config): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (db_is_sqlite($config)) {
        $path = db_sqlite_path($config);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        db_apply_timezone($pdo, $config);
        return $pdo;
    }

    $db = $config['db'];
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'],
        $db['port'],
        $db['name'],
        $db['charset']
    );

    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    db_apply_timezone($pdo, $config);

    return $pdo;
}