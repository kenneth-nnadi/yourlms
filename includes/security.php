<?php
declare(strict_types=1);

function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    return false;
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
        . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com; "
        . "font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; "
        . "img-src 'self' data: blob:; "
        . "connect-src 'self'; "
        . "frame-ancestors 'self'"
    );
}

function self_registration_allowed(): bool
{
    return (config()['allow_self_registration'] ?? true) !== false;
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return is_string($ip) && $ip !== '' ? $ip : '0.0.0.0';
}

function rate_limit_store_path(string $action): string
{
    $dir = rtrim(config()['upload_dir'], '/') . '/rate_limits';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $key = hash('sha256', $action . '|' . client_ip());
    return $dir . '/' . $key . '.json';
}

function read_rate_limit_state(string $action): array
{
    $path = rate_limit_store_path($action);
    if (!is_file($path)) {
        return ['count' => 0, 'until' => 0];
    }
    $data = json_decode((string) file_get_contents($path), true);
    return is_array($data) ? $data : ['count' => 0, 'until' => 0];
}

function write_rate_limit_state(string $action, array $state): void
{
    file_put_contents(rate_limit_store_path($action), json_encode($state));
}

function check_action_rate_limit(string $action, int $maxAttempts = 5, int $lockSeconds = 900): ?string
{
    $state = read_rate_limit_state($action);
    if (($state['until'] ?? 0) > time()) {
        $mins = (int) ceil(($state['until'] - time()) / 60);
        return "Too many attempts. Try again in {$mins} minute" . ($mins === 1 ? '' : 's') . '.';
    }
    return null;
}

function record_action_failure(string $action, int $maxAttempts = 5, int $lockSeconds = 900): void
{
    $state = read_rate_limit_state($action);
    $state['count'] = (int) ($state['count'] ?? 0) + 1;
    if ($state['count'] >= $maxAttempts) {
        $state['until'] = time() + $lockSeconds;
        $state['count'] = 0;
    }
    write_rate_limit_state($action, $state);
}

function clear_action_rate_limit(string $action): void
{
    $path = rate_limit_store_path($action);
    if (is_file($path)) {
        @unlink($path);
    }
    if ($action === 'login') {
        unset($_SESSION['login_rate']);
    }
}

function check_login_rate_limit(): ?string
{
    return check_action_rate_limit('login');
}

function record_login_failure(): void
{
    record_action_failure('login');
}

function clear_login_rate_limit(): void
{
    clear_action_rate_limit('login');
}

function check_password_reset_rate_limit(): ?string
{
    return check_action_rate_limit('password_reset', 3, 900);
}

function record_password_reset_attempt(): void
{
    record_action_failure('password_reset', 3, 900);
}