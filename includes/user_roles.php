<?php
declare(strict_types=1);

function account_roles(): array
{
    return ['instructor', 'student', 'ta', 'guest'];
}

function enrollment_roles(): array
{
    return ['instructor', 'student', 'ta', 'guest'];
}

function account_role_label(string $role): string
{
    return match ($role) {
        'instructor' => 'Instructor',
        'student' => 'Student',
        'ta' => 'Teaching Assistant',
        'guest' => 'Guest',
        default => ucfirst($role),
    };
}

function enrollment_role_label(string $role): string
{
    return match ($role) {
        'instructor' => 'Teacher',
        'student' => 'Student',
        'ta' => 'TA',
        'guest' => 'Guest',
        default => ucfirst($role),
    };
}

function user_is_site_instructor(array $user): bool
{
    return $user['role'] === 'instructor';
}

/** @deprecated Use user_is_site_instructor() or user_can_view_unpublished() */
function user_is_instructor(array $user): bool
{
    return user_is_site_instructor($user);
}

function course_enrollment(PDO $pdo, int $courseId, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM enrollments WHERE course_id = ? AND user_id = ?');
    $stmt->execute([$courseId, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function course_enrollment_role(PDO $pdo, int $courseId, int $userId): ?string
{
    $enrollment = course_enrollment($pdo, $courseId, $userId);
    return $enrollment['role'] ?? null;
}

function user_is_course_staff(PDO $pdo, int $courseId, array $user): bool
{
    if (user_is_site_instructor($user)) {
        return true;
    }
    $role = course_enrollment_role($pdo, $courseId, $user['id']);
    return in_array($role, ['instructor', 'ta'], true);
}

function user_is_course_teacher(PDO $pdo, int $courseId, array $user): bool
{
    if (user_is_site_instructor($user)) {
        return true;
    }
    return course_enrollment_role($pdo, $courseId, $user['id']) === 'instructor';
}

function user_can_view_unpublished(PDO $pdo, int $courseId, array $user): bool
{
    if (user_in_student_preview($pdo, $courseId, $user)) {
        return false;
    }
    return user_is_course_staff($pdo, $courseId, $user);
}

function user_can_edit_course_content(PDO $pdo, int $courseId, array $user): bool
{
    if (user_in_student_preview($pdo, $courseId, $user)) {
        return false;
    }
    return user_is_course_staff($pdo, $courseId, $user);
}

function user_can_grade(PDO $pdo, int $courseId, array $user): bool
{
    if (user_in_student_preview($pdo, $courseId, $user)) {
        return false;
    }
    return user_is_course_staff($pdo, $courseId, $user);
}

function user_can_manage_course_as_staff(PDO $pdo, int $courseId, array $user): bool
{
    if (user_in_student_preview($pdo, $courseId, $user)) {
        return false;
    }
    return user_is_course_staff($pdo, $courseId, $user);
}

function user_can_participate(PDO $pdo, int $courseId, array $user): bool
{
    if (user_is_site_instructor($user)) {
        return true;
    }
    if ($user['role'] === 'guest') {
        return false;
    }
    return course_enrollment_role($pdo, $courseId, $user['id']) === 'student';
}

function user_is_course_guest(PDO $pdo, int $courseId, array $user): bool
{
    if ($user['role'] === 'guest') {
        return true;
    }
    return course_enrollment_role($pdo, $courseId, $user['id']) === 'guest';
}

function user_can_access_teach_menu(PDO $pdo, array $user): bool
{
    if (user_is_site_instructor($user)) {
        return true;
    }
    $stmt = $pdo->prepare(
        "SELECT 1 FROM enrollments WHERE user_id = ? AND role IN ('instructor', 'ta') LIMIT 1"
    );
    $stmt->execute([$user['id']]);
    return (bool) $stmt->fetch();
}

function user_can_manage_accounts(array $user): bool
{
    return user_is_site_instructor($user);
}

function user_can_manage_course_people(PDO $pdo, int $courseId, array $user): bool
{
    if (user_can_manage_accounts($user)) {
        return true;
    }
    return user_is_course_staff($pdo, $courseId, $user);
}

function courses_for_people_admin(PDO $pdo, array $user): array
{
    if (user_can_manage_accounts($user)) {
        return $pdo->query('SELECT id, code, name FROM courses ORDER BY code')->fetchAll();
    }
    $stmt = $pdo->prepare(
        "SELECT c.id, c.code, c.name FROM courses c
         JOIN enrollments e ON e.course_id = c.id
         WHERE e.user_id = ? AND e.role IN ('instructor', 'ta')
         ORDER BY c.code"
    );
    $stmt->execute([$user['id']]);
    return $stmt->fetchAll();
}

function user_can_manage_site_courses(array $user): bool
{
    return user_is_site_instructor($user);
}

function normalize_username(string $username): string
{
    return strtolower(trim($username));
}

function validate_username(string $username): ?string
{
    $username = normalize_username($username);
    if (strlen($username) < 3) {
        return 'Username must be at least 3 characters.';
    }
    if (strlen($username) > 64) {
        return 'Username must be 64 characters or fewer.';
    }
    if (!preg_match('/^[a-z0-9._-]+$/', $username)) {
        return 'Username may only contain letters, numbers, dots, underscores, and hyphens.';
    }
    return null;
}

function find_user_by_login_identifier(PDO $pdo, string $identifier): ?array
{
    $identifier = trim($identifier);
    if ($identifier === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1');
    $stmt->execute([strtolower($identifier), normalize_username($identifier)]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function user_login_label(array $user): string
{
    if (!empty($user['username'])) {
        return (string) $user['username'];
    }
    return (string) ($user['email'] ?? '');
}

function user_has_admin_managed_password(array $user): bool
{
    return !empty($user['admin_managed_password']);
}

function create_user_account(PDO $pdo, string $email, string $password, string $fullName, string $role): ?string
{
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Invalid email address.';
    }
    if (strlen($password) < 6) {
        return 'Password must be at least 6 characters.';
    }
    if (!in_array($role, account_roles(), true)) {
        return 'Invalid role.';
    }

    $check = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $check->execute([$email]);
    if ($check->fetch()) {
        return 'An account with this email already exists.';
    }

    $pdo->prepare(
        'INSERT INTO users (email, password_hash, full_name, role, admin_managed_password) VALUES (?, ?, ?, ?, 0)'
    )->execute([
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        trim($fullName),
        $role,
    ]);

    return null;
}

function create_simple_user_account(PDO $pdo, string $username, string $password, string $role = 'student'): ?string
{
    $usernameErr = validate_username($username);
    if ($usernameErr !== null) {
        return $usernameErr;
    }
    $username = normalize_username($username);
    if (strlen($password) < 6) {
        return 'Password must be at least 6 characters.';
    }
    if (!in_array($role, account_roles(), true)) {
        return 'Invalid role.';
    }

    $check = $pdo->prepare('SELECT id FROM users WHERE username = ? OR email = ?');
    $check->execute([$username, $username]);
    if ($check->fetch()) {
        return 'That username is already taken.';
    }

    $pdo->prepare(
        'INSERT INTO users (username, email, password_hash, full_name, role, admin_managed_password) VALUES (?, NULL, ?, ?, ?, 1)'
    )->execute([
        $username,
        password_hash($password, PASSWORD_DEFAULT),
        $username,
        $role,
    ]);

    return null;
}

function admin_set_user_password(PDO $pdo, int $userId, string $newPassword): ?string
{
    if (strlen($newPassword) < 6) {
        return 'Password must be at least 6 characters.';
    }
    $stmt = $pdo->prepare('SELECT id, admin_managed_password FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return 'User not found.';
    }
    if (empty($row['admin_managed_password'])) {
        return 'This account manages its own password.';
    }
    $pdo->prepare('UPDATE users SET password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL WHERE id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
    return null;
}

function require_course_content_editor(PDO $pdo, int $courseId, array $user): void
{
    if (!user_can_edit_course_content($pdo, $courseId, $user)) {
        flash('error', 'You do not have permission to edit this course.');
        redirect('/dashboard.php');
    }
}

function enroll_user_in_course(PDO $pdo, int $courseId, int $userId, string $role): bool
{
    if (!in_array($role, enrollment_roles(), true)) {
        return false;
    }
    $pdo->prepare(sql_enroll_upsert())->execute([$courseId, $userId, $role]);
    return true;
}