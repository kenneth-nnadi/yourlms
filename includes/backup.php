<?php
declare(strict_types=1);

require_once __DIR__ . '/sql_compat.php';
require_once __DIR__ . '/db.php';

function build_full_site_backup(array $config): string
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('ZipArchive is required for backups.');
    }

    $tmpdir = rtrim(sys_get_temp_dir(), '/') . '/yourlms_backup_' . bin2hex(random_bytes(4));
    mkdir($tmpdir, 0755, true);
    $stamp = date('Y-m-d_His');

    if (db_is_sqlite($config)) {
        $dbPath = db_sqlite_path($config);
        if (!is_file($dbPath)) {
            throw new RuntimeException('Database file not found: ' . $dbPath);
        }
        try {
            $pdo = db_connect($config);
            $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        } catch (Throwable) {
            // Continue with file copy if checkpoint fails.
        }
        $dumpFile = $tmpdir . '/yourlms_' . $stamp . '.sqlite';
        if (!copy($dbPath, $dumpFile)) {
            throw new RuntimeException('Could not copy database file for backup.');
        }
        $meta = [
            'app' => $config['app_name'] ?? 'YourLMS',
            'created_at' => date('c'),
            'storage' => 'sqlite',
            'database' => basename($dbPath),
        ];
    } else {
        $db = $config['db'];
        $dumpFile = $tmpdir . '/database_' . $stamp . '.sql';
        $host = $db['host'];
        $port = (int) ($db['port'] ?? 3306);
        $name = $db['name'];
        $user = $db['user'];
        $pass = $db['pass'];

        $passArg = $pass !== '' ? '-p' . escapeshellarg($pass) : '';
        $cmd = sprintf(
            'mysqldump -h %s -P %d -u %s %s --single-transaction --routines %s > %s 2>&1',
            escapeshellarg($host),
            $port,
            escapeshellarg($user),
            $passArg,
            escapeshellarg($name),
            escapeshellarg($dumpFile)
        );
        exec($cmd, $output, $code);
        if ($code !== 0 || !is_file($dumpFile) || filesize($dumpFile) < 10) {
            @unlink($dumpFile);
            throw new RuntimeException('mysqldump failed. Is mysqldump in PATH? ' . implode("\n", $output));
        }
        $meta = [
            'app' => $config['app_name'] ?? 'YourLMS',
            'created_at' => date('c'),
            'storage' => 'mysql',
            'database' => $name,
        ];
    }

    file_put_contents($tmpdir . '/backup-meta.json', json_encode($meta, JSON_PRETTY_PRINT));

    $zipPath = $tmpdir . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create backup zip.');
    }
    $zip->addFile($dumpFile, basename($dumpFile));
    $zip->addFile($tmpdir . '/backup-meta.json', 'backup-meta.json');

    $uploadDir = rtrim($config['upload_dir'], '/');
    if (is_dir($uploadDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadDir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }
            $rel = 'uploads/' . str_replace('\\', '/', substr($file->getPathname(), strlen($uploadDir) + 1));
            if (str_contains($rel, '/rate_limits/')) {
                continue;
            }
            $zip->addFile($file->getPathname(), $rel);
        }
    }
    $zip->close();
    @unlink($dumpFile);
    @unlink($tmpdir . '/backup-meta.json');
    @rmdir($tmpdir);

    return $zipPath;
}