<?php
declare(strict_types=1);

require_once __DIR__ . '/quiz_types.php';

function render_quiz_question_field(array $q, int $idx): void
{
    $qid = (int) $q['id'];
    $type = $q['question_type'] ?? 'choice';
    $meta = decode_quiz_choices($q);
    echo '<div class="quiz-question" role="group" aria-labelledby="quiz-q-' . $qid . '">';
    echo '<div class="quiz-q-num" id="quiz-q-' . $qid . '">QUESTION ' . ($idx + 1) . ' · ' . e(quiz_question_types()[$type] ?? $type) . '</div>';
    echo '<div style="font-weight:500;margin-bottom:12px;">' . e($q['question']) . '</div>';

    if ($type === 'essay') {
        echo '<label class="visually-hidden" for="q-' . $qid . '">Answer for question ' . ($idx + 1) . '</label>';
        echo '<textarea id="q-' . $qid . '" name="q[' . $qid . ']" rows="5" required placeholder="Type your answer…"></textarea>';
    } elseif ($type === 'multi_select') {
        foreach ($meta['options'] as $ci => $choice) {
            echo '<label class="quiz-choice"><input type="checkbox" name="q[' . $qid . '][]" value="' . (int) $ci . '"> ' . e($choice) . '</label>';
        }
    } elseif ($type === 'matching') {
        echo '<div class="quiz-matching-grid">';
        foreach ($meta['left'] as $li => $left) {
            echo '<div class="quiz-match-row">';
            echo '<span class="quiz-match-left">' . e($left) . '</span>';
            echo '<label class="visually-hidden" for="match-' . $qid . '-' . $li . '">Match for ' . e($left) . '</label>';
            echo '<select id="match-' . $qid . '-' . $li . '" name="q[' . $qid . '][' . (int) $li . ']" required>';
            echo '<option value="">— Select —</option>';
            foreach ($meta['right'] as $ri => $right) {
                echo '<option value="' . (int) $ri . '">' . e($right) . '</option>';
            }
            echo '</select></div>';
        }
        echo '</div>';
    } else {
        foreach ($meta['options'] as $ci => $choice) {
            echo '<label class="quiz-choice"><input type="radio" name="q[' . $qid . ']" value="' . (int) $ci . '" required> ' . e($choice) . '</label>';
        }
    }
    echo '</div>';
}

function render_quiz_question_review(array $q, int $idx, mixed $given, ?array $essayScores, bool $needsGrading): void
{
    $qid = (int) $q['id'];
    $type = $q['question_type'] ?? 'choice';
    $meta = decode_quiz_choices($q);
    echo '<div class="quiz-question quiz-review-question">';
    echo '<div class="quiz-q-num">QUESTION ' . ($idx + 1) . '</div>';
    echo '<div style="font-weight:500;margin-bottom:12px;">' . e($q['question']) . '</div>';

    if ($type === 'essay') {
        echo '<div class="content-box" style="background:#fafafa;">' . e((string) $given) . '</div>';
        if ($essayScores && isset($essayScores[$qid])) {
            echo '<p style="font-size:13px;color:#16a34a;margin-top:8px;">Essay score: ' . e((string) $essayScores[$qid]) . ' pts</p>';
        } elseif ($needsGrading) {
            echo '<p style="font-size:13px;color:#71717a;margin-top:8px;">Awaiting instructor grading</p>';
        }
        echo '</div>';
        return;
    }

    if ($type === 'multi_select') {
        $givenArr = is_array($given) ? array_map('intval', $given) : [];
        $expected = array_map('intval', $meta['correct'] ?? []);
        echo '<ul class="quiz-review-choices">';
        foreach ($meta['options'] as $ci => $choice) {
            $cls = in_array($ci, $expected, true) ? 'quiz-review-correct' : '';
            if (in_array($ci, $givenArr, true) && !in_array($ci, $expected, true)) {
                $cls = 'quiz-review-wrong';
            }
            echo '<li class="' . $cls . '">' . (in_array($ci, $givenArr, true) ? '▸ ' : '') . e($choice) . '</li>';
        }
        echo '</ul></div>';
        return;
    }

    if ($type === 'matching') {
        $pairs = $meta['pairs'] ?? [];
        echo '<ul class="quiz-review-choices">';
        foreach ($meta['left'] as $li => $left) {
            $expected = (int) ($pairs[$li] ?? $pairs[(string) $li] ?? -1);
            $g = is_array($given) ? (int) ($given[$li] ?? $given[(string) $li] ?? -1) : -1;
            $cls = $g === $expected ? 'quiz-review-correct' : 'quiz-review-wrong';
            $rightLabel = $meta['right'][$g] ?? '—';
            echo '<li class="' . $cls . '">' . e($left) . ' → ' . e($rightLabel) . '</li>';
        }
        echo '</ul></div>';
        return;
    }

    $givenIdx = (int) $given;
    $correctIdx = (int) ($meta['correct'][0] ?? $q['correct_index'] ?? 0);
    $isCorrect = $givenIdx === $correctIdx;
    echo '<ul class="quiz-review-choices">';
    foreach ($meta['options'] as $ci => $choice) {
        $cls = '';
        if ($ci === $correctIdx) {
            $cls = 'quiz-review-correct';
        } elseif ($ci === $givenIdx) {
            $cls = 'quiz-review-wrong';
        }
        echo '<li class="' . $cls . '">';
        echo ($ci === $givenIdx ? '▸ ' : '') . e($choice);
        if ($ci === $correctIdx) {
            echo '<span class="quiz-review-tag">Correct</span>';
        }
        if ($ci === $givenIdx && !$isCorrect) {
            echo '<span class="quiz-review-tag quiz-review-tag-wrong">Your answer</span>';
        }
        echo '</li>';
    }
    echo '</ul></div>';
}