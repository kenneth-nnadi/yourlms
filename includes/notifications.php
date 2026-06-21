<?php
declare(strict_types=1);

require_once __DIR__ . '/mail.php';

function notification_link(string $path): string
{
    return ltrim(str_replace('\\', '/', $path), '/');
}

function notification_follow_path(?string $link): string
{
    if (!$link) {
        return 'notifications.php';
    }
    if (str_starts_with($link, 'http://') || str_starts_with($link, 'https://')) {
        return $link;
    }
    $base = rtrim(config()['base_url'], '/');
    if (str_starts_with($link, '/')) {
        if ($base !== '' && str_starts_with($link, $base . '/')) {
            return ltrim(substr($link, strlen($base)), '/');
        }
        if ($base !== '' && $link === $base) {
            return '';
        }
        return ltrim($link, '/');
    }
    return $link;
}

function notification_kind_label(string $kind): string
{
    return match ($kind) {
        'announcement' => 'Announcement',
        'assignment' => 'Assignment',
        'discussion' => 'Discussion',
        'submission' => 'Submission',
        'grade' => 'Grade',
        'comment' => 'Comment',
        'quiz' => 'Quiz',
        default => ucfirst($kind),
    };
}

function create_notification(PDO $pdo, int $userId, ?int $courseId, string $kind, string $title, string $body, ?string $link = null): void
{
    $pdo->prepare(
        'INSERT INTO notifications (user_id, course_id, kind, title, body, link) VALUES (?, ?, ?, ?, ?, ?)'
    )->execute([$userId, $courseId, $kind, $title, $body, $link ? notification_link($link) : null]);
}

function notification_unread_count(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL');
    $stmt->execute([$userId]);
    return (int) $stmt->fetchColumn();
}

function notifications_for_user(PDO $pdo, int $userId, int $limit = 30): array
{
    $stmt = $pdo->prepare(
        'SELECT n.*, c.code AS course_code FROM notifications n
         LEFT JOIN courses c ON c.id = n.course_id
         WHERE n.user_id = ? ORDER BY n.created_at DESC LIMIT ?'
    );
    $stmt->bindValue(1, $userId, PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

function mark_notification_read(PDO $pdo, int $notificationId, int $userId): void
{
    $pdo->prepare('UPDATE notifications SET read_at = ' . db_now_sql() . ' WHERE id = ? AND user_id = ?')
        ->execute([$notificationId, $userId]);
}

function mark_all_notifications_read(PDO $pdo, int $userId): void
{
    $pdo->prepare('UPDATE notifications SET read_at = ' . db_now_sql() . ' WHERE user_id = ? AND read_at IS NULL')
        ->execute([$userId]);
}

function course_staff_user_ids(PDO $pdo, int $courseId): array
{
    $ids = [];
    $stmt = $pdo->prepare(
        "SELECT DISTINCT u.id FROM users u
         JOIN enrollments e ON e.user_id = u.id
         WHERE e.course_id = ? AND e.role IN ('instructor', 'ta')"
    );
    $stmt->execute([$courseId]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $ids[(int) $id] = true;
    }
    foreach ($pdo->query("SELECT id FROM users WHERE role = 'instructor'")->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $ids[(int) $id] = true;
    }
    return array_keys($ids);
}

function course_student_user_ids(PDO $pdo, int $courseId): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT u.id FROM users u
         JOIN enrollments e ON e.user_id = u.id
         WHERE e.course_id = ? AND e.role = 'student'"
    );
    $stmt->execute([$courseId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function send_notification_email(array $userRow, string $title, string $body, ?string $link): void
{
    $cfg = config();
    if (!mail_is_enabled($cfg) || empty($userRow['email'])) {
        return;
    }
    $appName = $cfg['app_name'] ?? 'YourLMS';
    $fullLink = $link ? url(notification_follow_path($link)) : '';
    $emailBody = "{$body}\n";
    if ($fullLink) {
        $emailBody .= "\nView: {$fullLink}\n";
    }
    send_mail($cfg, $userRow['email'], "{$appName}: {$title}", $emailBody);
}

function notify_course_staff(PDO $pdo, int $courseId, string $kind, string $title, string $body, ?string $link, ?int $exceptUserId = null): void
{
    $ids = course_staff_user_ids($pdo, $courseId);
    if (!$ids) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT id, email, full_name FROM users WHERE id IN ({$placeholders})");
    $stmt->execute($ids);
    $storedLink = $link ? notification_link($link) : null;
    foreach ($stmt->fetchAll() as $staff) {
        if ($exceptUserId && (int) $staff['id'] === $exceptUserId) {
            continue;
        }
        create_notification($pdo, (int) $staff['id'], $courseId, $kind, $title, $body, $storedLink);
        send_notification_email($staff, $title, $body, $storedLink);
    }
}

function notify_user(PDO $pdo, int $userId, ?int $courseId, string $kind, string $title, string $body, ?string $link, bool $email = true): void
{
    $storedLink = $link ? notification_link($link) : null;
    create_notification($pdo, $userId, $courseId, $kind, $title, $body, $storedLink);
    if (!$email) {
        return;
    }
    $stmt = $pdo->prepare('SELECT email, full_name FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if ($row) {
        send_notification_email($row, $title, $body, $storedLink);
    }
}

function notify_course_students(PDO $pdo, int $courseId, string $kind, string $title, string $body, ?string $link, ?int $exceptUserId = null): void
{
    foreach (course_student_user_ids($pdo, $courseId) as $studentId) {
        if ($exceptUserId && $studentId === $exceptUserId) {
            continue;
        }
        notify_user($pdo, $studentId, $courseId, $kind, $title, $body, $link, true);
    }
}

function notify_discussion_activity(
    PDO $pdo,
    int $courseId,
    array $discussion,
    array $author,
    string $content,
    ?int $parentId,
    ?int $parentAuthorId
): void {
    $discussionId = (int) $discussion['id'];
    $authorId = (int) $author['id'];
    $link = notification_link("discussion.php?course_id={$courseId}&id={$discussionId}");
    $snippet = mb_substr(strip_tags($content), 0, 160);
    $title = (string) $discussion['title'];

    if ($parentId && $parentAuthorId && $parentAuthorId !== $authorId) {
        notify_user(
            $pdo,
            $parentAuthorId,
            $courseId,
            'discussion',
            'Reply in: ' . $title,
            ($author['full_name'] ?? 'Someone') . ': ' . $snippet,
            $link,
            true
        );
    }

    $authorIsStaff = user_is_site_instructor($author)
        || in_array(
            course_enrollment_role($pdo, $courseId, $authorId),
            ['instructor', 'ta'],
            true
        );

    if ($authorIsStaff && !$parentId) {
        notify_course_students(
            $pdo,
            $courseId,
            'discussion',
            'New discussion post: ' . $title,
            ($author['full_name'] ?? 'Instructor') . ': ' . $snippet,
            $link,
            $authorId
        );
        return;
    }

    notify_course_staff(
        $pdo,
        $courseId,
        'discussion',
        ($parentId ? 'Discussion reply: ' : 'New discussion post: ') . $title,
        ($author['full_name'] ?? 'Student') . ': ' . $snippet,
        $link,
        $authorId
    );
}

function render_notification_bell(PDO $pdo, int $userId, string $extraClass = ''): void
{
    static $bellInstance = 0;
    $bellInstance++;
    $btnId = 'notification-bell-btn-' . $bellInstance;
    $panelId = 'notification-dropdown-' . $bellInstance;
    $unread = notification_unread_count($pdo, $userId);
    $recent = notifications_for_user($pdo, $userId, 8);
    $cls = trim('notification-wrap ' . $extraClass);
    echo '<div class="' . e($cls) . '">';
    echo '<button type="button" class="account-notifications notification-bell-btn' . ($unread ? ' has-unread' : '') . '" id="' . e($btnId) . '" aria-expanded="false" aria-controls="' . e($panelId) . '" aria-label="Notifications" title="Notifications">';
    echo '<span aria-hidden="true">🔔</span>';
    if ($unread) {
        echo '<span class="notification-badge">' . ($unread > 9 ? '9+' : $unread) . '</span>';
    }
    echo '</button>';
    echo '<div class="notification-dropdown" id="' . e($panelId) . '" hidden>';
    if ($recent) {
        echo '<div class="notification-dropdown-head"><strong>Notifications</strong>';
        if ($unread) {
            echo '<span class="notification-dropdown-count">' . (int) $unread . ' unread</span>';
        }
        echo '</div>';
        echo '<div class="notification-dropdown-list">';
        foreach ($recent as $n) {
            $rowCls = 'notification-dropdown-item' . ($n['read_at'] ? '' : ' notification-dropdown-unread');
            $href = url('notifications.php?read=' . (int) $n['id']);
            echo '<a class="' . $rowCls . '" href="' . e($href) . '">';
            echo '<span class="notification-dropdown-kind">' . e(notification_kind_label((string) $n['kind'])) . '</span>';
            echo '<span class="notification-dropdown-title">' . e($n['title']) . '</span>';
            $ago = time_ago($n['created_at']);
            $stamp = format_datetime($n['created_at'], 'M j, Y g:ia');
            if (!empty($n['course_code'])) {
                echo '<span class="notification-dropdown-meta" title="' . e($stamp) . '">' . e($n['course_code']) . ' · ' . e($ago) . '</span>';
            } else {
                echo '<span class="notification-dropdown-meta" title="' . e($stamp) . '">' . e($ago) . '</span>';
            }
            echo '</a>';
        }
        echo '</div>';
    } else {
        echo '<div class="notification-dropdown-empty">No notifications yet.</div>';
    }
    echo '<a class="notification-dropdown-all" href="' . url('notifications.php') . '">View all notifications</a>';
    echo '</div></div>';
}