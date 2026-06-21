<?php
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_request_path(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $base = rtrim(config()['base_url'], '/');
    if ($base !== '' && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base)) ?: '/';
    }
    return $uri;
}

function csrf_exempt_paths(): array
{
    return [
        '/login.php',
        '/forgot-password.php',
        '/reset-password.php',
        '/calendar-export.php',
        '/admin/export-download.php',
        '/admin/grades-export.php',
    ];
}

function verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $path = csrf_request_path();
    if (in_array($path, csrf_exempt_paths(), true)) {
        return;
    }
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        die('Invalid or missing security token. Please go back and try again.');
    }
}

function render_csrf_field(): void
{
    echo '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function render_csrf_script(): void
{
    $token = e(csrf_token());
    echo "<script>
(function () {
  document.querySelectorAll('form[method=\"post\"], form[method=\"POST\"]').forEach(function (form) {
    if (form.querySelector('input[name=\"csrf_token\"]')) return;
    var input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'csrf_token';
    input.value = '{$token}';
    form.appendChild(input);
  });
})();
</script>";
}