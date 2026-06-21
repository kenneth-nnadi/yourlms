<?php
declare(strict_types=1);

if (!is_file(dirname(__DIR__) . '/.setup-complete') && !is_file(dirname(__DIR__) . '/.install-lock')) {
    header('Location: setup.php');
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';

if (current_user()) {
    redirect('/dashboard.php');
}
redirect('/login.php');