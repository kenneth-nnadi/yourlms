<?php
declare(strict_types=1);

function admin_module_in_course(PDO $pdo, int $moduleId, int $courseId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM modules WHERE id = ? AND course_id = ?');
    $stmt->execute([$moduleId, $courseId]);
    return $stmt->fetch() ?: null;
}

function admin_item_in_course(PDO $pdo, int $itemId, int $courseId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT mi.*, m.course_id, m.id AS module_id FROM module_items mi
         JOIN modules m ON m.id = mi.module_id
         WHERE mi.id = ? AND m.course_id = ?'
    );
    $stmt->execute([$itemId, $courseId]);
    return $stmt->fetch() ?: null;
}

function admin_swap_position(PDO $pdo, string $table, int $id, string $direction, string $scopeCol, int $scopeId): void
{
    $allowed = [
        'modules' => ['course_id'],
        'module_items' => ['module_id'],
        'quiz_questions' => ['quiz_id'],
    ];
    if (!isset($allowed[$table]) || $allowed[$table][0] !== $scopeCol) {
        return;
    }

    $stmt = $pdo->prepare("SELECT id, position FROM {$table} WHERE id = ? AND {$scopeCol} = ?");
    $stmt->execute([$id, $scopeId]);
    $current = $stmt->fetch();
    if (!$current) {
        return;
    }

    $pos = (int) $current['position'];
    $op = $direction === 'up' ? '<' : '>';
    $order = $direction === 'up' ? 'DESC' : 'ASC';
    $neighbor = $pdo->prepare(
        "SELECT id, position FROM {$table} WHERE {$scopeCol} = ? AND position {$op} ? ORDER BY position {$order} LIMIT 1"
    );
    $neighbor->execute([$scopeId, $pos]);
    $other = $neighbor->fetch();
    if (!$other) {
        return;
    }

    $pdo->prepare("UPDATE {$table} SET position = ? WHERE id = ?")->execute([$other['position'], $current['id']]);
    $pdo->prepare("UPDATE {$table} SET position = ? WHERE id = ?")->execute([$pos, $other['id']]);
}

function admin_apply_module_order(PDO $pdo, int $courseId, array $moduleIds): void
{
    $update = $pdo->prepare('UPDATE modules SET position = ? WHERE id = ? AND course_id = ?');
    foreach (array_values($moduleIds) as $pos => $id) {
        $update->execute([(int) $pos, (int) $id, $courseId]);
    }
}

function admin_apply_item_order(PDO $pdo, int $courseId, array $itemOrders): void
{
    $update = $pdo->prepare(
        'UPDATE module_items mi
         JOIN modules m ON m.id = mi.module_id
         SET mi.position = ?
         WHERE mi.id = ? AND mi.module_id = ? AND m.course_id = ?'
    );
    foreach ($itemOrders as $moduleId => $itemIds) {
        if (!admin_module_in_course($pdo, (int) $moduleId, $courseId)) {
            continue;
        }
        foreach (array_values($itemIds) as $pos => $itemId) {
            $update->execute([(int) $pos, (int) $itemId, (int) $moduleId, $courseId]);
        }
    }
}

function teach_admin_courses(PDO $pdo, array $user): array
{
    if (user_is_site_instructor($user)) {
        return $pdo->query('SELECT * FROM courses ORDER BY created_at DESC')->fetchAll();
    }
    $stmt = $pdo->prepare(
        "SELECT c.* FROM courses c
         JOIN enrollments e ON e.course_id = c.id
         WHERE e.user_id = ? AND e.role IN ('instructor', 'ta')
         ORDER BY c.created_at DESC"
    );
    $stmt->execute([$user['id']]);
    return $stmt->fetchAll();
}

function ref_module_items(PDO $pdo, int $courseId, string $itemType, int $refId): array
{
    $stmt = $pdo->prepare(
        "SELECT mi.*, m.title AS module_title, m.published AS module_published
         FROM module_items mi
         JOIN modules m ON m.id = mi.module_id
         WHERE m.course_id = ? AND mi.item_type = ? AND mi.ref_id = ?
         ORDER BY m.position, mi.position"
    );
    $stmt->execute([$courseId, $itemType, $refId]);
    return $stmt->fetchAll();
}

function ref_visible_to_students(PDO $pdo, int $courseId, string $itemType, int $refId): bool
{
    return ref_visible_to_student($pdo, $courseId, $itemType, $refId);
}

function publish_course(PDO $pdo, int $courseId): void
{
    $pdo->prepare('UPDATE courses SET published = 1 WHERE id = ?')->execute([$courseId]);
}

function add_ref_to_module(PDO $pdo, int $courseId, int $moduleId, string $itemType, int $refId, string $title, bool $publishItem = true): ?int
{
    if (!admin_module_in_course($pdo, $moduleId, $courseId)) {
        return null;
    }
    $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM module_items WHERE module_id = ?');
    $posStmt->execute([$moduleId]);
    $pos = (int) $posStmt->fetchColumn();
    $insert = $pdo->prepare(
        'INSERT INTO module_items (module_id, title, item_type, ref_id, position, published) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([$moduleId, mb_substr($title, 0, 255), $itemType, $refId, $pos, $publishItem ? 1 : 0]);
    $itemId = (int) $pdo->lastInsertId();
    if ($itemId <= 0) {
        $lookup = $pdo->prepare(
            'SELECT id FROM module_items WHERE module_id = ? AND item_type = ? AND ref_id = ? ORDER BY id DESC LIMIT 1'
        );
        $lookup->execute([$moduleId, $itemType, $refId]);
        $itemId = (int) $lookup->fetchColumn();
    }
    if ($publishItem) {
        $pdo->prepare('UPDATE modules SET published = 1 WHERE id = ?')->execute([$moduleId]);
        publish_course($pdo, $courseId);
    }
    return $itemId > 0 ? $itemId : null;
}

function ref_publish_meta(PDO $pdo, int $courseId, string $itemType, int $refId): ?array
{
    $sql = match ($itemType) {
        'assignment' => 'SELECT title, description AS body, description_format AS body_format FROM assignments WHERE id = ? AND course_id = ?',
        'quiz' => 'SELECT title, description AS body, description_format AS body_format FROM quizzes WHERE id = ? AND course_id = ?',
        'discussion' => 'SELECT title, prompt AS body, prompt_format AS body_format FROM discussions WHERE id = ? AND course_id = ?',
        default => null,
    };
    if (!$sql) {
        return null;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$refId, $courseId]);
    return $stmt->fetch() ?: null;
}

function notify_students_ref_published(PDO $pdo, int $courseId, string $itemType, int $refId, ?int $exceptUserId = null): void
{
    require_once __DIR__ . '/notifications.php';
    $meta = ref_publish_meta($pdo, $courseId, $itemType, $refId);
    if (!$meta) {
        return;
    }
    $link = match ($itemType) {
        'assignment' => "assignment.php?course_id={$courseId}&id={$refId}",
        'quiz' => "quiz.php?course_id={$courseId}&id={$refId}",
        'discussion' => "discussion.php?course_id={$courseId}&id={$refId}",
        default => null,
    };
    $label = match ($itemType) {
        'assignment' => 'assignment',
        'quiz' => 'quiz',
        'discussion' => 'discussion',
        default => 'item',
    };
    notify_course_students(
        $pdo,
        $courseId,
        $itemType,
        'New ' . $label . ': ' . $meta['title'],
        rich_content_excerpt($meta['body'] ?? '', $meta['body_format'] ?? 'text', 200),
        $link,
        $exceptUserId
    );
}

function set_ref_published(PDO $pdo, int $courseId, string $itemType, int $refId, bool $published): bool
{
    $items = ref_module_items($pdo, $courseId, $itemType, $refId);
    if (!$items) {
        return false;
    }
    foreach ($items as $item) {
        $pdo->prepare('UPDATE module_items SET published = ? WHERE id = ?')->execute([$published ? 1 : 0, $item['id']]);
        if ($published) {
            $pdo->prepare('UPDATE modules SET published = 1 WHERE id = ?')->execute([$item['module_id']]);
        }
    }
    if ($published) {
        publish_course($pdo, $courseId);
    }
    return true;
}

function go_live_ref(PDO $pdo, int $courseId, string $itemType, int $refId): bool
{
    return set_ref_published($pdo, $courseId, $itemType, $refId, true);
}

function teach_admin_course_options(PDO $pdo, array $user): array
{
    return array_map(
        fn($c) => ['id' => $c['id'], 'code' => $c['code'], 'name' => $c['name']],
        teach_admin_courses($pdo, $user)
    );
}

function admin_swap_quiz_question(PDO $pdo, int $questionId, int $quizId, string $direction): void
{
    admin_swap_position($pdo, 'quiz_questions', $questionId, $direction, 'quiz_id', $quizId);
}

function admin_reindex_positions(PDO $pdo, string $table, string $scopeCol, int $scopeId): void
{
    $allowed = ['modules', 'module_items', 'quiz_questions'];
    if (!in_array($table, $allowed, true)) {
        return;
    }
    $rows = $pdo->prepare("SELECT id FROM {$table} WHERE {$scopeCol} = ? ORDER BY position ASC, id ASC");
    $rows->execute([$scopeId]);
    $update = $pdo->prepare("UPDATE {$table} SET position = ? WHERE id = ?");
    $i = 0;
    foreach ($rows->fetchAll() as $row) {
        $update->execute([$i++, $row['id']]);
    }
}