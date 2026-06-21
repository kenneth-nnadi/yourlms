<?php
declare(strict_types=1);

require_once __DIR__ . '/imscc_importer.php';

function import_course_json(PDO $pdo, array $data, array $config, int $userId, ?int $targetCourseId = null, bool $replaceExisting = false, ?string $filesRoot = null): array
{
    if (($data['format'] ?? '') !== 'open-lms-course-v1') {
        throw new RuntimeException('Unsupported export format. Expected open-lms-course-v1.');
    }

    $courseRow = $data['course'] ?? null;
    if (!$courseRow || empty($courseRow['code'])) {
        throw new RuntimeException('Export is missing course data.');
    }

    if ($targetCourseId) {
        $check = $pdo->prepare('SELECT id FROM courses WHERE id = ?');
        $check->execute([$targetCourseId]);
        if (!$check->fetch()) {
            throw new RuntimeException('Target course not found.');
        }
        $courseId = $targetCourseId;
        if ($replaceExisting) {
            clear_course_content($pdo, $courseId);
            $pdo->prepare('DELETE FROM assignment_groups WHERE course_id = ?')->execute([$courseId]);
            $pdo->prepare(
                'UPDATE courses SET code = ?, name = ?, description = ?, term = ?, color = ?, published = ? WHERE id = ?'
            )->execute([
                $courseRow['code'],
                $courseRow['name'] ?? $courseRow['code'],
                $courseRow['description'] ?? null,
                $courseRow['term'] ?? null,
                $courseRow['color'] ?? '#0d9488',
                !empty($courseRow['published']) ? 1 : 0,
                $courseId,
            ]);
        }
    } else {
        $pdo->prepare(
            'INSERT INTO courses (code, name, description, term, color, published) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $courseRow['code'],
            $courseRow['name'] ?? $courseRow['code'],
            $courseRow['description'] ?? null,
            $courseRow['term'] ?? null,
            $courseRow['color'] ?? '#0d9488',
            !empty($courseRow['published']) ? 1 : 0,
        ]);
        $courseId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO enrollments (course_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$courseId, $userId, 'instructor']);
    }

    $groupMap = [];
    foreach ($data['assignment_groups'] ?? [] as $g) {
        $pdo->prepare('INSERT INTO assignment_groups (course_id, name, weight, position) VALUES (?, ?, ?, ?)')
            ->execute([$courseId, $g['name'], (float) ($g['weight'] ?? 0), (int) ($g['position'] ?? 0)]);
        $groupMap[(int) $g['id']] = (int) $pdo->lastInsertId();
    }

    $assignmentMap = [];
    foreach ($data['assignments'] ?? [] as $a) {
        $gid = isset($a['group_id']) ? ($groupMap[(int) $a['group_id']] ?? null) : null;
        $pdo->prepare(
            'INSERT INTO assignments (course_id, group_id, title, description, description_format, due_at, points, lock_after_due)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $courseId,
            $gid,
            $a['title'],
            $a['description'] ?? null,
            ($a['description_format'] ?? 'text') === 'html' ? 'html' : 'text',
            $a['due_at'] ?? null,
            (float) ($a['points'] ?? 100),
            !empty($a['lock_after_due']) ? 1 : 0,
        ]);
        $assignmentMap[(int) $a['id']] = (int) $pdo->lastInsertId();
    }

    $quizMap = [];
    foreach ($data['quizzes'] ?? [] as $q) {
        $gid = isset($q['group_id']) ? ($groupMap[(int) $q['group_id']] ?? null) : null;
        $pdo->prepare(
            'INSERT INTO quizzes (course_id, group_id, title, description, description_format, points, due_at, max_attempts, lock_after_due)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $courseId,
            $gid,
            $q['title'],
            $q['description'] ?? null,
            ($q['description_format'] ?? 'text') === 'html' ? 'html' : 'text',
            (float) ($q['points'] ?? 25),
            $q['due_at'] ?? null,
            (int) ($q['max_attempts'] ?? 1),
            !empty($q['lock_after_due']) ? 1 : 0,
        ]);
        $newQuizId = (int) $pdo->lastInsertId();
        $quizMap[(int) $q['id']] = $newQuizId;
        foreach ($q['questions'] ?? [] as $pos => $question) {
            $choices = $question['choices'] ?? null;
            if (is_string($choices)) {
                $choices = json_decode($choices, true);
            }
            $pdo->prepare(
                'INSERT INTO quiz_questions (quiz_id, question_type, question, choices, correct_index, points, position)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $newQuizId,
                $question['question_type'] ?? 'choice',
                $question['question'] ?? $question['question_text'] ?? '',
                $choices ? json_encode($choices) : null,
                isset($question['correct_index']) ? (int) $question['correct_index'] : null,
                isset($question['points']) ? (float) $question['points'] : null,
                (int) ($question['position'] ?? $pos),
            ]);
        }
    }

    $discussionMap = [];
    foreach ($data['discussions'] ?? [] as $d) {
        $gid = isset($d['group_id']) ? ($groupMap[(int) $d['group_id']] ?? null) : null;
        $pdo->prepare(
            'INSERT INTO discussions (course_id, group_id, title, prompt, prompt_format, points, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $courseId,
            $gid,
            $d['title'],
            $d['prompt'] ?? null,
            ($d['prompt_format'] ?? 'text') === 'html' ? 'html' : 'text',
            isset($d['points']) ? (float) $d['points'] : null,
            $userId,
        ]);
        $discussionMap[(int) $d['id']] = (int) $pdo->lastInsertId();
    }

    foreach ($data['announcements'] ?? [] as $ann) {
        $pdo->prepare(
            'INSERT INTO announcements (course_id, title, body, body_format, published, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $courseId,
            $ann['title'],
            $ann['body'] ?? '',
            ($ann['body_format'] ?? 'text') === 'html' ? 'html' : 'text',
            !empty($ann['published']) ? 1 : 0,
            $userId,
        ]);
    }

    $pathMap = [];
    if ($filesRoot && is_dir($filesRoot)) {
        $pathMap = import_course_export_files($pdo, $courseId, $config, $filesRoot, $data['course_files'] ?? []);
    }

    foreach ($data['modules'] ?? [] as $mod) {
        $pdo->prepare('INSERT INTO modules (course_id, title, position, published) VALUES (?, ?, ?, ?)')
            ->execute([$courseId, $mod['title'], (int) ($mod['position'] ?? 0), !empty($mod['published']) ? 1 : 0]);
        $moduleId = (int) $pdo->lastInsertId();

        foreach ($mod['items'] ?? [] as $item) {
            $refId = null;
            $type = $item['item_type'] ?? 'page';
            if (!empty($item['ref_id'])) {
                $oldRef = (int) $item['ref_id'];
                $refId = match ($type) {
                    'assignment' => $assignmentMap[$oldRef] ?? null,
                    'quiz' => $quizMap[$oldRef] ?? null,
                    'discussion' => $discussionMap[$oldRef] ?? null,
                    default => null,
                };
            }
            $filePath = $item['file_path'] ?? null;
            if ($filePath && isset($pathMap[$filePath])) {
                $filePath = $pathMap[$filePath];
            }
            $pdo->prepare(
                'INSERT INTO module_items (module_id, title, item_type, content, content_format, ref_id, file_path, position, published)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $moduleId,
                $item['title'] ?? 'Item',
                $type,
                $item['content'] ?? null,
                ($item['content_format'] ?? 'text') === 'html' ? 'html' : 'text',
                $refId,
                $filePath,
                (int) ($item['position'] ?? 0),
                !empty($item['published']) ? 1 : 0,
            ]);
        }
    }

    return [
        'course_id' => $courseId,
        'replaced' => $replaceExisting && $targetCourseId !== null,
        'stats' => [
            'modules' => count($data['modules'] ?? []),
            'assignments' => count($data['assignments'] ?? []),
            'quizzes' => count($data['quizzes'] ?? []),
            'discussions' => count($data['discussions'] ?? []),
            'announcements' => count($data['announcements'] ?? []),
            'files' => count($pathMap),
        ],
    ];
}

function import_course_export_files(PDO $pdo, int $courseId, array $config, string $filesRoot, array $courseFilesMeta): array
{
    $uploadBase = rtrim($config['upload_dir'], '/');
    $pathMap = [];
    $destDir = $uploadBase . '/course_' . $courseId;
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    $copyFile = static function (string $srcRel, string $destRel) use ($uploadBase, $filesRoot, &$pathMap): void {
        $src = $filesRoot . '/' . ltrim($srcRel, '/');
        if (!is_file($src)) {
            return;
        }
        $dest = $uploadBase . '/' . $destRel;
        $parent = dirname($dest);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }
        copy($src, $dest);
        $pathMap[$srcRel] = $destRel;
    };

    foreach ($courseFilesMeta as $f) {
        $oldPath = $f['file_path'] ?? '';
        if ($oldPath === '') {
            continue;
        }
        $basename = basename($oldPath);
        $newRel = 'course_' . $courseId . '/import_' . bin2hex(random_bytes(3)) . '_' . $basename;
        $copyFile($oldPath, $newRel);
        if (isset($pathMap[$oldPath])) {
            $pdo->prepare('INSERT INTO course_files (course_id, title, file_path, mime_type, uploaded_by) VALUES (?, ?, ?, ?, ?)')
                ->execute([$courseId, $f['title'] ?? $basename, $pathMap[$oldPath], $f['mime_type'] ?? null, null]);
        }
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($filesRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($filesRoot) + 1));
        if (isset($pathMap[$rel])) {
            continue;
        }
        if (str_starts_with($rel, 'course_') || str_starts_with($rel, 'imscc/') || str_starts_with($rel, 'submissions/')) {
            $basename = basename($rel);
            $newRel = 'course_' . $courseId . '/import_' . bin2hex(random_bytes(3)) . '_' . $basename;
            $copyFile($rel, $newRel);
        }
    }

    return $pathMap;
}

function parse_course_import_upload(array $config, array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed.');
    }
    $name = strtolower($file['name'] ?? '');
    $tmp = $file['tmp_name'];
    if (str_ends_with($name, '.zip')) {
        $extract = $config['upload_dir'] . '/json_import_' . bin2hex(random_bytes(4));
        mkdir($extract, 0755, true);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            throw new RuntimeException('Could not open zip archive.');
        }
        $zip->extractTo($extract);
        $zip->close();
        $jsonPath = $extract . '/course.json';
        if (!is_file($jsonPath)) {
            throw new RuntimeException('Zip must contain course.json at the root.');
        }
        $data = json_decode((string) file_get_contents($jsonPath), true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid course.json.');
        }
        $filesRoot = is_dir($extract . '/files') ? $extract . '/files' : null;
        return ['data' => $data, 'files_root' => $filesRoot, 'cleanup' => $extract];
    }
    $data = json_decode((string) file_get_contents($tmp), true);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid JSON file.');
    }
    return ['data' => $data, 'files_root' => null, 'cleanup' => null];
}