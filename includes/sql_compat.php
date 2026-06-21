<?php
declare(strict_types=1);

function db_driver(?array $config = null): string
{
    $config ??= config();
    return strtolower((string) ($config['db']['driver'] ?? 'mysql'));
}

function db_is_sqlite(?array $config = null): bool
{
    return db_driver($config) === 'sqlite';
}

function db_now_sql(?array $config = null): string
{
    return db_is_sqlite($config) ? "datetime('now')" : 'NOW()';
}

function mysql_abbrev_to_iana(string $abbrev): string
{
    $map = [
        'UTC' => 'UTC',
        'GMT' => 'UTC',
        'PDT' => 'America/Los_Angeles',
        'PST' => 'America/Los_Angeles',
        'MDT' => 'America/Denver',
        'MST' => 'America/Denver',
        'CDT' => 'America/Chicago',
        'CST' => 'America/Chicago',
        'EDT' => 'America/New_York',
        'EST' => 'America/New_York',
        'ADT' => 'America/Halifax',
        'AST' => 'America/Halifax',
        'CEST' => 'Europe/Berlin',
        'CET' => 'Europe/Berlin',
        'BST' => 'Europe/London',
        'JST' => 'Asia/Tokyo',
        'AEST' => 'Australia/Sydney',
        'AEDT' => 'Australia/Sydney',
    ];
    $key = strtoupper(trim($abbrev));
    return $map[$key] ?? 'UTC';
}

function db_detect_mysql_timezone(PDO $pdo): string
{
    try {
        $sys = (string) $pdo->query('SELECT @@system_time_zone')->fetchColumn();
        if ($sys !== '' && strtoupper($sys) !== 'SYSTEM') {
            return mysql_abbrev_to_iana($sys);
        }
    } catch (Throwable) {
    }
    return 'UTC';
}

function db_apply_timezone(PDO $pdo, array $config): string
{
    $tz = trim((string) ($config['timezone'] ?? ''));
    if ($tz === '') {
        $tz = db_is_sqlite($config) ? 'UTC' : db_detect_mysql_timezone($pdo);
    }
    date_default_timezone_set($tz);
    if (!db_is_sqlite($config)) {
        $offset = (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('P');
        $pdo->exec('SET time_zone = ' . $pdo->quote($offset));
    }
    return $tz;
}

function sql_enroll_upsert(?array $config = null): string
{
    if (db_is_sqlite($config)) {
        return 'INSERT INTO enrollments (course_id, user_id, role) VALUES (?, ?, ?)
                ON CONFLICT(course_id, user_id) DO UPDATE SET role = excluded.role';
    }
    return 'INSERT INTO enrollments (course_id, user_id, role) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE role = VALUES(role)';
}

function sql_submission_upsert(?array $config = null): string
{
    $now = db_now_sql($config);
    if (db_is_sqlite($config)) {
        return "INSERT INTO submissions (assignment_id, user_id, content, file_path, file_name, is_late, submitted_at)
                VALUES (?, ?, ?, ?, ?, ?, {$now})
                ON CONFLICT(assignment_id, user_id) DO UPDATE SET
                  content = excluded.content,
                  file_path = COALESCE(excluded.file_path, file_path),
                  file_name = COALESCE(excluded.file_name, file_name),
                  is_late = excluded.is_late,
                  submitted_at = {$now}";
    }
    return "INSERT INTO submissions (assignment_id, user_id, content, file_path, file_name, is_late, submitted_at)
            VALUES (?, ?, ?, ?, ?, ?, {$now})
            ON DUPLICATE KEY UPDATE content = VALUES(content), file_path = COALESCE(VALUES(file_path), file_path),
            file_name = COALESCE(VALUES(file_name), file_name), is_late = VALUES(is_late), submitted_at = {$now}";
}

function sql_discussion_grade_upsert(?array $config = null): string
{
    $now = db_now_sql($config);
    if (db_is_sqlite($config)) {
        return "INSERT INTO discussion_grades (discussion_id, user_id, points, feedback, graded_at)
                VALUES (?, ?, ?, ?, {$now})
                ON CONFLICT(discussion_id, user_id) DO UPDATE SET
                  points = excluded.points, feedback = excluded.feedback, graded_at = {$now}";
    }
    return "INSERT INTO discussion_grades (discussion_id, user_id, points, feedback, graded_at)
            VALUES (?, ?, ?, ?, {$now})
            ON DUPLICATE KEY UPDATE points = VALUES(points), feedback = VALUES(feedback), graded_at = {$now}";
}

function sql_enrollment_role_order(): string
{
    if (db_is_sqlite()) {
        return "CASE e.role WHEN 'instructor' THEN 1 WHEN 'ta' THEN 2 WHEN 'student' THEN 3 WHEN 'guest' THEN 4 ELSE 5 END";
    }
    return "FIELD(e.role, 'instructor', 'ta', 'student', 'guest')";
}

function sql_enroll_insert_ignore(?array $config = null): string
{
    if (db_is_sqlite($config)) {
        return 'INSERT OR IGNORE INTO enrollments (course_id, user_id, role) VALUES (?, ?, ?)';
    }
    return 'INSERT IGNORE INTO enrollments (course_id, user_id, role) VALUES (?, ?, ?)';
}