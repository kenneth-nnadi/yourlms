<?php
require __DIR__ . '/../includes/bootstrap.php';
redirect('/admin/people.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));