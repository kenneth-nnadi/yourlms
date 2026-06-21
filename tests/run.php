<?php
declare(strict_types=1);

/**
 * Simple smoke tests — run: php tests/run.php
 */

$root = dirname(__DIR__);
require_once $root . '/includes/security.php';
require_once $root . '/includes/helpers.php';

$config = require $root . '/config.php';
$GLOBALS['config'] = $config;

$failures = 0;
$assert = static function (bool $ok, string $label) use (&$failures): void {
    if ($ok) {
        echo "  ok  {$label}\n";
        return;
    }
    echo " FAIL {$label}\n";
    $failures++;
};

$GLOBALS['config']['upload_dir'] = sys_get_temp_dir() . '/yourlms_test_' . bin2hex(random_bytes(3));

echo "YourLMS smoke tests\n\n";

echo "security.php\n";
$_SESSION = [];
$assert(check_login_rate_limit() === null, 'rate limit clear initially');
record_login_failure();
record_login_failure();
record_login_failure();
record_login_failure();
record_login_failure();
$assert(check_login_rate_limit() !== null, 'rate limit after 5 failures');
clear_login_rate_limit();
$assert(check_login_rate_limit() === null, 'rate limit cleared after success');
$assert(check_password_reset_rate_limit() === null, 'password reset limit clear initially');
record_password_reset_attempt();
record_password_reset_attempt();
record_password_reset_attempt();
$assert(check_password_reset_rate_limit() !== null, 'password reset limit after 3 attempts');

$GLOBALS['config']['allow_self_registration'] = false;
$assert(self_registration_allowed() === false, 'invite-only when allow_self_registration false');
$GLOBALS['config']['allow_self_registration'] = true;
$assert(self_registration_allowed() === true, 'signup allowed when true');

echo "\nhelpers.php — weighted_grade_summary\n";
$groups = [
    ['id' => 1, 'name' => 'Homework', 'weight' => 60],
    ['id' => 2, 'name' => 'Exams', 'weight' => 40],
];
$rows = [
    ['points' => 100, 'group_id' => 1, 'score' => 80],
    ['points' => 100, 'group_id' => 2, 'score' => 90],
];
$w = weighted_grade_summary($groups, $rows);
$assert($w !== null, 'weighted summary returns data');
$assert(abs($w['weighted_percent'] - 84.0) < 0.1, 'weighted percent 80%×60% + 90%×40% = 84%');
$assert(weighted_grade_summary([], $rows) === null, 'no groups returns null');

echo "\ncontent_format_value\n";
$assert(content_format_value('html') === 'html', 'html format');
$assert(content_format_value('text') === 'text', 'text format');

echo "\n";
if ($failures === 0) {
    echo "All tests passed.\n";
    exit(0);
}
echo "{$failures} test(s) failed.\n";
exit(1);