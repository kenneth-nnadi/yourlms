<?php
declare(strict_types=1);

function config(): array
{
    global $config;
    return $config;
}

function url(string $path = ''): string
{
    $base = rtrim(config()['base_url'], '/');
    if ($path === '') {
        return $base ?: '/';
    }
    return $base . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function render_user_html(?string $html): string
{
    if ($html === null || $html === '') {
        return '';
    }
    $allowed = '<p><br><strong><em><b><i><ul><ol><li><a><h2><h3><h4><blockquote><code><pre>';
    return strip_tags($html, $allowed);
}

function content_format_value(string $format): string
{
    return $format === 'html' ? 'html' : 'text';
}

function render_rich_content(?string $content, string $format = 'text'): void
{
    if ($format === 'html') {
        echo '<div class="user-html-content">';
        echo render_user_html($content);
        echo '</div>';
        return;
    }
    echo nl2br(e($content ?? ''));
}

function rich_content_excerpt(?string $content, string $format = 'text', int $limit = 160): string
{
    if ($content === null || $content === '') {
        return '';
    }
    $text = $format === 'html' ? strip_tags($content) : $content;
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    if (mb_strlen($text) <= $limit) {
        return $text;
    }
    return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
}

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }
    $val = $_SESSION['flash'][$key] ?? null;
    unset($_SESSION['flash'][$key]);
    return $val;
}

function app_datetime(?string $dt): ?DateTimeImmutable
{
    if (!$dt) {
        return null;
    }
    return new DateTimeImmutable($dt, new DateTimeZone(date_default_timezone_get()));
}

function format_datetime(?string $dt, string $fmt = 'M j, Y g:ia'): string
{
    $parsed = app_datetime($dt);
    if (!$parsed) {
        return '—';
    }
    return $parsed->format($fmt);
}

function time_ago(string $dt): string
{
    $then = app_datetime($dt);
    if (!$then) {
        return '—';
    }
    $now = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    $diff = $now->getTimestamp() - $then->getTimestamp();
    if ($diff < 0) {
        return 'just now';
    }
    if ($diff < 45) {
        return 'just now';
    }
    if ($diff < 3600) {
        $mins = max(1, (int) floor($diff / 60));
        return $mins . ' min ago';
    }
    if ($diff < 86400) {
        $hrs = (int) floor($diff / 3600);
        $mins = (int) floor(($diff % 3600) / 60);
        if ($mins > 0 && $hrs < 12) {
            return $hrs . ' hr' . ($hrs === 1 ? '' : 's') . ' ' . $mins . ' min ago';
        }
        return $hrs . ' hr' . ($hrs === 1 ? '' : 's') . ' ago';
    }
    if ($diff < 604800) {
        $days = (int) floor($diff / 86400);
        return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }
    return format_datetime($dt);
}

function course_or_404(PDO $pdo, int $courseId): array
{
    $stmt = $pdo->prepare('SELECT * FROM courses WHERE id = ?');
    $stmt->execute([$courseId]);
    $course = $stmt->fetch();
    if (!$course) {
        http_response_code(404);
        die('Course not found.');
    }
    return $course;
}

function user_enrolled_in(PDO $pdo, int $courseId, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM enrollments WHERE course_id = ? AND user_id = ?');
    $stmt->execute([$courseId, $userId]);
    return (bool) $stmt->fetch();
}

function course_is_published(array $course): bool
{
    return (bool) ($course['published'] ?? 0);
}

function module_is_published(array $module): bool
{
    return (bool) ($module['published'] ?? 0);
}

function item_is_published(array $item): bool
{
    return (bool) ($item['published'] ?? 1);
}

function canvas_workflow_is_published(string $state): bool
{
    $state = strtolower(trim($state));
    return in_array($state, ['active', 'published'], true);
}

function item_visible_to_student(array $item, array $module): bool
{
    return module_is_published($module) && item_is_published($item);
}

function ref_visible_to_student(PDO $pdo, int $courseId, string $itemType, int $refId): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1 FROM module_items mi
         JOIN modules m ON m.id = mi.module_id
         WHERE m.course_id = ? AND mi.item_type = ? AND mi.ref_id = ?
           AND m.published = 1 AND mi.published = 1
         LIMIT 1"
    );
    $stmt->execute([$courseId, $itemType, $refId]);
    return (bool) $stmt->fetch();
}

function require_course_access(PDO $pdo, int $courseId, array $user): array
{
    $course = course_or_404($pdo, $courseId);
    $preview = user_in_student_preview($pdo, $courseId, $user);

    if (!$preview && user_is_site_instructor($user)) {
        return $course;
    }

    if (!$preview && user_is_course_staff($pdo, $courseId, $user)) {
        return $course;
    }

    if (!user_enrolled_in($pdo, $courseId, $user['id']) && !user_is_site_instructor($user)) {
        flash('error', 'You are not enrolled in this course.');
        redirect('/dashboard.php');
    }

    if (!course_is_published($course)) {
        flash('error', $preview
            ? 'Students cannot access this course until it is published.'
            : 'This course is not published yet.');
        redirect('/dashboard.php');
    }

    return $course;
}

function require_published_ref_access(PDO $pdo, int $courseId, array $user, string $itemType, int $refId): void
{
    if (!user_can_view_unpublished($pdo, $courseId, $user) && !ref_visible_to_student($pdo, $courseId, $itemType, $refId)) {
        http_response_code(404);
        die('Not found.');
    }
}

function fetch_course_modules(PDO $pdo, int $courseId, array $user): array
{
    $sql = 'SELECT * FROM modules WHERE course_id = ?';
    if (!user_can_view_unpublished($pdo, $courseId, $user)) {
        $sql .= ' AND published = 1';
    }
    $sql .= ' ORDER BY position ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$courseId]);
    return $stmt->fetchAll();
}

function fetch_module_items(PDO $pdo, int $moduleId, array $user, array $module): array
{
    $sql = 'SELECT * FROM module_items WHERE module_id = ?';
    if (!user_can_view_unpublished($pdo, (int) $module['course_id'], $user)) {
        $sql .= ' AND published = 1';
    }
    $sql .= ' ORDER BY position ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$moduleId]);
    return $stmt->fetchAll();
}

function publish_status_badge(bool $published, string $kind = 'module'): string
{
    if ($published) {
        return '<span class="publish-badge publish-badge-on" title="Published">✓</span>';
    }
    return '<span class="publish-badge publish-badge-off" title="Unpublished">☁</span>';
}

function courses_for_dashboard(PDO $pdo, array $user): array
{
    if (user_is_site_instructor($user)) {
        return $pdo->query('SELECT * FROM courses ORDER BY created_at ASC')->fetchAll();
    }
    $stmt = $pdo->prepare(
        'SELECT c.*, e.role AS enrollment_role FROM courses c
         JOIN enrollments e ON e.course_id = c.id
         WHERE e.user_id = ?
           AND (c.published = 1 OR e.role IN (\'instructor\', \'ta\'))
         ORDER BY c.created_at ASC'
    );
    $stmt->execute([$user['id']]);
    return $stmt->fetchAll();
}

function item_link(int $courseId, array $item): ?string
{
    return match ($item['item_type']) {
        'assignment' => $item['ref_id'] ? url("assignment.php?course_id={$courseId}&id={$item['ref_id']}") : null,
        'quiz' => $item['ref_id'] ? url("quiz.php?course_id={$courseId}&id={$item['ref_id']}") : null,
        'discussion' => $item['ref_id'] ? url("discussion.php?course_id={$courseId}&id={$item['ref_id']}") : null,
        'announcement' => $item['ref_id'] ? url("announcements.php?course_id={$courseId}&id={$item['ref_id']}") : null,
        'page' => url("page.php?course_id={$courseId}&item_id={$item['id']}"),
        'file' => $item['file_path'] ? url('download.php?item_id=' . $item['id']) : null,
        'external' => $item['content'] ?: null,
        'lti' => $item['ref_id'] ? url("lti-launch.php?course_id={$courseId}&tool_id={$item['ref_id']}") : null,
        default => null,
    };
}

function search_staff_bypass_sql(array $user): array
{
    if (user_is_site_instructor($user)) {
        return ['sql' => '1=1', 'params' => []];
    }
    $previewId = active_student_preview_course_id();
    $params = [(int) $user['id']];
    $previewSql = '';
    if ($previewId) {
        $previewSql = ' AND c.id != ?';
        $params[] = $previewId;
    }
    return [
        'sql' => "EXISTS (
            SELECT 1 FROM enrollments e_staff
            WHERE e_staff.course_id = c.id AND e_staff.user_id = ?
              AND e_staff.role IN ('instructor', 'ta')
        ){$previewSql}",
        'params' => $params,
    ];
}

function search_published_ref_exists_sql(string $itemType, string $refCol): string
{
    return "EXISTS (
        SELECT 1 FROM module_items mi
        JOIN modules m ON m.id = mi.module_id
        WHERE m.course_id = c.id AND mi.item_type = '{$itemType}' AND mi.ref_id = {$refCol}
          AND m.published = 1 AND mi.published = 1
    )";
}

function course_search(PDO $pdo, array $user, string $query, ?int $courseId = null, int $limit = 40): array
{
    $query = trim($query);
    if ($query === '') {
        return [];
    }
    $like = '%' . $query . '%';
    $results = [];
    $isSiteInstructor = user_is_site_instructor($user);

    $courseFilter = '';
    $courseParams = [];
    if ($courseId) {
        $courseFilter = ' AND c.id = ?';
        $courseParams[] = $courseId;
    }

    if ($isSiteInstructor) {
        $enrollClause = '1=1';
        $enrollParams = [];
        $staffBypass = ['sql' => '1=1', 'params' => []];
    } else {
        $enrollClause = 'EXISTS (SELECT 1 FROM enrollments e WHERE e.course_id = c.id AND e.user_id = ?)';
        $enrollParams = [(int) $user['id']];
        $staffBypass = search_staff_bypass_sql($user);
    }

    $publishedStudentGate = $isSiteInstructor
        ? '1=1'
        : '(' . $staffBypass['sql'] . ' OR (c.published = 1))';

    $refTypes = [
        ['assignment', 'assignments', 'a', 'a.title', 'a.id', 'assignment.php?course_id=%d&id=%d'],
        ['quiz', 'quizzes', 'q', 'q.title', 'q.id', 'quiz.php?course_id=%d&id=%d'],
        ['discussion', 'discussions', 'd', 'd.title', 'd.id', 'discussion.php?course_id=%d&id=%d'],
    ];

    foreach ($refTypes as [$kind, $table, $alias, $col, $idCol, $pathTpl]) {
        $refPublished = $isSiteInstructor
            ? '1=1'
            : '(' . $staffBypass['sql'] . ' OR ' . search_published_ref_exists_sql($kind, "{$alias}.id") . ')';
        $sql = "SELECT {$alias}.id, {$alias}.title, c.id AS course_id, c.code
                FROM {$table} {$alias}
                JOIN courses c ON c.id = {$alias}.course_id
                WHERE {$enrollClause} AND {$col} LIKE ?
                  AND {$publishedStudentGate} AND {$refPublished}{$courseFilter}
                ORDER BY {$alias}.title LIMIT " . (int) $limit;
        $stmt = $pdo->prepare($sql);
        $params = array_merge(
            $enrollParams,
            [$like],
            $isSiteInstructor ? [] : $staffBypass['params'],
            $isSiteInstructor ? [] : $staffBypass['params'],
            $courseParams
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $results[] = [
                'kind' => $kind,
                'title' => $row['title'],
                'course_id' => (int) $row['course_id'],
                'code' => $row['code'],
                'href' => url(sprintf($pathTpl, $row['course_id'], $row['id'])),
            ];
        }
    }

    $annPublished = $isSiteInstructor
        ? '1=1'
        : '(' . $staffBypass['sql'] . ' OR (c.published = 1 AND an.published = 1))';
    $annSql = "SELECT an.id, an.title, c.id AS course_id, c.code
               FROM announcements an
               JOIN courses c ON c.id = an.course_id
               WHERE {$enrollClause} AND an.title LIKE ?
                 AND {$publishedStudentGate} AND {$annPublished}{$courseFilter}
               ORDER BY an.title LIMIT " . (int) $limit;
    $stmt = $pdo->prepare($annSql);
    $annParams = array_merge(
        $enrollParams,
        [$like],
        $isSiteInstructor ? [] : $staffBypass['params'],
        $isSiteInstructor ? [] : $staffBypass['params'],
        $courseParams
    );
    $stmt->execute($annParams);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = [
            'kind' => 'announcement',
            'title' => $row['title'],
            'course_id' => (int) $row['course_id'],
            'code' => $row['code'],
            'href' => url(sprintf('announcements.php?course_id=%d&id=%d', $row['course_id'], $row['id'])),
        ];
    }

    $pagePublished = $isSiteInstructor
        ? '1=1'
        : '(' . $staffBypass['sql'] . ' OR (m.published = 1 AND mi.published = 1))';
    $pageSql = "SELECT mi.id, mi.title, c.id AS course_id, c.code
                FROM module_items mi
                JOIN modules m ON m.id = mi.module_id
                JOIN courses c ON c.id = m.course_id
                WHERE mi.item_type = 'page' AND (mi.title LIKE ? OR mi.content LIKE ?)
                  AND {$enrollClause} AND {$publishedStudentGate} AND {$pagePublished}{$courseFilter}
                ORDER BY mi.title LIMIT " . (int) $limit;
    $stmt = $pdo->prepare($pageSql);
    $pageParams = array_merge(
        [$like, $like],
        $enrollParams,
        $isSiteInstructor ? [] : $staffBypass['params'],
        $isSiteInstructor ? [] : $staffBypass['params'],
        $courseParams
    );
    $stmt->execute($pageParams);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = [
            'kind' => 'page',
            'title' => $row['title'],
            'course_id' => (int) $row['course_id'],
            'code' => $row['code'],
            'href' => url("page.php?course_id={$row['course_id']}&item_id={$row['id']}"),
        ];
    }

    $fileModulePublished = $isSiteInstructor
        ? '1=1'
        : '(' . $staffBypass['sql'] . ' OR (m.published = 1 AND mi.published = 1))';
    $moduleFileSql = "SELECT mi.id, mi.title, c.id AS course_id, c.code
                      FROM module_items mi
                      JOIN modules m ON m.id = mi.module_id
                      JOIN courses c ON c.id = m.course_id
                      WHERE mi.item_type = 'file' AND mi.title LIKE ?
                        AND {$enrollClause} AND {$publishedStudentGate} AND {$fileModulePublished}{$courseFilter}
                      ORDER BY mi.title LIMIT " . (int) $limit;
    $stmt = $pdo->prepare($moduleFileSql);
    $modFileParams = array_merge(
        $enrollParams,
        [$like],
        $isSiteInstructor ? [] : $staffBypass['params'],
        $isSiteInstructor ? [] : $staffBypass['params'],
        $courseParams
    );
    $stmt->execute($modFileParams);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = [
            'kind' => 'file',
            'title' => $row['title'],
            'course_id' => (int) $row['course_id'],
            'code' => $row['code'],
            'href' => url('download.php?item_id=' . $row['id']),
        ];
    }

    $courseFileGate = $isSiteInstructor
        ? '1=1'
        : '(' . $staffBypass['sql'] . ' OR c.published = 1)';
    $courseFileSql = "SELECT cf.id, cf.title, c.id AS course_id, c.code
                      FROM course_files cf
                      JOIN courses c ON c.id = cf.course_id
                      WHERE cf.title LIKE ? AND {$enrollClause} AND {$courseFileGate}{$courseFilter}
                      ORDER BY cf.title LIMIT " . (int) $limit;
    $stmt = $pdo->prepare($courseFileSql);
    $cfParams = array_merge(
        $enrollParams,
        [$like],
        $isSiteInstructor ? [] : $staffBypass['params'],
        $courseParams
    );
    $stmt->execute($cfParams);
    foreach ($stmt->fetchAll() as $row) {
        $results[] = [
            'kind' => 'file',
            'title' => $row['title'],
            'course_id' => (int) $row['course_id'],
            'code' => $row['code'],
            'href' => url('download.php?file_id=' . $row['id']),
        ];
    }

    return array_slice($results, 0, $limit);
}

function assignment_groups_for_course(PDO $pdo, int $courseId): array
{
    $stmt = $pdo->prepare('SELECT * FROM assignment_groups WHERE course_id = ? ORDER BY position, id');
    $stmt->execute([$courseId]);
    return $stmt->fetchAll();
}

function weighted_grade_summary(array $groups, array $rows): ?array
{
    if (!$groups) {
        return null;
    }
    $totalWeight = array_sum(array_map(fn($g) => (float) $g['weight'], $groups));
    if ($totalWeight <= 0) {
        return null;
    }

    $byGroup = [];
    foreach ($groups as $g) {
        $byGroup[(int) $g['id']] = ['earned' => 0.0, 'possible' => 0.0, 'name' => $g['name'], 'weight' => (float) $g['weight']];
    }
    $ungrouped = ['earned' => 0.0, 'possible' => 0.0];

    foreach ($rows as $row) {
        $score = (float) ($row['score'] ?? 0);
        $possible = (float) $row['points'];
        $gid = isset($row['group_id']) ? (int) $row['group_id'] : 0;
        if ($gid && isset($byGroup[$gid])) {
            $byGroup[$gid]['earned'] += $score;
            $byGroup[$gid]['possible'] += $possible;
        } else {
            $ungrouped['earned'] += $score;
            $ungrouped['possible'] += $possible;
        }
    }

    $weighted = 0.0;
    $parts = [];
    foreach ($byGroup as $g) {
        if ($g['possible'] <= 0) {
            continue;
        }
        $pct = ($g['earned'] / $g['possible']) * 100;
        $weighted += $pct * ($g['weight'] / $totalWeight);
        $parts[] = $g['name'] . ': ' . number_format($pct, 1) . '% × ' . number_format($g['weight'], 0) . '%';
    }

    return [
        'weighted_percent' => round($weighted, 2),
        'total_weight' => $totalWeight,
        'parts' => $parts,
        'ungrouped' => $ungrouped,
    ];
}

function item_label(array $item): string
{
    return match ($item['item_type']) {
        'assignment' => 'Assignment · Due ' . format_datetime($item['due_at']) . ' · ' . ($item['points'] ?? 0) . ' pts',
        'quiz' => 'Quiz · ' . ($item['points'] ?? 0) . ' pts',
        'discussion' => 'Discussion',
        'announcement' => 'Announcement',
        'page' => 'Page',
        'file' => 'File',
        'external' => 'External link',
        'lti' => 'External tool',
        default => $item['item_type'],
    };
}

function ensure_upload_dir(string $dir): bool
{
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
    }
    if (!is_writable($dir)) {
        @chmod($dir, 0777);
    }
    return is_writable($dir);
}

function upload_dir_writable(?array $config = null): bool
{
    $config ??= config();
    return ensure_upload_dir($config['upload_dir']);
}

function handle_upload(array $file, string $destDir, int $maxMb): array
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msg = match ($file['error']) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large for this server.',
            UPLOAD_ERR_PARTIAL => 'Upload was interrupted — try again.',
            UPLOAD_ERR_NO_FILE => 'No file was selected.',
            default => 'Upload failed (error code ' . (int) $file['error'] . ').',
        };
        return ['ok' => false, 'error' => $msg];
    }
    if ($file['size'] > $maxMb * 1024 * 1024) {
        return ['ok' => false, 'error' => "File exceeds {$maxMb}MB limit."];
    }

    if (!ensure_upload_dir($destDir)) {
        return [
            'ok' => false,
            'error' => 'The uploads folder is not writable by the web server. On XAMPP, run: chmod -R 777 uploads/',
        ];
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safe = bin2hex(random_bytes(8)) . ($ext ? '.' . strtolower($ext) : '');
    $dest = rtrim($destDir, '/') . '/' . $safe;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return [
            'ok' => false,
            'error' => 'Could not save file to ' . basename($destDir) . '/. Check that uploads/ is writable by Apache.',
        ];
    }
    return ['ok' => true, 'path' => $safe, 'name' => $file['name'], 'mime' => $file['type'] ?? null];
}

function save_assignment_attachment(PDO $pdo, int $courseId, int $assignmentId, array $file, ?array $config = null): array
{
    $config ??= config();
    $dest = $config['upload_dir'] . '/assignments/course_' . $courseId . '/assignment_' . $assignmentId;
    $upload = handle_upload($file, $dest, (int) $config['upload_max_mb']);
    if (!$upload['ok']) {
        return $upload;
    }
    $relPath = 'assignments/course_' . $courseId . '/assignment_' . $assignmentId . '/' . $upload['path'];
    $pdo->prepare('UPDATE assignments SET attachment_path = ?, attachment_name = ? WHERE id = ? AND course_id = ?')
        ->execute([$relPath, $upload['name'], $assignmentId, $courseId]);
    return ['ok' => true];
}

function assignment_is_past_due(array $assignment): bool
{
    if (empty($assignment['due_at'])) {
        return false;
    }
    return strtotime($assignment['due_at']) < time();
}

function assignment_allows_submission(array $assignment): bool
{
    if (!assignment_is_past_due($assignment)) {
        return true;
    }
    return !(bool) ($assignment['lock_after_due'] ?? 0);
}

function submission_would_be_late(array $assignment): bool
{
    return assignment_is_past_due($assignment);
}

function quiz_is_past_due(array $quiz): bool
{
    if (empty($quiz['due_at'])) {
        return false;
    }
    return strtotime($quiz['due_at']) < time();
}

function quiz_allows_attempt(PDO $pdo, array $quiz, int $userId): bool
{
    if (quiz_is_past_due($quiz) && (bool) ($quiz['lock_after_due'] ?? 0)) {
        return false;
    }
    $max = (int) ($quiz['max_attempts'] ?? 1);
    if ($max === 0) {
        return true;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ? AND user_id = ?');
    $stmt->execute([(int) $quiz['id'], $userId]);
    return (int) $stmt->fetchColumn() < $max;
}

function quiz_attempt_count(PDO $pdo, int $quizId, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ? AND user_id = ?');
    $stmt->execute([$quizId, $userId]);
    return (int) $stmt->fetchColumn();
}

function course_students(PDO $pdo, int $courseId): array
{
    $stmt = $pdo->prepare(
        "SELECT u.id, u.full_name, u.email FROM users u
         JOIN enrollments e ON e.user_id = u.id
         WHERE e.course_id = ? AND e.role = 'student'
         ORDER BY u.full_name"
    );
    $stmt->execute([$courseId]);
    return $stmt->fetchAll();
}

function announcements_for_user(PDO $pdo, array $user, int $limit = 5): array
{
    if (user_is_site_instructor($user)) {
        $stmt = $pdo->prepare(
            "SELECT an.*, c.code, c.name, c.color FROM announcements an
             JOIN courses c ON c.id = an.course_id
             WHERE an.published = 1
             ORDER BY an.created_at DESC LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    $stmt = $pdo->prepare(
        "SELECT an.*, c.code, c.name, c.color FROM announcements an
         JOIN courses c ON c.id = an.course_id
         JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
         WHERE an.published = 1 AND c.published = 1
         ORDER BY an.created_at DESC LIMIT ?"
    );
    $stmt->bindValue(1, $user['id'], PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function dashboard_todo_items(PDO $pdo, array $user, int $limit = 10): array
{
    $items = [];
    $isSiteInstructor = user_is_site_instructor($user);
    $now = db_now_sql();

    if ($isSiteInstructor) {
        $assignments = $pdo->query(
            "SELECT a.id, a.title, a.due_at, a.points, a.course_id, c.code, c.color, 'assignment' AS item_kind
             FROM assignments a JOIN courses c ON c.id = a.course_id
             WHERE a.due_at IS NULL OR a.due_at >= {$now}"
        )->fetchAll();
        $quizzes = $pdo->query(
            "SELECT q.id, q.title, q.due_at, q.points, q.course_id, c.code, c.color, 'quiz' AS item_kind
             FROM quizzes q JOIN courses c ON c.id = q.course_id
             WHERE q.due_at IS NOT NULL AND q.due_at >= {$now}"
        )->fetchAll();
    } else {
        $assignments = $pdo->prepare(
            "SELECT a.id, a.title, a.due_at, a.points, a.course_id, c.code, c.color, 'assignment' AS item_kind
             FROM assignments a
             JOIN courses c ON c.id = a.course_id
             JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
             WHERE c.published = 1 AND e.role = 'student'
               AND (a.due_at IS NULL OR a.due_at >= {$now})
               AND EXISTS (
                 SELECT 1 FROM module_items mi
                 JOIN modules m ON m.id = mi.module_id
                 WHERE m.course_id = c.id AND mi.item_type = 'assignment' AND mi.ref_id = a.id
                   AND m.published = 1 AND mi.published = 1
               )"
        );
        $assignments->execute([$user['id']]);
        $assignments = $assignments->fetchAll();

        $quizzes = $pdo->prepare(
            "SELECT q.id, q.title, q.due_at, q.points, q.course_id, c.code, c.color, 'quiz' AS item_kind
             FROM quizzes q
             JOIN courses c ON c.id = q.course_id
             JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
             WHERE c.published = 1 AND e.role = 'student'
               AND q.due_at IS NOT NULL AND q.due_at >= {$now}
               AND EXISTS (
                 SELECT 1 FROM module_items mi
                 JOIN modules m ON m.id = mi.module_id
                 WHERE m.course_id = c.id AND mi.item_type = 'quiz' AND mi.ref_id = q.id
                   AND m.published = 1 AND mi.published = 1
               )"
        );
        $quizzes->execute([$user['id']]);
        $quizzes = $quizzes->fetchAll();
    }

    foreach (array_merge($assignments, $quizzes) as $row) {
        $dueTs = $row['due_at'] ? strtotime($row['due_at']) : PHP_INT_MAX;
        $items[] = array_merge($row, ['due_ts' => $dueTs]);
    }
    usort($items, fn($a, $b) => $a['due_ts'] <=> $b['due_ts']);
    return array_slice($items, 0, $limit);
}

function dashboard_calendar_items(PDO $pdo, array $user, int $year, int $month): array
{
    $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
    $endDate = new DateTimeImmutable($start);
    $end = $endDate->modify('last day of this month')->format('Y-m-d 23:59:59');

    $isSiteInstructor = user_is_site_instructor($user);

    if ($isSiteInstructor) {
        $assignments = $pdo->prepare(
            "SELECT a.id, a.title, a.due_at, a.course_id, c.code, c.color, 'assignment' AS item_kind
             FROM assignments a
             JOIN courses c ON c.id = a.course_id
             WHERE a.due_at IS NOT NULL AND a.due_at BETWEEN ? AND ?"
        );
        $assignments->execute([$start, $end]);
        $assignments = $assignments->fetchAll();

        $quizzes = $pdo->prepare(
            "SELECT q.id, q.title, q.due_at, q.course_id, c.code, c.color, 'quiz' AS item_kind
             FROM quizzes q
             JOIN courses c ON c.id = q.course_id
             WHERE q.due_at IS NOT NULL AND q.due_at BETWEEN ? AND ?"
        );
        $quizzes->execute([$start, $end]);
        $quizzes = $quizzes->fetchAll();
    } else {
        $assignments = $pdo->prepare(
            "SELECT a.id, a.title, a.due_at, a.course_id, c.code, c.color, 'assignment' AS item_kind
             FROM assignments a
             JOIN courses c ON c.id = a.course_id
             JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
             WHERE c.published = 1 AND e.role = 'student'
               AND a.due_at IS NOT NULL AND a.due_at BETWEEN ? AND ?
               AND EXISTS (
                 SELECT 1 FROM module_items mi
                 JOIN modules m ON m.id = mi.module_id
                 WHERE m.course_id = c.id AND mi.item_type = 'assignment' AND mi.ref_id = a.id
                   AND m.published = 1 AND mi.published = 1
               )"
        );
        $assignments->execute([$user['id'], $start, $end]);
        $assignments = $assignments->fetchAll();

        $quizzes = $pdo->prepare(
            "SELECT q.id, q.title, q.due_at, q.course_id, c.code, c.color, 'quiz' AS item_kind
             FROM quizzes q
             JOIN courses c ON c.id = q.course_id
             JOIN enrollments e ON e.course_id = c.id AND e.user_id = ?
             WHERE c.published = 1 AND e.role = 'student'
               AND q.due_at IS NOT NULL AND q.due_at BETWEEN ? AND ?
               AND EXISTS (
                 SELECT 1 FROM module_items mi
                 JOIN modules m ON m.id = mi.module_id
                 WHERE m.course_id = c.id AND mi.item_type = 'quiz' AND mi.ref_id = q.id
                   AND m.published = 1 AND mi.published = 1
               )"
        );
        $quizzes->execute([$user['id'], $start, $end]);
        $quizzes = $quizzes->fetchAll();
    }

    $byDate = [];
    foreach (array_merge($assignments, $quizzes) as $row) {
        $day = date('Y-m-d', strtotime($row['due_at']));
        $byDate[$day][] = $row;
    }
    ksort($byDate);
    return $byDate;
}

function discussion_build_tree(array $posts): array
{
    $children = [];
    foreach ($posts as $p) {
        $parentKey = $p['parent_id'] ? (int) $p['parent_id'] : 0;
        $children[$parentKey][] = $p;
    }
    $build = static function (int $parentId) use (&$build, $children): array {
        $nodes = [];
        foreach ($children[$parentId] ?? [] as $p) {
            $p['children'] = $build((int) $p['id']);
            $nodes[] = $p;
        }
        return $nodes;
    };
    return $build(0);
}

function compute_quiz_score(array $quiz, array $questions, array $answers, ?array $essayScores = null): array
{
    require_once __DIR__ . '/quiz_types.php';
    return compute_quiz_score_extended($quiz, $questions, $answers, $essayScores);
}