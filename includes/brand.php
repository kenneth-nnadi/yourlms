<?php
declare(strict_types=1);

/** Bundled default logo — used unless an instructor uploads a replacement on Profile. */
const APP_DEFAULT_LOGO = 'assets/branding/yourlms.png';

function app_theme(): array
{
    $cfg = config();
    $theme = $cfg['theme'] ?? [];

    return [
        'accent' => $theme['accent'] ?? '#0d9488',
        'nav' => $theme['nav'] ?? '#1e293b',
        'surface' => $theme['surface'] ?? '#f1f5f9',
        'enable_dark_mode' => ($theme['enable_dark_mode'] ?? true) !== false,
    ];
}

function app_initials(): string
{
    $name = trim(config()['app_name'] ?? 'LMS');
    $words = preg_split('/\s+/u', $name) ?: [];
    $initials = '';
    foreach (array_slice($words, 0, 2) as $word) {
        if ($word !== '') {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1));
        }
    }
    if ($initials === '' && count($words) === 1 && mb_strlen($words[0]) >= 2) {
        $initials = mb_strtoupper(mb_substr($words[0], 0, 2));
    }

    return $initials !== '' ? $initials : 'L';
}

function app_logo_config_path(): string
{
    return trim((string) (config()['app_logo'] ?? ''));
}

function app_logo_upload_relative_path(): ?string
{
    $rel = app_logo_config_path();
    if (preg_match('#^branding/logo\.(png|jpe?g|webp|svg)$#i', $rel)) {
        $full = config()['upload_dir'] . '/' . $rel;
        if (is_file($full)) {
            return $rel;
        }
    }
    foreach (['png', 'jpg', 'jpeg', 'webp', 'svg'] as $ext) {
        $candidate = 'branding/logo.' . $ext;
        if (is_file(config()['upload_dir'] . '/' . $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function app_default_logo_relative_path(): ?string
{
    $full = dirname(__DIR__) . '/' . APP_DEFAULT_LOGO;
    return is_file($full) ? APP_DEFAULT_LOGO : null;
}

/** @deprecated Use app_logo_upload_relative_path() */
function app_logo_relative_path(): ?string
{
    return app_logo_upload_relative_path();
}

function app_has_custom_uploaded_logo(): bool
{
    return app_logo_upload_relative_path() !== null;
}

function app_logo_url(): ?string
{
    if (app_has_custom_uploaded_logo()) {
        return url('brand-asset.php');
    }
    $bundled = app_default_logo_relative_path();
    return $bundled ? url($bundled) : null;
}

function app_has_emoji_icon(): bool
{
    return trim((string) (config()['app_icon'] ?? '')) !== '';
}

function app_has_logo_image(): bool
{
    return app_logo_url() !== null;
}

function render_brand_mark(string $class = 'site-brand-mark'): void
{
    $logoUrl = app_logo_url();
    if ($logoUrl) {
        echo '<span class="' . e($class) . ' site-brand-mark-image">';
        echo '<img src="' . e($logoUrl) . '" alt="' . e(config()['app_name']) . '" decoding="async">';
        echo '</span>';
        return;
    }
    if (app_has_emoji_icon()) {
        echo '<span class="' . e($class) . '">' . e(config()['app_icon']) . '</span>';
        return;
    }
    echo '<span class="' . e($class) . ' site-brand-initials">' . e(app_initials()) . '</span>';
}

function render_favicon_link(): void
{
    $logoUrl = app_logo_url();
    if ($logoUrl) {
        echo '<link rel="icon" href="' . e($logoUrl) . '" type="image/png">';
        echo '<link rel="apple-touch-icon" href="' . e($logoUrl) . '">';
        return;
    }
    echo '<link rel="icon" href="' . e(url('favicon.php')) . '" type="image/svg+xml">';
}

function render_brand_theme(): void
{
    $theme = app_theme();
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', $theme['accent']) ? $theme['accent'] : '#0d9488';
    $nav = preg_match('/^#[0-9a-fA-F]{6}$/', $theme['nav']) ? $theme['nav'] : '#1e293b';
    $surface = preg_match('/^#[0-9a-fA-F]{6}$/', $theme['surface']) ? $theme['surface'] : '#f1f5f9';
    $dark = config()['theme']['dark'] ?? [];

    echo '<style>:root {';
    echo '--brand-accent:' . $accent . ';';
    echo '--brand-nav:' . $nav . ';';
    echo '--brand-surface:' . $surface . ';';
    echo '--brand-accent-soft:color-mix(in srgb,' . $accent . ' 14%,#fff);';
    echo '--brand-accent-link:' . $accent . ';';
    echo '}';
    echo '[data-theme="dark"] {';
    echo '--brand-bg:' . ($dark['bg'] ?? '#0f172a') . ';';
    echo '--brand-surface:' . ($dark['surface'] ?? '#0f172a') . ';';
    echo '--brand-sidebar:' . ($dark['sidebar'] ?? '#1e293b') . ';';
    echo '--brand-border:' . ($dark['border'] ?? '#475569') . ';';
    echo '--brand-text:' . ($dark['text'] ?? '#f1f5f9') . ';';
    echo '--brand-nav:' . ($dark['nav'] ?? '#020617') . ';';
    echo '--brand-accent-soft:color-mix(in srgb,' . $accent . ' 28%,#1e293b);';
    echo '--brand-accent-link:color-mix(in srgb,' . $accent . ' 72%,#fff);';
    echo '--text-muted:#cbd5e1;';
    echo '--text-subtle:#94a3b8;';
    echo '--text-secondary:#e2e8f0;';
    echo '--surface-card:' . ($dark['sidebar'] ?? '#1e293b') . ';';
    echo '--surface-muted:#1e293b;';
    echo '--surface-hover:#334155;';
    echo '--surface-inset:#0f172a;';
    echo '--input-bg:#0f172a;';
    echo '}</style>';
}