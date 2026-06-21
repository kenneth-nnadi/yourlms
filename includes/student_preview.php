<?php
declare(strict_types=1);

function student_preview_active(int $courseId): bool
{
    return !empty($_SESSION['student_preview'][$courseId]);
}

function student_preview_request_path(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $query = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY);
    return $path . ($query ? '?' . $query : '');
}

function student_preview_path_is_admin(string $path): bool
{
    $base = rtrim(config()['base_url'], '/');
    $relative = $path;
    if ($base !== '' && str_starts_with($path, $base)) {
        $relative = substr($path, strlen($base)) ?: '/';
    }
    return str_contains($relative, '/admin/');
}

function student_preview_valid_return_path(string $path): bool
{
    if ($path === '' || !str_starts_with($path, '/')) {
        return false;
    }
    $base = rtrim(config()['base_url'], '/');
    if ($base !== '' && !str_starts_with($path, $base)) {
        return false;
    }
    $relative = $base !== '' ? (substr($path, strlen($base)) ?: '/') : $path;
    return str_starts_with($relative, '/admin/')
        || str_starts_with($relative, '/course.php')
        || str_starts_with($relative, '/gradebook.php');
}

function student_preview_default_admin_return(int $courseId): string
{
    return url('admin/modules.php?course_id=' . $courseId);
}

function student_preview_admin_return(int $courseId): string
{
    $stored = $_SESSION['student_preview_admin_return'][$courseId] ?? null;
    if (is_string($stored) && student_preview_valid_return_path($stored)) {
        return $stored;
    }
    return student_preview_default_admin_return($courseId);
}

function student_preview_infer_admin_return(int $courseId): ?string
{
    if (!empty($_SERVER['HTTP_REFERER'])) {
        $refPath = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH) ?: '';
        $refQuery = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY);
        $ref = $refPath . ($refQuery ? '?' . $refQuery : '');
        if (student_preview_valid_return_path($ref) && student_preview_path_is_admin($ref)) {
            return $ref;
        }
    }
    $stored = $_SESSION['student_preview_admin_return'][$courseId] ?? null;
    if (is_string($stored) && student_preview_valid_return_path($stored)) {
        return $stored;
    }
    return null;
}

function set_student_preview(int $courseId, bool $active, ?string $adminReturn = null): void
{
    if (!isset($_SESSION['student_preview'])) {
        $_SESSION['student_preview'] = [];
    }
    if (!isset($_SESSION['student_preview_admin_return'])) {
        $_SESSION['student_preview_admin_return'] = [];
    }

    if ($active) {
        $_SESSION['student_preview'][$courseId] = true;

        if ($adminReturn && student_preview_valid_return_path($adminReturn)) {
            $_SESSION['student_preview_admin_return'][$courseId] = $adminReturn;
        } elseif (!empty($_SERVER['HTTP_REFERER'])) {
            $refPath = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_PATH) ?: '';
            $refQuery = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_QUERY);
            $ref = $refPath . ($refQuery ? '?' . $refQuery : '');
            if (student_preview_valid_return_path($ref) && student_preview_path_is_admin($ref)) {
                $_SESSION['student_preview_admin_return'][$courseId] = $ref;
            }
        }

        if (empty($_SESSION['student_preview_admin_return'][$courseId])) {
            $_SESSION['student_preview_admin_return'][$courseId] = student_preview_default_admin_return($courseId);
        }
    } else {
        unset($_SESSION['student_preview'][$courseId]);
    }
}

function clear_student_preview(int $courseId): void
{
    set_student_preview($courseId, false);
    unset($_SESSION['student_preview_admin_return'][$courseId]);
}

function active_student_preview_course_id(): ?int
{
    foreach ($_SESSION['student_preview'] ?? [] as $id => $on) {
        if ($on) {
            return (int) $id;
        }
    }
    return null;
}

function student_preview_exit_url(int $courseId): string
{
    return url('student_preview.php?course_id=' . $courseId . '&off=1&admin=1');
}

function student_preview_enter_url(int $courseId, ?string $courseReturn = null, ?string $adminReturn = null): string
{
    $courseReturn = $courseReturn ?: (rtrim(config()['base_url'], '/') . '/course.php?id=' . $courseId);
    if ($adminReturn === null && student_preview_path_is_admin(student_preview_request_path())) {
        $adminReturn = student_preview_request_path();
    }
    $params = 'course_id=' . $courseId . '&on=1&return=' . urlencode($courseReturn);
    if ($adminReturn && student_preview_valid_return_path($adminReturn)) {
        $params .= '&admin_return=' . urlencode($adminReturn);
    }
    return url('student_preview.php?' . $params);
}

function render_admin_preview_link(int $courseId, ?string $adminReturn = null): void
{
    if ($courseId <= 0) {
        return;
    }
    $adminReturn = $adminReturn ?? student_preview_request_path();
    $href = student_preview_enter_url(
        $courseId,
        rtrim(config()['base_url'], '/') . '/course.php?id=' . $courseId,
        $adminReturn
    );
    echo '<a class="btn btn-sm btn-outline" href="' . e($href) . '">Preview as student</a>';
}

function user_in_student_preview(PDO $pdo, int $courseId, array $user): bool
{
    return student_preview_active($courseId) && user_is_course_staff($pdo, $courseId, $user);
}

function course_header_actions(PDO $pdo, int $courseId, array $user, string $extraHtml = ''): string
{
    $html = $extraHtml;
    if (!user_is_course_staff($pdo, $courseId, $user)) {
        return $html;
    }

    if (!student_preview_active($courseId)) {
        $html .= '<a class="btn btn-sm btn-outline" href="' . e(student_preview_enter_url($courseId, null, student_preview_infer_admin_return($courseId))) . '">Preview as student</a>';
    }
    return $html;
}

function render_student_preview_banner(int $courseId): void
{
    if (!student_preview_active($courseId)) {
        return;
    }
    $returnTo = student_preview_admin_return($courseId);
    echo '<div class="student-preview-banner">';
    echo '<div class="student-preview-banner-text">';
    echo '<strong>Student preview</strong> — you are seeing what students see. Unpublished modules and items are hidden.';
    echo '</div>';
    echo '<a class="btn btn-sm student-preview-exit-btn" href="' . e(student_preview_exit_url($courseId)) . '" title="Return to ' . e($returnTo) . '">Exit preview</a>';
    echo '</div>';
}

function published_ref_ids_for_course(PDO $pdo, int $courseId, string $itemType): array
{
    $stmt = $pdo->prepare(
        "SELECT DISTINCT mi.ref_id FROM module_items mi
         JOIN modules m ON m.id = mi.module_id
         WHERE m.course_id = ? AND mi.item_type = ? AND mi.ref_id IS NOT NULL
           AND m.published = 1 AND mi.published = 1"
    );
    $stmt->execute([$courseId, $itemType]);
    return array_map('intval', array_column($stmt->fetchAll(), 'ref_id'));
}

function ref_is_live(PDO $pdo, int $courseId, string $itemType, int $refId): bool
{
    return in_array($refId, published_ref_ids_for_course($pdo, $courseId, $itemType), true);
}

function filter_rows_by_published_refs(PDO $pdo, int $courseId, array $user, array $rows, string $itemType): array
{
    if (user_can_view_unpublished($pdo, $courseId, $user)) {
        return $rows;
    }
    $ids = published_ref_ids_for_course($pdo, $courseId, $itemType);
    if (!$ids) {
        return [];
    }
    return array_values(array_filter($rows, static fn($row) => in_array((int) $row['id'], $ids, true)));
}