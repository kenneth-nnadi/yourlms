<?php
declare(strict_types=1);

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        redirect('/login.php');
    }
    return $user;
}

function require_instructor(): array
{
    $user = require_login();
    if (!user_is_site_instructor($user)) {
        flash('error', 'Instructor access required.');
        redirect('/dashboard.php');
    }
    return $user;
}

function require_teach_access(PDO $pdo): array
{
    $user = require_login();
    if (!user_can_access_teach_menu($pdo, $user)) {
        flash('error', 'Teaching access required.');
        redirect('/dashboard.php');
    }
    return $user;
}

function login_user(PDO $pdo, string $identifier, string $password): bool
{
    $row = find_user_by_login_identifier($pdo, $identifier);
    if (!$row || !password_verify($password, $row['password_hash'])) {
        return false;
    }
    session_regenerate_id(true);
    clear_login_rate_limit();
    $_SESSION['user'] = [
        'id' => (int) $row['id'],
        'email' => $row['email'],
        'username' => $row['username'] ?? null,
        'full_name' => $row['full_name'],
        'role' => $row['role'],
        'admin_managed_password' => !empty($row['admin_managed_password']),
    ];
    return true;
}

function register_user(PDO $pdo, string $email, string $password, string $fullName, string $role): ?string
{
    if (!self_registration_allowed()) {
        return 'Self-registration is disabled. Ask an instructor for an account.';
    }
    if (!in_array($role, ['student', 'guest'], true)) {
        return 'Self-registration is only available for students and guests. Ask an instructor to create your account.';
    }

    $error = create_user_account($pdo, $email, $password, $fullName, $role);
    if ($error !== null) {
        return $error;
    }

    login_user($pdo, $email, $password);
    return null;
}

function logout_user(): void
{
    unset($_SESSION['student_preview'], $_SESSION['student_preview_admin_return']);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}