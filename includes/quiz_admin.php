<?php
declare(strict_types=1);

require_once __DIR__ . '/quiz_types.php';

function admin_persist_quiz_question(PDO $pdo, int $quizId, array $post, ?int $questionId = null): void
{
    $qType = $post['question_type'] ?? 'choice';
    if (!isset(quiz_question_types()[$qType])) {
        throw new InvalidArgumentException('Invalid question type.');
    }
    $question = trim($post['question'] ?? '');
    if ($question === '') {
        throw new InvalidArgumentException('Question text is required.');
    }

    $encoded = encode_quiz_choices($qType, $post);
    if ($qType === 'essay') {
        $pts = ($post['essay_points'] ?? '') !== '' ? (float) $post['essay_points'] : null;
        if ($questionId) {
            $pdo->prepare('UPDATE quiz_questions SET question = ?, question_type = ?, choices = ?, correct_index = ?, points = ? WHERE id = ? AND quiz_id = ?')
                ->execute([$question, 'essay', '[]', 0, $pts, $questionId, $quizId]);
        } else {
            $pos = (int) $pdo->query("SELECT COALESCE(MAX(position), -1) + 1 FROM quiz_questions WHERE quiz_id = {$quizId}")->fetchColumn();
            $pdo->prepare('INSERT INTO quiz_questions (quiz_id, question, question_type, choices, correct_index, points, position) VALUES (?, ?, ?, ?, 0, ?, ?)')
                ->execute([$quizId, $question, 'essay', '[]', $pts, $pos]);
        }
        return;
    }

    if ($questionId) {
        $pdo->prepare('UPDATE quiz_questions SET question = ?, question_type = ?, choices = ?, correct_index = ?, points = NULL WHERE id = ? AND quiz_id = ?')
            ->execute([$question, $qType, $encoded['choices'], $encoded['correct_index'], $questionId, $quizId]);
    } else {
        $pos = (int) $pdo->query("SELECT COALESCE(MAX(position), -1) + 1 FROM quiz_questions WHERE quiz_id = {$quizId}")->fetchColumn();
        $pdo->prepare('INSERT INTO quiz_questions (quiz_id, question, question_type, choices, correct_index, position) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$quizId, $question, $qType, $encoded['choices'], $encoded['correct_index'], $pos]);
    }
}

function render_quiz_question_form_fields(?array $question = null, string $selectId = 'question-type'): void
{
    $qType = $question['question_type'] ?? 'choice';
    $meta = $question ? decode_quiz_choices($question) : null;
    $questionText = $question['question'] ?? '';
    $correctIndex = (int) ($question['correct_index'] ?? 0);
    $essayPoints = $question !== null && $question['points'] !== null ? (string) $question['points'] : '';

    $panelDisplay = static function (string $type) use ($qType): string {
        return $qType === $type ? 'block' : 'none';
    };

    echo '<div class="form-group"><label>Type</label><select name="question_type" id="' . e($selectId) . '" class="quiz-q-type-select">';
    foreach (quiz_question_types() as $val => $label) {
        $sel = $qType === $val ? ' selected' : '';
        echo '<option value="' . e($val) . '"' . $sel . '>' . e($label) . '</option>';
    }
    echo '</select></div>';
    echo '<div class="form-group"><label>Question</label><textarea name="question" required rows="2">' . e($questionText) . '</textarea></div>';

    $choiceOptions = $meta && $qType === 'choice' ? $meta['options'] : [];
    echo '<div class="quiz-type-panel" data-type="choice" style="display:' . $panelDisplay('choice') . ';">';
    for ($i = 0; $i < 4; $i++) {
        $val = e($choiceOptions[$i] ?? '');
        echo '<div class="form-group"><label>Choice ' . ($i + 1) . '</label><input name="choices[]" value="' . $val . '"></div>';
    }
    echo '<div class="form-group"><label>Correct choice index (0-based)</label><input name="correct_index" type="number" min="0" value="' . $correctIndex . '" style="max-width:80px;"></div>';
    echo '</div>';

    $tfCorrect = $qType === 'true_false' ? $correctIndex : 0;
    echo '<div class="quiz-type-panel" data-type="true_false" style="display:' . $panelDisplay('true_false') . ';">';
    echo '<div class="form-group"><label>Correct answer</label><select name="correct_index">';
    echo '<option value="0"' . ($tfCorrect === 0 ? ' selected' : '') . '>True</option>';
    echo '<option value="1"' . ($tfCorrect === 1 ? ' selected' : '') . '>False</option>';
    echo '</select></div></div>';

    $msOptions = $meta && $qType === 'multi_select' ? $meta['options'] : [];
    $msCorrect = $meta && $qType === 'multi_select' ? array_map('intval', $meta['correct'] ?? []) : [];
    echo '<div class="quiz-type-panel" data-type="multi_select" style="display:' . $panelDisplay('multi_select') . ';">';
    for ($i = 0; $i < 4; $i++) {
        $val = e($msOptions[$i] ?? '');
        $checked = in_array($i, $msCorrect, true) ? ' checked' : '';
        echo '<div class="form-group quiz-ms-row"><label>Option ' . ($i + 1) . '</label><input name="choices[]" value="' . $val . '"> ';
        echo '<label><input type="checkbox" name="correct_indices[]" value="' . $i . '"' . $checked . '> Correct</label></div>';
    }
    echo '</div>';

    $matchLeft = $meta && $qType === 'matching' ? $meta['left'] : [];
    $matchRight = $meta && $qType === 'matching' ? $meta['right'] : [];
    $matchRows = max(3, count($matchLeft), count($matchRight));
    echo '<div class="quiz-type-panel" data-type="matching" style="display:' . $panelDisplay('matching') . ';">';
    for ($i = 0; $i < $matchRows; $i++) {
        echo '<div class="form-group"><label>Left ' . ($i + 1) . '</label><input name="match_left[]" value="' . e($matchLeft[$i] ?? '') . '"></div>';
        echo '<div class="form-group"><label>Right match ' . ($i + 1) . '</label><input name="match_right[]" value="' . e($matchRight[$i] ?? '') . '"></div>';
        echo '<input type="hidden" name="match_pairs[]" value="' . $i . '">';
    }
    echo '<p style="font-size:12px;color:#71717a;">Pairs are matched in order (left 1 → right 1, etc.).</p>';
    echo '</div>';

    echo '<div class="quiz-type-panel" data-type="essay" style="display:' . $panelDisplay('essay') . ';">';
    echo '<div class="form-group"><label>Points (optional)</label><input name="essay_points" type="number" step="0.1" value="' . e($essayPoints) . '" style="max-width:120px;"></div>';
    echo '</div>';
}