<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
logout_user();
redirect('/login.php');