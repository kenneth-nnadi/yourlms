<?php
declare(strict_types=1);

function export_course_json(PDO $pdo, int $courseId): array
{
    $course = $pdo->prepare('SELECT * FROM courses WHERE id = ?');
    $course->execute([$courseId]);
    $course = $course->fetch();
    if (!$course) {
        throw new RuntimeException('Course not found.');
    }

    $modules = $pdo->prepare('SELECT * FROM modules WHERE course_id = ? ORDER BY position');
    $modules->execute([$courseId]);
    $modules = $modules->fetchAll();

    $moduleData = [];
    foreach ($modules as $m) {
        $items = $pdo->prepare('SELECT * FROM module_items WHERE module_id = ? ORDER BY position');
        $items->execute([$m['id']]);
        $moduleData[] = [
            'title' => $m['title'],
            'position' => (int) $m['position'],
            'published' => (bool) $m['published'],
            'items' => $items->fetchAll(),
        ];
    }

    $assignments = $pdo->prepare('SELECT * FROM assignments WHERE course_id = ?');
    $assignments->execute([$courseId]);
    $quizzes = $pdo->prepare('SELECT * FROM quizzes WHERE course_id = ?');
    $quizzes->execute([$courseId]);
    $discussions = $pdo->prepare('SELECT * FROM discussions WHERE course_id = ?');
    $discussions->execute([$courseId]);
    $announcements = $pdo->prepare('SELECT * FROM announcements WHERE course_id = ?');
    $announcements->execute([$courseId]);
    $files = $pdo->prepare('SELECT * FROM course_files WHERE course_id = ?');
    $files->execute([$courseId]);

    $g = $pdo->prepare('SELECT * FROM assignment_groups WHERE course_id = ? ORDER BY position');
    $g->execute([$courseId]);
    $groups = $g->fetchAll();

    $quizzes = $quizzes->fetchAll();
    $quizIds = array_column($quizzes, 'id');

    $questions = [];
    if ($quizIds) {
        $ph = implode(',', array_fill(0, count($quizIds), '?'));
        $q = $pdo->prepare("SELECT * FROM quiz_questions WHERE quiz_id IN ({$ph}) ORDER BY quiz_id, position");
        $q->execute($quizIds);
        foreach ($q->fetchAll() as $row) {
            $questions[$row['quiz_id']][] = $row;
        }
    }

    foreach ($quizzes as &$quiz) {
        $quiz['questions'] = $questions[$quiz['id']] ?? [];
    }
    unset($quiz);

    return [
        'exported_at' => date('c'),
        'format' => 'open-lms-course-v1',
        'course' => $course,
        'assignment_groups' => $groups,
        'assignments' => $assignments->fetchAll(),
        'quizzes' => $quizzes,
        'discussions' => $discussions->fetchAll(),
        'announcements' => $announcements->fetchAll(),
        'course_files' => $files->fetchAll(),
        'modules' => $moduleData,
    ];
}

function collect_export_file_paths(array $data, array $config): array
{
    $paths = [];
    foreach ($data['course_files'] ?? [] as $f) {
        if (!empty($f['file_path'])) {
            $paths[$f['file_path']] = true;
        }
    }
    foreach ($data['modules'] ?? [] as $mod) {
        foreach ($mod['items'] ?? [] as $item) {
            if (!empty($item['file_path'])) {
                $paths[$item['file_path']] = true;
            }
        }
    }
    $uploadBase = rtrim($config['upload_dir'], '/');
    $existing = [];
    foreach (array_keys($paths) as $rel) {
        if (is_file($uploadBase . '/' . $rel)) {
            $existing[] = $rel;
        }
    }
    return $existing;
}

function build_course_export_zip(PDO $pdo, int $courseId, array $config): string
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('ZipArchive extension is required for full export.');
    }
    $data = export_course_json($pdo, $courseId);
    $tmpdir = rtrim(sys_get_temp_dir(), '/') . '/nicelms_export_' . bin2hex(random_bytes(4));
    mkdir($tmpdir, 0755, true);
    $filesDir = $tmpdir . '/files';
    mkdir($filesDir, 0755, true);

    file_put_contents(
        $tmpdir . '/course.json',
        json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );

    $uploadBase = rtrim($config['upload_dir'], '/');
    foreach (collect_export_file_paths($data, $config) as $rel) {
        $src = $uploadBase . '/' . $rel;
        $dest = $filesDir . '/' . $rel;
        $parent = dirname($dest);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }
        copy($src, $dest);
    }

    $zipPath = $tmpdir . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create export zip.');
    }
    $addDir = static function (ZipArchive $zip, string $dir, string $base) use (&$addDir): void {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            $local = ($base !== '' ? $base . '/' : '') . $entry;
            if (is_dir($path)) {
                $zip->addEmptyDir($local);
                $addDir($zip, $path, $local);
            } else {
                $zip->addFile($path, $local);
            }
        }
    };
    $zip->addFile($tmpdir . '/course.json', 'course.json');
    $addDir($zip, $filesDir, 'files');
    $zip->close();

    return $zipPath;
}