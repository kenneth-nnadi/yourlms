<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/bootstrap.php';
require_once $root . '/includes/quiz_types.php';
require_once $root . '/includes/api.php';
require_once $root . '/includes/course_export.php';
require_once $root . '/includes/course_duplicate.php';

$results = [];
$fail = 0;
$warn = 0;

$record = static function (string $area, string $feature, string $status, string $note = '') use (&$results, &$fail, &$warn): void {
    $results[] = compact('area', 'feature', 'status', 'note');
    if ($status === 'FAIL') $fail++;
    if ($status === 'WARN') $warn++;
};

echo "YourLMS deep functional audit\n\n";

// Quiz scoring (PHPUnit-free)
$qTf = ['id' => 1, 'question_type' => 'true_false', 'choices' => '["True","False"]', 'correct_index' => 0, 'points' => null];
$record('Quizzes', 'True/False auto-grading', score_quiz_question($qTf, 0, 5.0) === 5.0 ? 'PASS' : 'FAIL');
$qMs = ['id' => 2, 'question_type' => 'multi_select', 'choices' => json_encode(['options' => ['A','B','C'], 'correct' => [0,2]]), 'correct_index' => 0, 'points' => null];
$record('Quizzes', 'Multi-select auto-grading', score_quiz_question($qMs, [0,2], 10.0) === 10.0 ? 'PASS' : 'FAIL');
$qMatch = ['id' => 3, 'question_type' => 'matching', 'choices' => json_encode(['left'=>['L1','L2'],'right'=>['R1','R2'],'pairs'=>[0,1]]), 'correct_index' => 0, 'points' => null];
$record('Quizzes', 'Matching partial credit', score_quiz_question($qMatch, [0=>0,1=>0], 4.0) === 2.0 ? 'PASS' : 'FAIL');

// DB content checks
$quizCount = (int) $pdo->query('SELECT COUNT(*) FROM quizzes')->fetchColumn();
$quizInModule = (int) $pdo->query("SELECT COUNT(*) FROM module_items WHERE item_type='quiz'")->fetchColumn();
$record('Data / Publishing', 'Quiz exists in course', $quizCount > 0 ? 'PASS' : 'FAIL', "{$quizCount} quiz");
$record('Data / Publishing', 'Quiz linked to module (Go live)', $quizInModule > 0 ? 'PASS' : 'WARN', $quizInModule > 0 ? 'published in module' : 'quiz not in any module — students cannot access it');

$assignCount = (int) $pdo->query('SELECT COUNT(*) FROM assignments')->fetchColumn();
$record('Data / Publishing', 'Assignments in course', $assignCount > 0 ? 'PASS' : 'WARN', "{$assignCount} assignments — create via Teach → Assignments");

$announceCount = (int) $pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn();
$record('Data / Publishing', 'Announcements', $announceCount > 0 ? 'PASS' : 'WARN', "{$announceCount} announcements");

$subCount = (int) $pdo->query('SELECT COUNT(*) FROM submissions')->fetchColumn();
$attemptCount = (int) $pdo->query('SELECT COUNT(*) FROM quiz_attempts')->fetchColumn();
$record('Student workflow', 'Assignment submissions', $subCount > 0 ? 'PASS' : 'WARN', "{$subCount} submissions — not yet tested end-to-end");
$record('Student workflow', 'Quiz attempts', $attemptCount > 0 ? 'PASS' : 'WARN', "{$attemptCount} attempts — not yet tested end-to-end");

// API token round-trip
$instructor = $pdo->query("SELECT id FROM users WHERE email='instructor@yourlms.test' LIMIT 1")->fetch();
if ($instructor) {
    $tok = api_create_token($pdo, (int) $instructor['id'], 'audit-test');
    $hash = hash('sha256', $tok['token']);
    $found = $pdo->prepare('SELECT id FROM api_tokens WHERE token_hash = ?');
    $found->execute([$hash]);
    $record('API', 'Create API token', $found->fetch() ? 'PASS' : 'FAIL');

    $base = getenv('YOURLMS_BASE') ?: 'http://localhost/yourlms';
    $ch = curl_init("{$base}/api/index.php?route=courses");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok['token']],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode((string) $body, true);
    $record('API', 'GET /courses with token', ($code === 200 && !empty($data['courses'])) ? 'PASS' : 'FAIL', "HTTP {$code}");
    $pdo->prepare('DELETE FROM api_tokens WHERE token_hash = ?')->execute([$hash]);
} else {
    $record('API', 'Create API token', 'FAIL', 'instructor not found');
}

// Course export
try {
    $json = export_course_json($pdo, 1, $config);
    $record('Import/Export', 'Course JSON export', !empty($json['course']['code']) ? 'PASS' : 'FAIL', 'open-lms-course-v1');
} catch (Throwable $e) {
    $record('Import/Export', 'Course JSON export', 'FAIL', $e->getMessage());
}

// Config checks
$record('Email', 'SMTP email notifications', !empty($config['smtp']['enabled']) ? 'PASS' : 'WARN', $config['smtp']['enabled'] ? 'enabled' : 'disabled — in-app notifications still work');
$record('Auth', 'Self-registration', ($config['allow_self_registration'] ?? false) ? 'PASS' : 'WARN', 'invite-only (default) — instructors add users via People');
$record('Deployment', 'XAMPP/MySQL mode', (db_driver($config) === 'mysql') ? 'PASS' : 'WARN', db_driver($config));
$record('Deployment', 'SQLite shared-hosting mode', is_file($root . '/install.php') ? 'PASS' : 'FAIL', 'install.php present');
$record('Deployment', 'EC2 one-click install', is_file($root . '/deploy/ec2/install.sh') ? 'PASS' : 'FAIL');
$record('Deployment', 'Docker local install', is_file($root . '/deploy/debian-home/docker-compose.yml') ? 'PASS' : 'FAIL');

// IMS import package present
$ims = $root . '/docs/samples/sample.imscc.zip';
$record('Import/Export', 'IMS sample package bundled', is_file($ims) ? 'PASS' : 'WARN', is_file($ims) ? basename($ims) : 'zip not in project root');

// Rich editor
$record('Content', 'Quill rich text editor', is_file($root . '/assets/js/rich-editor.js') ? 'PASS' : 'FAIL');
$record('Content', 'Module pages from IMS import', (int) $pdo->query("SELECT COUNT(*) FROM module_items WHERE item_type='page'")->fetchColumn() > 0 ? 'PASS' : 'WARN');

// Security files
$record('Security', 'CSRF protection module', is_file($root . '/includes/csrf.php') ? 'PASS' : 'FAIL');
$record('Security', 'Rate limiting module', is_file($root . '/includes/security.php') ? 'PASS' : 'FAIL');

// Mobile
$css = file_get_contents($root . '/assets/css/style.css') ?: '';
$record('Mobile UI', 'Responsive CSS breakpoints', str_contains($css, 'site-menu-toggle') && str_contains($css, '@media (max-width: 768px)') ? 'PASS' : 'FAIL');
$layout = file_get_contents($root . '/includes/layout.php') ?: '';
$record('Mobile UI', 'Hamburger in layout', str_contains($layout, 'site-menu-toggle') ? 'PASS' : 'FAIL');

$byArea = [];
foreach ($results as $r) {
    $byArea[$r['area']][] = $r;
}
foreach ($byArea as $area => $items) {
    echo "=== {$area} ===\n";
    foreach ($items as $r) {
        $icon = match ($r['status']) { 'PASS' => '✓', 'WARN' => '!', default => '✗' };
        $note = $r['note'] ? " — {$r['note']}" : '';
        echo "  {$icon} [{$r['status']}] {$r['feature']}{$note}\n";
    }
    echo "\n";
}
echo "Summary: " . count($results) . " checks, {$fail} failed, {$warn} warnings\n";
exit($fail > 0 ? 1 : 0);