<?php
declare(strict_types=1);

function gradebook_columns_for_export(PDO $pdo, int $courseId, array $user): array
{
    $showUnpublished = user_can_view_unpublished($pdo, $courseId, $user);
    $columns = [];

    $assignments = $pdo->prepare('SELECT id, title, points, group_id FROM assignments WHERE course_id = ? ORDER BY due_at, title');
    $assignments->execute([$courseId]);
    foreach ($assignments->fetchAll() as $a) {
        $id = (int) $a['id'];
        $live = ref_is_live($pdo, $courseId, 'assignment', $id);
        if (!$showUnpublished && !$live) {
            continue;
        }
        $columns[] = ['kind' => 'assignment', 'id' => $id, 'title' => $a['title'], 'points' => $a['points'], 'group_id' => $a['group_id'] ?? null];
    }

    $quizzes = $pdo->prepare('SELECT id, title, points, group_id FROM quizzes WHERE course_id = ? ORDER BY due_at, title');
    $quizzes->execute([$courseId]);
    foreach ($quizzes->fetchAll() as $q) {
        $id = (int) $q['id'];
        $live = ref_is_live($pdo, $courseId, 'quiz', $id);
        if (!$showUnpublished && !$live) {
            continue;
        }
        $columns[] = ['kind' => 'quiz', 'id' => $id, 'title' => $q['title'], 'points' => $q['points'], 'group_id' => $q['group_id'] ?? null];
    }

    $discussions = $pdo->prepare('SELECT id, title, points, group_id FROM discussions WHERE course_id = ? AND points IS NOT NULL AND points > 0 ORDER BY title');
    $discussions->execute([$courseId]);
    foreach ($discussions->fetchAll() as $d) {
        $id = (int) $d['id'];
        $live = ref_is_live($pdo, $courseId, 'discussion', $id);
        if (!$showUnpublished && !$live) {
            continue;
        }
        $columns[] = ['kind' => 'discussion', 'id' => $id, 'title' => $d['title'], 'points' => $d['points'], 'group_id' => $d['group_id'] ?? null];
    }

    return $columns;
}

function grade_export_maps(PDO $pdo, int $courseId, array $students, array $columns): array
{
    $subMap = [];
    $assignmentIds = array_column(array_filter($columns, fn($c) => $c['kind'] === 'assignment'), 'id');
    if ($students && $assignmentIds) {
        $ph = implode(',', array_fill(0, count($assignmentIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM submissions WHERE assignment_id IN ({$ph})");
        $stmt->execute($assignmentIds);
        foreach ($stmt->fetchAll() as $s) {
            $subMap[$s['user_id']][$s['assignment_id']] = $s;
        }
    }

    $quizMap = [];
    $quizIds = array_column(array_filter($columns, fn($c) => $c['kind'] === 'quiz'), 'id');
    if ($students && $quizIds) {
        $ph = implode(',', array_fill(0, count($quizIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM quiz_attempts WHERE quiz_id IN ({$ph}) ORDER BY submitted_at DESC");
        $stmt->execute($quizIds);
        foreach ($stmt->fetchAll() as $a) {
            $uid = (int) $a['user_id'];
            $qid = (int) $a['quiz_id'];
            if (!isset($quizMap[$uid][$qid])) {
                $quizMap[$uid][$qid] = $a;
            }
        }
    }

    $discMap = [];
    $discIds = array_column(array_filter($columns, fn($c) => $c['kind'] === 'discussion'), 'id');
    if ($students && $discIds) {
        $ph = implode(',', array_fill(0, count($discIds), '?'));
        $stmt = $pdo->prepare("SELECT * FROM discussion_grades WHERE discussion_id IN ({$ph})");
        $stmt->execute($discIds);
        foreach ($stmt->fetchAll() as $g) {
            $discMap[$g['user_id']][$g['discussion_id']] = $g;
        }
    }

    return ['sub' => $subMap, 'quiz' => $quizMap, 'disc' => $discMap];
}

function cell_score_for_export(array $col, int $uid, array $maps): ?float
{
    if ($col['kind'] === 'assignment') {
        $sub = $maps['sub'][$uid][$col['id']] ?? null;
        return $sub && $sub['grade'] !== null ? (float) $sub['grade'] : null;
    }
    if ($col['kind'] === 'quiz') {
        $att = $maps['quiz'][$uid][$col['id']] ?? null;
        return $att && !$att['needs_grading'] ? (float) $att['score'] : null;
    }
    $g = $maps['disc'][$uid][$col['id']] ?? null;
    return $g && $g['points'] !== null ? (float) $g['points'] : null;
}

function build_grades_csv(PDO $pdo, int $courseId, array $user): string
{
    $students = course_students($pdo, $courseId);
    $columns = gradebook_columns_for_export($pdo, $courseId, $user);
    $groups = assignment_groups_for_course($pdo, $courseId);
    $showWeighted = $groups && array_sum(array_map(fn($g) => (float) $g['weight'], $groups)) > 0;
    $maps = grade_export_maps($pdo, $courseId, $students, $columns);

    $out = fopen('php://temp', 'r+');
    $header = ['Student', 'Email'];
    foreach ($columns as $col) {
        $header[] = ucfirst($col['kind']) . ': ' . $col['title'] . ' (' . $col['points'] . ' pts)';
    }
    if ($showWeighted) {
        $header[] = 'Weighted %';
    }
    $header[] = 'Total earned';
    $header[] = 'Total possible';
    fputcsv($out, $header);

    foreach ($students as $st) {
        $uid = (int) $st['id'];
        $row = [$st['full_name'], $st['email']];
        $totalEarned = 0.0;
        $totalPossible = 0.0;
        $gradeRows = [];
        foreach ($columns as $col) {
            $totalPossible += (float) $col['points'];
            $score = cell_score_for_export($col, $uid, $maps);
            if ($score !== null) {
                $totalEarned += $score;
            }
            $gradeRows[] = [
                'points' => (float) $col['points'],
                'group_id' => $col['group_id'] ?? null,
                'score' => $score,
            ];
            $row[] = $score !== null ? (string) round($score, 2) : '';
        }
        if ($showWeighted) {
            $weighted = weighted_grade_summary($groups, $gradeRows);
            $row[] = $weighted ? (string) round($weighted['weighted_percent'], 2) : '';
        }
        $row[] = (string) round($totalEarned, 2);
        $row[] = (string) round($totalPossible, 2);
        fputcsv($out, $row);
    }

    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);
    return $csv ?: '';
}