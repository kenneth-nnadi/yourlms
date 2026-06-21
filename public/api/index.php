<?php
declare(strict_types=1);

define('API_REQUEST', true);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/api.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = $_GET['route'] ?? '';
$path = trim($path, '/');
$segments = $path !== '' ? explode('/', $path) : [];

$user = $method === 'OPTIONS' ? null : api_authenticate($pdo);

if ($method === 'OPTIONS') {
    api_json_response(204, []);
}

if ($segments === ['courses'] && $method === 'GET') {
    $rows = $pdo->query('SELECT id, code, name, term, published FROM courses ORDER BY code')->fetchAll();
    api_json_response(200, ['courses' => $rows]);
}

if (count($segments) === 2 && $segments[0] === 'courses' && $method === 'GET') {
    $courseId = (int) $segments[1];
    $stmt = $pdo->prepare('SELECT id, code, name, term, description, published FROM courses WHERE id = ?');
    $stmt->execute([$courseId]);
    $course = $stmt->fetch();
    if (!$course) {
        api_json_response(404, ['error' => 'Course not found.']);
    }
    api_json_response(200, ['course' => $course]);
}

if (count($segments) === 3 && $segments[0] === 'courses' && $segments[2] === 'students' && $method === 'GET') {
    $courseId = (int) $segments[1];
    $students = course_students($pdo, $courseId);
    api_json_response(200, ['students' => $students]);
}

if (count($segments) === 3 && $segments[0] === 'courses' && $segments[2] === 'grades' && $method === 'GET') {
    require_once dirname(__DIR__, 2) . '/includes/grade_export.php';
    $courseId = (int) $segments[1];
    $csv = build_grades_csv($pdo, $courseId, $user);
    api_json_response(200, ['course_id' => $courseId, 'format' => 'csv', 'data' => $csv]);
}

api_json_response(404, ['error' => 'Unknown endpoint.', 'hint' => 'Try /api/index.php?route=courses']);