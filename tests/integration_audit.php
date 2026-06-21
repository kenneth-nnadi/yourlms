<?php
declare(strict_types=1);

/**
 * Live integration audit — run against XAMPP:
 *   php tests/integration_audit.php
 */

$base = getenv('YOURLMS_BASE') ?: 'http://localhost/yourlms';
$cookieFile = sys_get_temp_dir() . '/yourlms_audit_' . bin2hex(random_bytes(4)) . '.txt';

$results = [];
$failures = 0;
$warnings = 0;

function http(string $url, string $method = 'GET', array $opts = []): array
{
    global $cookieFile;
    $ch = curl_init($url);
    $headers = $opts['headers'] ?? [];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_HEADER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if (!empty($opts['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
    }
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [
        'code' => $code,
        'headers' => substr((string) $raw, 0, $headerSize),
        'body' => substr((string) $raw, $headerSize),
        'error' => $err,
    ];
}

function record(string $area, string $feature, string $status, string $note = ''): void
{
    global $results, $failures, $warnings;
    $results[] = compact('area', 'feature', 'status', 'note');
    if ($status === 'FAIL') {
        $failures++;
    } elseif ($status === 'WARN') {
        $warnings++;
    }
}

function login(string $email, string $password): bool
{
    global $base;
    $page = http("{$base}/login.php");
    if ($page['code'] !== 200) {
        record('Auth', 'Login page loads', 'FAIL', "HTTP {$page['code']}");
        return false;
    }
    record('Auth', 'Login page loads', 'PASS', 'HTTP 200');

    if (!str_contains($page['body'], 'csrf_token')) {
        record('Security', 'CSRF token on login form', 'WARN', 'No csrf_token field found');
    } else {
        record('Security', 'CSRF token on login form', 'PASS');
    }

    preg_match('/name="csrf_token" value="([^"]+)"/', $page['body'], $m);
    $csrf = $m[1] ?? '';
    $resp = http("{$base}/login.php", 'POST', [
        'body' => http_build_query([
            'action' => 'login',
            'email' => $email,
            'password' => $password,
            'csrf_token' => $csrf,
        ]),
        'headers' => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    if (!in_array($resp['code'], [302, 303], true)) {
        record('Auth', "Login as {$email}", 'FAIL', "HTTP {$resp['code']}");
        return false;
    }
    if (!str_contains($resp['headers'], 'dashboard.php')) {
        record('Auth', "Login as {$email}", 'WARN', 'Redirect target unclear');
    } else {
        record('Auth', "Login as {$email}", 'PASS', 'Redirects to dashboard');
    }
    return true;
}

function getPage(string $path, string $area, string $feature, int $expect = 200, ?string $mustContain = null): void
{
    global $base;
    $resp = http("{$base}{$path}");
    if ($resp['error']) {
        record($area, $feature, 'FAIL', $resp['error']);
        return;
    }
    if ($resp['code'] !== $expect) {
        record($area, $feature, 'FAIL', "Expected {$expect}, got {$resp['code']} ({$path})");
        return;
    }
    if ($mustContain && !str_contains($resp['body'], $mustContain)) {
        record($area, $feature, 'WARN', "Missing expected content: {$mustContain}");
        return;
    }
    record($area, $feature, 'PASS', "{$path} → {$expect}");
}

function logout(): void
{
    global $base;
    http("{$base}/logout.php");
}

echo "YourLMS integration audit\nBase: {$base}\n\n";

// --- Public pages ---
getPage('/login.php', 'Auth', 'Sign-in page', 200, 'YourLMS');
getPage('/forgot-password.php', 'Auth', 'Forgot password page', 200);
getPage('/favicon.php', 'Branding', 'Dynamic favicon', 200);

// --- Instructor session ---
if (login('instructor@yourlms.test', 'password123')) {
    getPage('/dashboard.php', 'Student experience', 'Dashboard (instructor)', 200, 'Welcome back');
    getPage('/courses.php', 'Student experience', 'Courses list', 200);
    getPage('/calendar.php', 'Student experience', 'Calendar page', 200);
    getPage('/search.php', 'Student experience', 'Search page', 200);
    getPage('/notifications.php', 'Notifications', 'Notifications page', 200);
    getPage('/profile.php', 'Profile & branding', 'Profile page', 200);

    getPage('/admin/index.php', 'Teach hub', 'Teach dashboard', 200, 'Teaching tools');
    getPage('/admin/courses.php', 'Teach hub', 'Courses admin', 200);
    getPage('/admin/modules.php?course_id=1', 'Teach hub', 'Modules admin', 200);
    getPage('/admin/assignments.php', 'Teach hub', 'Assignments admin', 200);
    getPage('/admin/quizzes.php', 'Teach hub', 'Quizzes admin', 200);
    getPage('/admin/discussions.php', 'Teach hub', 'Discussions admin', 200);
    getPage('/admin/people.php?course_id=1', 'Teach hub', 'People / enrollments', 200);
    getPage('/admin/import.php', 'Import/Export', 'IMS import page', 200);
    getPage('/admin/import-json.php', 'Import/Export', 'JSON import page', 200);
    getPage('/admin/export.php', 'Import/Export', 'Course export page', 200);
    getPage('/admin/backup.php', 'Import/Export', 'Full backup page', 200);
    getPage('/admin/groups.php?course_id=1', 'Grades', 'Assignment groups', 200);
    getPage('/admin/grades-export.php?course_id=1', 'Grades', 'Grades CSV export', 200);
    getPage('/admin/rubrics.php?course_id=1', 'Grades', 'Rubrics admin', 200);
    getPage('/admin/comment-bank.php', 'Grades', 'Comment bank', 200);
    getPage('/admin/lti-tools.php?course_id=1', 'LTI', 'External tools admin', 200);
    getPage('/admin/api-tokens.php', 'API', 'API tokens admin', 200);

    getPage('/course.php?id=1', 'Course', 'Course home', 200);
    getPage('/assignments.php?course_id=1', 'Course', 'Assignments list', 200);
    getPage('/quizzes.php?course_id=1', 'Course', 'Quizzes list', 200);
    getPage('/discussions.php?course_id=1', 'Course', 'Discussions list', 200);
    getPage('/announcements.php?course_id=1', 'Course', 'Announcements', 200);
    getPage('/grades.php?course_id=1', 'Course', 'Student grades page', 200);
    getPage('/gradebook.php?course_id=1', 'Course', 'Gradebook', 200);
    getPage('/files.php?course_id=1', 'Course', 'Course files', 200);
    getPage('/tools.php?course_id=1', 'Course', 'Course tools', 200);
    getPage('/calendar-export.php', 'Calendar', 'iCal export', 200);

    // Mobile nav markup
    $dash = http("{$base}/dashboard.php");
    if (str_contains($dash['body'], 'site-menu-toggle')) {
        record('Mobile UI', 'Hamburger menu markup', 'PASS');
    } else {
        record('Mobile UI', 'Hamburger menu markup', 'FAIL', 'site-menu-toggle not found');
    }
    if (str_contains($dash['body'], 'site-menu')) {
        record('Mobile UI', 'Mobile drawer markup', 'PASS');
    } else {
        record('Mobile UI', 'Mobile drawer markup', 'FAIL');
    }

    // Security headers
    if (str_contains($dash['headers'], 'Content-Security-Policy')) {
        record('Security', 'CSP header', 'PASS');
    } else {
        record('Security', 'CSP header', 'WARN');
    }
    if (str_contains($dash['headers'], 'X-Frame-Options')) {
        record('Security', 'X-Frame-Options header', 'PASS');
    } else {
        record('Security', 'X-Frame-Options header', 'WARN');
    }

    // API without token
    $api = http("{$base}/api/index.php?route=courses");
    if ($api['code'] === 401) {
        record('API', 'Rejects unauthenticated requests', 'PASS');
    } else {
        record('API', 'Rejects unauthenticated requests', 'FAIL', "HTTP {$api['code']}");
    }

    logout();
    record('Auth', 'Logout', 'PASS');
}

// --- Student session ---
if (login('student@yourlms.test', 'password123')) {
    getPage('/dashboard.php', 'Roles', 'Student dashboard', 200);
    $admin = http("{$base}/admin/index.php");
    if (in_array($admin['code'], [302, 303], true) || $admin['code'] === 403) {
        record('Roles', 'Student blocked from Teach hub', 'PASS', "HTTP {$admin['code']}");
    } elseif (!str_contains($admin['body'], 'Teaching tools')) {
        record('Roles', 'Student blocked from Teach hub', 'PASS', 'No teach content');
    } else {
        record('Roles', 'Student blocked from Teach hub', 'FAIL', 'Student can access admin');
    }
    getPage('/course.php?id=1', 'Roles', 'Student course access', 200);
    getPage('/grades.php?course_id=1', 'Roles', 'Student grades view', 200);
    logout();
}

// --- Database checks via mysql ---
$mysql = '/Applications/XAMPP/xamppfiles/bin/mysql';
if (is_executable($mysql)) {
    $q = static function (string $sql) use ($mysql): string {
        return trim((string) shell_exec("{$mysql} -u root yourlms -N -e " . escapeshellarg($sql) . ' 2>/dev/null'));
    };
    $courseCount = (int) $q('SELECT COUNT(*) FROM courses');
    record('Data', 'Courses in database', $courseCount > 0 ? 'PASS' : 'WARN', "{$courseCount} course(s)");
    $moduleCount = (int) $q('SELECT COUNT(*) FROM modules');
    record('Data', 'Modules in database', $moduleCount > 0 ? 'PASS' : 'WARN', "{$moduleCount} module(s)");
    $quizCount = (int) $q('SELECT COUNT(*) FROM quizzes');
    record('Data', 'Quizzes in database', $quizCount > 0 ? 'PASS' : 'WARN', "{$quizCount} quiz(zes)");
    $enrollCount = (int) $q('SELECT COUNT(*) FROM enrollments');
    record('Data', 'Enrollments', $enrollCount > 0 ? 'PASS' : 'WARN', "{$enrollCount} enrollment(s)");
}

// --- Output report ---
$byArea = [];
foreach ($results as $r) {
    $byArea[$r['area']][] = $r;
}

foreach ($byArea as $area => $items) {
    echo "=== {$area} ===\n";
    foreach ($items as $r) {
        $icon = match ($r['status']) {
            'PASS' => '✓',
            'WARN' => '!',
            default => '✗',
        };
        $note = $r['note'] ? " — {$r['note']}" : '';
        echo "  {$icon} [{$r['status']}] {$r['feature']}{$note}\n";
    }
    echo "\n";
}

echo "Summary: " . count($results) . " checks, {$failures} failed, {$warnings} warnings\n";
@unlink($cookieFile);
exit($failures > 0 ? 1 : 0);