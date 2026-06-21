<?php
declare(strict_types=1);

return [
    'app_name' => 'YourLMS',
    'app_tagline' => 'Your courses, on your server.',
    'app_icon' => '',
    'app_logo' => null,
    'theme' => [
        'accent' => '#0d9488',
        'nav' => '#1e293b',
        'surface' => '#f1f5f9',
        'enable_dark_mode' => true,
        'dark' => [
            'bg' => '#0f172a',
            'surface' => '#0f172a',
            'sidebar' => '#1e293b',
            'border' => '#475569',
            'text' => '#f1f5f9',
            'nav' => '#020617',
        ],
    ],
    'base_url' => '/yourlms',
    'timezone' => '',
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'yourlms',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'upload_dir' => __DIR__ . '/uploads',
    'upload_max_mb' => 50,
    'allow_self_registration' => false,
    'session' => [
        'secure' => false,
        'auto_secure' => true,
        'samesite' => 'Lax',
    ],
    'smtp' => [
        'enabled' => false,
        'host' => 'smtp.example.com',
        'port' => 587,
        'secure' => 'tls',
        'user' => '',
        'pass' => '',
        'from' => 'noreply@example.com',
        'from_name' => null,
    ],
];