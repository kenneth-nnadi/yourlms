<?php
declare(strict_types=1);

$GLOBALS['config'] = require dirname(__DIR__) . '/config.php';
$GLOBALS['config']['upload_dir'] = sys_get_temp_dir() . '/yourlms_phpunit';

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/quiz_types.php';
require_once dirname(__DIR__) . '/includes/security.php';