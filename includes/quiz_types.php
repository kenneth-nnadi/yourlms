<?php
declare(strict_types=1);

function quiz_auto_graded_types(): array
{
    return ['choice', 'true_false', 'multi_select', 'matching'];
}

function quiz_question_types(): array
{
    return [
        'choice' => 'Multiple choice',
        'true_false' => 'True / False',
        'multi_select' => 'Multiple select',
        'matching' => 'Matching',
        'essay' => 'Essay (manual grade)',
    ];
}

function decode_quiz_choices(array $question): array
{
    $raw = $question['choices'] ?? '[]';
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
    } else {
        $decoded = $raw;
    }
    if (!is_array($decoded)) {
        return ['type' => $question['question_type'] ?? 'choice', 'options' => [], 'correct' => [], 'left' => [], 'right' => [], 'pairs' => []];
    }

    $type = $question['question_type'] ?? 'choice';
    if ($type === 'multi_select' && isset($decoded['options'])) {
        return [
            'type' => 'multi_select',
            'options' => $decoded['options'],
            'correct' => $decoded['correct'] ?? [],
            'left' => [],
            'right' => [],
            'pairs' => [],
        ];
    }
    if ($type === 'matching' && isset($decoded['left'])) {
        return [
            'type' => 'matching',
            'options' => [],
            'correct' => [],
            'left' => $decoded['left'] ?? [],
            'right' => $decoded['right'] ?? [],
            'pairs' => $decoded['pairs'] ?? [],
        ];
    }
    if ($type === 'true_false') {
        return [
            'type' => 'true_false',
            'options' => ['True', 'False'],
            'correct' => [(int) ($question['correct_index'] ?? 0)],
            'left' => [],
            'right' => [],
            'pairs' => [],
        ];
    }

    return [
        'type' => 'choice',
        'options' => array_values($decoded),
        'correct' => [(int) ($question['correct_index'] ?? 0)],
        'left' => [],
        'right' => [],
        'pairs' => [],
    ];
}

function encode_quiz_choices(string $type, array $post): array
{
    return match ($type) {
        'essay' => ['choices' => '[]', 'correct_index' => 0],
        'true_false' => [
            'choices' => json_encode(['True', 'False']),
            'correct_index' => (int) ($post['correct_index'] ?? 0),
        ],
        'multi_select' => [
            'choices' => json_encode([
                'options' => array_values(array_filter(array_map('trim', $post['choices'] ?? []))),
                'correct' => array_map('intval', $post['correct_indices'] ?? []),
            ]),
            'correct_index' => 0,
        ],
        'matching' => [
            'choices' => json_encode([
                'left' => array_values(array_filter(array_map('trim', $post['match_left'] ?? []))),
                'right' => array_values(array_filter(array_map('trim', $post['match_right'] ?? []))),
                'pairs' => array_map('intval', $post['match_pairs'] ?? []),
            ]),
            'correct_index' => 0,
        ],
        default => [
            'choices' => json_encode(array_values(array_filter(array_map('trim', $post['choices'] ?? [])))),
            'correct_index' => (int) ($post['correct_index'] ?? 0),
        ],
    };
}

function normalize_quiz_answer(array $question, mixed $raw): mixed
{
    $type = $question['question_type'] ?? 'choice';
    if ($type === 'essay') {
        return is_string($raw) ? trim($raw) : '';
    }
    if ($type === 'multi_select') {
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_unique(array_map('intval', $raw)));
    }
    if ($type === 'matching') {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $k => $v) {
            $out[(int) $k] = (int) $v;
        }
        return $out;
    }
    return is_numeric($raw) ? (int) $raw : -1;
}

function score_quiz_question(array $question, mixed $answer, float $points): float
{
    $type = $question['question_type'] ?? 'choice';
    $meta = decode_quiz_choices($question);
    $answer = normalize_quiz_answer($question, $answer);

    if ($type === 'essay') {
        return 0.0;
    }
    if ($type === 'multi_select') {
        $expected = array_map('intval', $meta['correct'] ?? []);
        sort($expected);
        $given = is_array($answer) ? $answer : [];
        sort($given);
        return $expected === $given ? $points : 0.0;
    }
    if ($type === 'matching') {
        $pairs = $meta['pairs'] ?? [];
        if (!$pairs) {
            return 0.0;
        }
        $given = is_array($answer) ? $answer : [];
        $correct = 0;
        foreach ($pairs as $leftIdx => $rightIdx) {
            if (isset($given[(int) $leftIdx]) && (int) $given[(int) $leftIdx] === (int) $rightIdx) {
                $correct++;
            }
        }
        return $points * ($correct / count($pairs));
    }

    $expected = (int) ($meta['correct'][0] ?? $question['correct_index'] ?? 0);
    return (int) $answer === $expected ? $points : 0.0;
}

function question_points_for_quiz(array $quiz, array $questions, array $question): float
{
    if ($question['points'] !== null && $question['points'] !== '') {
        return (float) $question['points'];
    }
    $auto = array_filter($questions, fn($q) => ($q['question_type'] ?? 'choice') !== 'essay');
    $autoCount = count($auto) ?: 1;
    $essayCount = count($questions) - count($auto);
    if (($question['question_type'] ?? 'choice') === 'essay') {
        return (float) $quiz['points'] / max(1, $essayCount ?: count($questions));
    }
    return (float) $quiz['points'] / $autoCount;
}

function compute_quiz_score_extended(array $quiz, array $questions, array $answers, ?array $essayScores = null): array
{
    $mcEarned = 0.0;
    $essayEarned = 0.0;
    $essayCount = 0;
    $gradedEssays = 0;

    foreach ($questions as $q) {
        $qid = (int) $q['id'];
        $pts = question_points_for_quiz($quiz, $questions, $q);
        $type = $q['question_type'] ?? 'choice';

        if ($type === 'essay') {
            $essayCount++;
            if ($essayScores !== null && isset($essayScores[$qid])) {
                $essayEarned += (float) $essayScores[$qid];
                $gradedEssays++;
            }
            continue;
        }

        $ans = $answers[$qid] ?? $answers[(string) $qid] ?? null;
        $mcEarned += score_quiz_question($q, $ans, $pts);
    }

    $needsGrading = $essayCount > 0 && ($essayScores === null || $gradedEssays < $essayCount);

    return [
        'score' => round($mcEarned + $essayEarned, 2),
        'needs_grading' => $needsGrading,
        'mc_earned' => $mcEarned,
        'essay_earned' => $essayEarned,
    ];
}