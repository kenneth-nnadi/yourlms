<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/notifications.php';
$user = require_login();

$courseId = (int) ($_GET['course_id'] ?? $_POST['course_id'] ?? 0);
$attemptId = (int) ($_GET['attempt_id'] ?? $_POST['attempt_id'] ?? 0);
$course = require_course_access($pdo, $courseId, $user);

if (!user_can_grade($pdo, $courseId, $user)) {
    flash('error', 'You do not have permission to grade quizzes.');
    redirect("gradebook.php?course_id={$courseId}");
}

$stmt = $pdo->prepare(
    'SELECT qa.*, q.title AS quiz_title, q.points AS quiz_points, q.course_id, u.full_name
     FROM quiz_attempts qa
     JOIN quizzes q ON q.id = qa.quiz_id
     JOIN users u ON u.id = qa.user_id
     WHERE qa.id = ? AND q.course_id = ?'
);
$stmt->execute([$attemptId, $courseId]);
$attempt = $stmt->fetch();
if (!$attempt) {
    http_response_code(404);
    die('Attempt not found.');
}

$qStmt = $pdo->prepare('SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY position');
$qStmt->execute([(int) $attempt['quiz_id']]);
$questions = $qStmt->fetchAll();
$answers = json_decode($attempt['answers'], true) ?: [];
$essayScores = json_decode($attempt['essay_scores'] ?? '[]', true) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_essay_grades'])) {
    $newScores = [];
    foreach ($questions as $q) {
        if (($q['question_type'] ?? 'choice') !== 'essay') {
            continue;
        }
        $qid = (int) $q['id'];
        $val = $_POST['essay_score'][$qid] ?? '';
        if ($val !== '') {
            $newScores[$qid] = (float) $val;
        }
    }
    $quiz = ['id' => $attempt['quiz_id'], 'points' => $attempt['quiz_points']];
    $result = compute_quiz_score($quiz, $questions, $answers, $newScores);
    $pdo->prepare('UPDATE quiz_attempts SET essay_scores = ?, score = ?, needs_grading = ? WHERE id = ?')
        ->execute([json_encode($newScores), $result['score'], $result['needs_grading'] ? 1 : 0, $attemptId]);
    if (!$result['needs_grading']) {
        notify_user(
            $pdo,
            (int) $attempt['user_id'],
            $courseId,
            'grade',
            'Quiz graded: ' . $attempt['quiz_title'],
            'Your score: ' . number_format($result['score'], 1) . ' / ' . $attempt['quiz_points'],
            "quiz.php?course_id={$courseId}&id={$attempt['quiz_id']}&attempt_id={$attemptId}",
            true
        );
    }
    flash('success', 'Quiz grades saved.');
    redirect("quiz-grade.php?course_id={$courseId}&attempt_id={$attemptId}");
}

$quiz = ['id' => $attempt['quiz_id'], 'points' => $attempt['quiz_points']];
$preview = compute_quiz_score($quiz, $questions, $answers, $essayScores ?: null);

render_head('Grade quiz');
render_app_shell_start($user, 'courses', "gradebook.php?course_id={$courseId}");
render_course_shell_start($course, 'gradebook', $courseId);
render_course_header('Grade quiz attempt', '<a class="btn btn-sm btn-outline" href="' . url("gradebook.php?course_id={$courseId}") . '">Back to gradebook</a>');
?>
<div class="course-page">
  <h1 style="font-size:1.5rem;font-weight:700;margin:0;"><?= e($attempt['quiz_title']) ?></h1>
  <p style="font-size:14px;color:#71717a;margin:8px 0 24px;">
    <?= e($attempt['full_name']) ?> · <?= e(format_datetime($attempt['submitted_at'])) ?>
    · Current score: <?= e(number_format((float) $attempt['score'], 1)) ?> / <?= e((string) $attempt['quiz_points']) ?>
  </p>

  <form method="post">
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
    <input type="hidden" name="attempt_id" value="<?= $attemptId ?>">
    <input type="hidden" name="save_essay_grades" value="1">
    <?php foreach ($questions as $idx => $q):
      $qid = (int) $q['id'];
      $isEssay = ($q['question_type'] ?? 'choice') === 'essay';
    ?>
      <div class="quiz-question" style="margin-bottom:20px;">
        <div class="quiz-q-num">QUESTION <?= $idx + 1 ?><?= $isEssay ? ' (essay — manual grade)' : ' (auto-graded)' ?></div>
        <div style="font-weight:500;margin-bottom:8px;"><?= e($q['question']) ?></div>
        <?php if ($isEssay): ?>
          <div class="content-box" style="margin-bottom:12px;"><?= e($answers[$qid] ?? $answers[(string) $qid] ?? '(no answer)') ?></div>
          <label style="font-size:12px;font-weight:600;">Points</label>
          <input type="number" step="0.1" min="0" name="essay_score[<?= $qid ?>]" value="<?= e(isset($essayScores[$qid]) ? (string) $essayScores[$qid] : '') ?>" style="width:100px;">
        <?php else:
          $choices = json_decode($q['choices'], true) ?: [];
          $ans = (int) ($answers[$qid] ?? $answers[(string) $qid] ?? -1);
          $correct = $ans === (int) $q['correct_index'];
        ?>
          <div style="font-size:14px;color:<?= $correct ? '#16a34a' : '#dc2626' ?>;">
            <?= $correct ? '✓ Correct' : '✗ Incorrect' ?> — <?= e($choices[$ans] ?? 'No answer') ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <button class="btn" type="submit">Save grades</button>
  </form>
</div>
<?php
render_course_shell_end();
render_app_shell_end();