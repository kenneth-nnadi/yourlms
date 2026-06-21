<?php
declare(strict_types=1);

if (!is_file(__DIR__ . '/.setup-complete') && !is_file(__DIR__ . '/.install-lock')) {
    header('Location: setup.php');
    exit;
}

require __DIR__ . '/includes/bootstrap.php';

if (current_user()) {
    redirect('/dashboard.php');
}
redirect('/login.php');