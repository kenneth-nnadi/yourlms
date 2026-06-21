<?php
declare(strict_types=1);

require_once __DIR__ . '/course_export.php';
require_once __DIR__ . '/course_import.php';

function duplicate_course_with_files(PDO $pdo, int $sourceId, array $config, int $userId): array
{
    $zipPath = build_course_export_zip($pdo, $sourceId, $config);
    $extract = rtrim($config['upload_dir'], '/') . '/dup_' . bin2hex(random_bytes(4));
    mkdir($extract, 0755, true);
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Could not open export zip.');
    }
    $zip->extractTo($extract);
    $zip->close();
    @unlink($zipPath);

    $jsonPath = $extract . '/course.json';
    if (!is_file($jsonPath)) {
        throw new RuntimeException('Export zip missing course.json.');
    }
    $data = json_decode((string) file_get_contents($jsonPath), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid course.json in export.');
    }

    $data['course']['code'] = ($data['course']['code'] ?? 'COURSE') . '-COPY-' . date('ymd');
    $data['course']['name'] = ($data['course']['name'] ?? 'Course') . ' (Copy)';
    $data['course']['published'] = 0;
    $filesRoot = is_dir($extract . '/files') ? $extract . '/files' : null;

    try {
        return import_course_json($pdo, $data, $config, $userId, null, false, $filesRoot);
    } finally {
        $rm = static function (string $dir) use (&$rm): void {
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $dir . '/' . $entry;
                is_dir($path) ? $rm($path) : @unlink($path);
            }
            @rmdir($dir);
        };
        $rm($extract);
    }
}