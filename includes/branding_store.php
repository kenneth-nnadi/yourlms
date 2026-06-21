<?php
declare(strict_types=1);

function branding_local_file(): string
{
    return dirname(__DIR__) . '/includes/branding.local.php';
}

function load_branding_overrides(): array
{
    $file = branding_local_file();
    if (!is_file($file)) {
        return [];
    }
    $data = require $file;
    return is_array($data) ? $data : [];
}

function save_branding_overrides(array $overrides): void
{
    $export = var_export($overrides, true);
    file_put_contents(
        branding_local_file(),
        "<?php\n\ndeclare(strict_types=1);\n\nreturn {$export};\n"
    );
}

function merge_branding_override(array $patch): void
{
    save_branding_overrides(array_replace_recursive(load_branding_overrides(), $patch));
}

function remove_branding_override_key(string $key): void
{
    $current = load_branding_overrides();
    unset($current[$key]);
    save_branding_overrides($current);
}

function store_branding_logo(array $config, array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return 'Upload failed — try again.';
    }
    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
    $mime = mime_content_type($file['tmp_name']) ?: '';
    if (!isset($allowed[$mime])) {
        return 'Logo must be PNG, JPEG, WebP, or SVG.';
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return 'Logo must be under 2 MB.';
    }
    $dir = $config['upload_dir'] . '/branding';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    foreach (glob($dir . '/logo.*') ?: [] as $old) {
        @unlink($old);
    }
    $rel = 'branding/logo.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $config['upload_dir'] . '/' . $rel)) {
        return 'Could not save logo.';
    }
    merge_branding_override(['app_logo' => $rel]);
    return null;
}