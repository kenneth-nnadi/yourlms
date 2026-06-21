<?php
declare(strict_types=1);

/** Target course import upload size when the machine has enough resources. */
function install_target_upload_mb(): int
{
    return 1024;
}

function install_bytes_from_ini(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1') {
        return PHP_INT_MAX;
    }
    $unit = strtolower($value[strlen($value) - 1]);
    $number = (float) $value;
    return (int) match ($unit) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
}

function install_format_mb(int $mb): string
{
    return $mb >= 1024 ? (int) round($mb / 1024) . 'G' : $mb . 'M';
}

function install_free_disk_bytes(string $path): ?int
{
    $free = @disk_free_space($path);
    return $free === false ? null : (int) $free;
}

function install_system_memory_bytes(): ?int
{
    if (PHP_OS_FAMILY === 'Darwin') {
        $out = @shell_exec('sysctl -n hw.memsize 2>/dev/null');
        if (is_string($out) && trim($out) !== '') {
            return (int) trim($out);
        }
    }
    if (is_readable('/proc/meminfo')) {
        $meminfo = @file_get_contents('/proc/meminfo');
        if (is_string($meminfo) && preg_match('/MemTotal:\s+(\d+)\s+kB/i', $meminfo, $m)) {
            return (int) $m[1] * 1024;
        }
    }
    return null;
}

/** @return array{upload_mb: int, memory_mb: int, warnings: list<string>} */
function install_resolve_upload_budget(): array
{
    $targetMb = install_target_upload_mb();
    $warnings = [];
    $root = dirname(__DIR__);
    $free = install_free_disk_bytes($root);
    if ($free !== null && $free < $targetMb * 1024 * 1024) {
        $freeGb = round($free / 1024 / 1024 / 1024, 1);
        $warnings[] = "Only {$freeGb} GB free on disk — imports may fail if packages are very large.";
    } elseif ($free !== null && $free < 512 * 1024 * 1024) {
        $warnings[] = 'Less than 512 MB free on disk — free up space before importing large courses.';
    }

    $ram = install_system_memory_bytes();
    $memoryMb = 1280;
    $uploadMb = $targetMb;
    if ($ram !== null && $ram < 2 * 1024 * 1024 * 1024) {
        $uploadMb = min($uploadMb, 512);
        $memoryMb = 768;
        $warnings[] = 'Limited system memory detected — upload limit capped at 512 MB.';
    } elseif ($ram !== null && $ram < 4 * 1024 * 1024 * 1024) {
        $uploadMb = min($uploadMb, 768);
        $memoryMb = 1024;
        $warnings[] = 'Moderate system memory — upload limit capped at 768 MB.';
    }

    return ['upload_mb' => $uploadMb, 'memory_mb' => $memoryMb, 'warnings' => $warnings];
}

/** @return array<string, string> */
function install_php_limit_directives(int $uploadMb, int $memoryMb): array
{
    $upload = install_format_mb($uploadMb);
    $memory = install_format_mb($memoryMb);
    return [
        'upload_max_filesize' => $upload,
        'post_max_size' => $upload,
        'memory_limit' => $memory,
        'max_execution_time' => '600',
        'max_input_time' => '600',
    ];
}

function install_detect_php_ini_paths(): array
{
    $paths = [];
    $loaded = php_ini_loaded_file();
    if (is_string($loaded) && $loaded !== '') {
        $paths[] = $loaded;
    }
    $scanned = php_ini_scanned_files();
    if (is_string($scanned) && $scanned !== '') {
        foreach (array_map('trim', explode(',', $scanned)) as $path) {
            if ($path !== '') {
                $paths[] = $path;
            }
        }
    }
    $xamppCandidates = [
        '/Applications/XAMPP/xamppfiles/etc/php.ini',
        'C:/xampp/php/php.ini',
        '/opt/lampp/etc/php.ini',
    ];
    foreach ($xamppCandidates as $candidate) {
        if (is_file($candidate)) {
            $paths[] = $candidate;
        }
    }
    return array_values(array_unique($paths));
}

function install_php_ini_apply(string $path, array $directives): bool
{
    if (!is_file($path) || !is_readable($path)) {
        return false;
    }
    $content = file_get_contents($path);
    if ($content === false) {
        return false;
    }
    foreach ($directives as $key => $value) {
        $line = "{$key} = {$value}";
        if (preg_match('/^;\s*' . preg_quote($key, '/') . '\s*=.*$/m', $content)) {
            $content = preg_replace('/^;\s*' . preg_quote($key, '/') . '\s*=.*$/m', $line, $content);
        } elseif (preg_match('/^' . preg_quote($key, '/') . '\s*=.*$/m', $content)) {
            $content = preg_replace('/^' . preg_quote($key, '/') . '\s*=.*$/m', $line, $content);
        } else {
            $content = rtrim($content) . "\n{$line}\n";
        }
    }
    return @file_put_contents($path, $content) !== false;
}

function install_write_user_ini(string $root, array $directives): bool
{
    $lines = ["; YourLMS — auto-generated upload limits\n"];
    foreach ($directives as $key => $value) {
        $lines[] = "{$key} = {$value}";
    }
    return file_put_contents($root . '/.user.ini', implode("\n", $lines) . "\n") !== false;
}

function install_write_htaccess_limits(string $htaccessPath, array $directives): bool
{
    if (!is_file($htaccessPath)) {
        return false;
    }
    $content = file_get_contents($htaccessPath);
    if ($content === false) {
        return false;
    }

    $block = "# BEGIN YourLMS upload limits\n";
    $block .= "<IfModule mod_php.c>\n";
    foreach ($directives as $key => $value) {
        $block .= "  php_value {$key} {$value}\n";
    }
    $block .= "</IfModule>\n";
    $block .= "<IfModule mod_php8.c>\n";
    foreach ($directives as $key => $value) {
        $block .= "  php_value {$key} {$value}\n";
    }
    $block .= "</IfModule>\n";
    $block .= "<IfModule mod_php5.c>\n";
    foreach ($directives as $key => $value) {
        $block .= "  php_value {$key} {$value}\n";
    }
    $block .= "</IfModule>\n";
    $block .= "# END YourLMS upload limits\n";

    if (str_contains($content, '# BEGIN YourLMS upload limits')) {
        $content = preg_replace(
            '/# BEGIN YourLMS upload limits.*?# END YourLMS upload limits\n/s',
            $block,
            $content
        ) ?? $content;
    } else {
        $content = preg_replace(
            '/(# Course imports.*?<\/IfModule>\n)+/s',
            '',
            $content
        ) ?? $content;
        $content = preg_replace(
            '/(Options -Indexes \+FollowSymLinks\n)/',
            "$1\n{$block}",
            $content,
            1
        ) ?? ($block . $content);
    }

    return file_put_contents($htaccessPath, $content) !== false;
}

/** @return array{0: list<string>, 1: list<string>} */
function install_configure_upload_limits(): array
{
    $messages = [];
    $errors = [];
    $root = dirname(__DIR__);
    $budget = install_resolve_upload_budget();
    $directives = install_php_limit_directives($budget['upload_mb'], $budget['memory_mb']);

    foreach ($budget['warnings'] as $warning) {
        $messages[] = 'Note: ' . $warning;
    }

    $free = install_free_disk_bytes($root);
    if ($free !== null && $free < 1024 * 1024 * 1024) {
        $freeGb = round($free / 1024 / 1024 / 1024, 1);
        $messages[] = "Note: {$freeGb} GB free on disk — keep at least 1 GB free for large course imports.";
    }

    $htaccess = $root . '/.htaccess';
    if (install_write_htaccess_limits($htaccess, $directives)) {
        $messages[] = 'Apache upload limits set to ' . $directives['upload_max_filesize'] . ' via .htaccess.';
    } else {
        $errors[] = 'Could not update .htaccess upload limits — check folder permissions.';
    }

    if (install_write_user_ini($root, $directives)) {
        $messages[] = 'PHP .user.ini written for hosts that honor per-directory settings.';
    }

    $iniPatched = false;
    foreach (install_detect_php_ini_paths() as $iniPath) {
        if (!is_writable($iniPath)) {
            continue;
        }
        if (install_php_ini_apply($iniPath, $directives)) {
            $messages[] = 'Updated ' . basename($iniPath) . ' for ' . $directives['upload_max_filesize'] . ' uploads.';
            $iniPatched = true;
            break;
        }
    }
    if (!$iniPatched) {
        $messages[] = 'php.ini was not modified (protected on XAMPP). Web uploads use .htaccess limits after Apache reads them.';
    }

    $configLocal = $root . '/config.local.php';
    $existing = [];
    if (is_file($configLocal)) {
        $loaded = require $configLocal;
        if (is_array($loaded)) {
            $existing = $loaded;
        }
    }
    $merged = array_replace_recursive($existing, ['upload_max_mb' => $budget['upload_mb']]);
    $export = var_export($merged, true);
    if (file_put_contents($configLocal, "<?php\n\ndeclare(strict_types=1);\n\nreturn {$export};\n") === false) {
        $errors[] = 'Could not save upload_max_mb to config.local.php.';
    } else {
        $messages[] = 'Application import limit set to ' . $budget['upload_mb'] . ' MB in config.local.php.';
    }

    file_put_contents($root . '/.upload-limits-applied', date('c') . "\nupload_mb={$budget['upload_mb']}\n");

    return [$messages, $errors];
}

function install_upload_limits_satisfied(): bool
{
    $target = install_target_upload_mb() * 1024 * 1024;
    $upload = install_bytes_from_ini((string) ini_get('upload_max_filesize'));
    $post = install_bytes_from_ini((string) ini_get('post_max_size'));
    return $upload >= $target && $post >= $target;
}