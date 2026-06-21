<?php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
redirect('/admin/people.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));