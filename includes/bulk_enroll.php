<?php
declare(strict_types=1);

function parse_bulk_enroll_csv(string $contents): array
{
    $lines = preg_split('/\r\n|\r|\n/', trim($contents));
    if (!$lines) {
        throw new RuntimeException('CSV file is empty.');
    }
    $header = str_getcsv(array_shift($lines));
    $header = array_map(static fn($h) => strtolower(trim((string) $h)), $header);
    $emailIdx = array_search('email', $header, true);
    if ($emailIdx === false) {
        throw new RuntimeException('CSV must include an "email" column.');
    }
    $roleIdx = array_search('role', $header, true);
    $nameIdx = array_search('full_name', $header, true);
    if ($nameIdx === false) {
        $nameIdx = array_search('name', $header, true);
    }
    $passIdx = array_search('password', $header, true);

    $rows = [];
    foreach ($lines as $num => $line) {
        if (trim($line) === '') {
            continue;
        }
        $cols = str_getcsv($line);
        $email = strtolower(trim((string) ($cols[$emailIdx] ?? '')));
        if ($email === '') {
            continue;
        }
        $rows[] = [
            'line' => $num + 2,
            'email' => $email,
            'role' => trim((string) ($roleIdx !== false ? ($cols[$roleIdx] ?? 'student') : 'student')) ?: 'student',
            'full_name' => trim((string) ($nameIdx !== false ? ($cols[$nameIdx] ?? '') : '')),
            'password' => trim((string) ($passIdx !== false ? ($cols[$passIdx] ?? '') : '')),
        ];
    }
    if (!$rows) {
        throw new RuntimeException('No data rows found in CSV.');
    }
    return $rows;
}

function bulk_enroll_csv_rows(PDO $pdo, int $courseId, array $rows, bool $createMissing, string $defaultPassword = ''): array
{
    $stats = ['enrolled' => 0, 'created' => 0, 'skipped' => 0, 'errors' => []];

    foreach ($rows as $row) {
        $role = strtolower($row['role']);
        $roleMap = [
            'teacher' => 'instructor',
            'instructor' => 'instructor',
            'ta' => 'ta',
            'student' => 'student',
            'guest' => 'guest',
        ];
        $enrollRole = $roleMap[$role] ?? null;
        if (!$enrollRole || !in_array($enrollRole, enrollment_roles(), true)) {
            $stats['errors'][] = "Line {$row['line']}: invalid role \"{$row['role']}\".";
            $stats['skipped']++;
            continue;
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$row['email']]);
        $existing = $stmt->fetch();

        if (!$existing && $createMissing) {
            $fullName = $row['full_name'] !== '' ? $row['full_name'] : explode('@', $row['email'])[0];
            $password = $row['password'] !== '' ? $row['password'] : $defaultPassword;
            if (strlen($password) < 6) {
                $stats['errors'][] = "Line {$row['line']}: password required (min 6 chars) for new account {$row['email']}.";
                $stats['skipped']++;
                continue;
            }
            $accountRole = match ($enrollRole) {
                'instructor' => 'instructor',
                'ta' => 'ta',
                'guest' => 'guest',
                default => 'student',
            };
            $err = create_user_account($pdo, $row['email'], $password, $fullName, $accountRole);
            if ($err) {
                $stats['errors'][] = "Line {$row['line']}: {$err}";
                $stats['skipped']++;
                continue;
            }
            $stats['created']++;
            $stmt->execute([$row['email']]);
            $existing = $stmt->fetch();
        }

        if (!$existing) {
            $stats['errors'][] = "Line {$row['line']}: no account for {$row['email']} (enable create accounts or add users first).";
            $stats['skipped']++;
            continue;
        }

        enroll_user_in_course($pdo, $courseId, (int) $existing['id'], $enrollRole);
        $stats['enrolled']++;
    }

    return $stats;
}