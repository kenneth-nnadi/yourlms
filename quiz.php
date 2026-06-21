<?php
require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/notifications.php';
require_once __DIR__ . '/includes/quiz_types.php';
require_once __DIR__ . '/includes/quiz_ui.php';
$user = require_login();

$courseId = (int) ($_GET['course_id'] ?? 0);
$quizId = (int) ($_GET['id'] ?? 0);
$viewAttemptId = (int) ($_GET['attempt_id'] ?? 0);
$course = require_course_access($pdo, $courseId, $user);

$stmt = $pdo->prepare('SELECT * FROM quizzes WHERE id = ? AND course_id = ?');
$stmt->execute([$quizId, $courseId]);
$quiz = $stmt->fetch();
if (!$quiz) {
    http_response_code(404);
    die('Quiz not found.');
}
require_published_ref_access($pdo, $courseId, $user, 'quiz', $quizId);

$qStmt = $pdo->prepare('SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY position ASC');
$qStmt->execute([$quizId]);
$questions = $qStmt->fetchAll();

$attemptCount = quiz_attempt_count($pdo, $quizId, $user['id']);
$maxAttempts = (int) ($quiz['max_attempts'] ?? 1);
$canParticipate = user_can_participate($pdo, $courseId, $user);
$canAttempt = $canParticipate && quiz_allows_attempt($pdo, $quiz, $user['id']);
$pastDue = quiz_is_past_due($quiz);
$isStaff = user_can_grade($pdo, $courseId, $user);

$reviewAttempt = null;
if ($viewAttemptId) {
    $aStmt = $pdo->prepare('SELECT * FROM quiz_attempts WHERE id = ? AND quiz_id = ?');
    $aStmt->execute([$viewAttemptId, $quizId]);
    $reviewAttempt = $aStmt->fetch();
    if ($reviewAttempt && !$isStaff && (int) $reviewAttempt['user_id'] !== (int) $user['id']) {
        $reviewAttempt = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canParticipate) {
    if (!$canAttempt) {
        flash('error', $pastDue && ($quiz['lock_after_due'] ?? 0)
            ? 'This quiz is closed — the due date has passed.'
            : 'You have used all allowed attempts.');
        redirect("quiz.php?course_id={$courseId}&id={$quizId}");
    }

    $answers = [];
    foreach ($questions as $q) {
        $qid = (int) $q['id'];
        $raw = $_POST['q'][$qid] ?? $_POST['q'][(string) $qid] ?? null;
        $answers[$qid] = normalize_quiz_answer($q, $raw);
    }

    $result = compute_quiz_score($quiz, $questions, $answers, null);
    $pdo->prepare('INSERT INTO quiz_attempts (quiz_id, user_id, answers, score, needs_grading) VALUES (?, ?, ?, ?, ?)')
        ->execute([$quizId, $user['id'], json_encode($answers), $result['score'], $result['needs_grading'] ? 1 : 0]);
    $newId = (int) $pdo->lastInsertId();

    if ($result['needs_grading']) {
        notify_course_staff(
            $pdo,
            $courseId,
            'submission',
            'Quiz needs grading: ' . $quiz['title'],
            $user['full_name'] . ' submitted a quiz with essay answers.',
            "quiz-grade.php?course_id={$courseId}&attempt_id={$newId}",
            (int) $user['id']
        );
    } else {
        notify_user(
            $pdo,
            (int) $user['id'],
            $courseId,
            'grade',
            'Quiz submitted: ' . $quiz['title'],
            'Your score: ' . number_format($result['score'], 1) . ' / ' . $quiz['points'],
            "quiz.php?course_id={$courseId}&id={$quizId}&attempt_id={$newId}",
            false
        );
    }

    flash('success', $result['needs_grading'] ? 'Quiz submitted — essay answers await instructor grading.' : 'Quiz submitted.');
    redirect("quiz.php?course_id={$courseId}&id={$quizId}&attempt_id={$newId}");
}

$lastAttempt = null;
if (!$reviewAttempt) {
    $aStmt = $pdo->prepare('SELECT * FROM quiz_attempts WHERE quiz_id = ? AND user_id = ? ORDER BY submitted_at DESC LIMIT 1');
    $aStmt->execute([$quizId, $user['id']]);
    $lastAttempt = $aStmt->fetch() ?: null;
}

render_head($quiz['title']);
render_app_shell_start($user, 'courses', "quizzes.php?course_id={$courseId}");
render_course_shell_start($course, 'quizzes', $courseId);
render_course_header('Quiz', course_header_actions($pdo, $courseId, $user));
?>
<div class="course-page">
  <h1 style="font-size:1.75rem;font-weight:700;margin:0;"><?= e($quiz['title']) ?></h1>
  <p style="font-size:14px;color:#71717a;margin:8px 0 0;">
    <?= count($questions) ?> questions · <?= e((string)$quiz['points']) ?> pts
    <?php if ($quiz['due_at']): ?> · Due <?= e(format_datetime($quiz['due_at'])) ?><?php endif; ?>
    <?php if ($maxAttempts > 0): ?> · <?= $attemptCount ?>/<?= $maxAttempts ?> attempts<?php else: ?> · Unlimited attempts<?php endif; ?>
    <?php if ($pastDue): ?><span class="late-badge">Past due</span><?php endif; ?>
  </p>
  <?php if ($quiz['description']): ?>
    <div class="content-box user-html-content" style="margin-top:16px;"><?php render_rich_content($quiz['description'], $quiz['description_format'] ?? 'text'); ?></div>
  <?php endif; ?>

  <?php if ($attemptCount > 0 && $canParticipate): ?>
    <p style="margin-top:16px;font-size:14px;">
      <a href="<?= url("quiz-attempts.php?course_id={$courseId}&quiz_id={$quizId}&student_id=" . $user['id']) ?>">View all your attempts (<?= $attemptCount ?>)</a>
    </p>
  <?php endif; ?>

  <?php if ($reviewAttempt):
    $savedAnswers = json_decode($reviewAttempt['answers'], true) ?: [];
    $essayScores = $reviewAttempt['essay_scores'] ? json_decode($reviewAttempt['essay_scores'], true) : null;
  ?>
    <div class="grade-banner" style="margin-top:16px;">
      Attempt review · <?= e(format_datetime($reviewAttempt['submitted_at'])) ?>
      · Score: <?= $reviewAttempt['needs_grading'] ? 'Awaiting essay grading' : e(number_format((float)$reviewAttempt['score'], 1)) . ' / ' . e((string)$quiz['points']) ?>
    </div>
    <div style="margin-top:24px;">
      <?php foreach ($questions as $idx => $q):
        $qid = (int) $q['id'];
        $given = $savedAnswers[$qid] ?? $savedAnswers[(string) $qid] ?? null;
        render_quiz_question_review($q, $idx, $given, $essayScores, (bool) $reviewAttempt['needs_grading']);
      endforeach; ?>
    </div>
    <a class="btn btn-outline" href="<?= url("quiz.php?course_id={$courseId}&id={$quizId}") ?>">Back to quiz</a>
  <?php elseif ($canParticipate && $canAttempt): ?>
    <form method="post" style="margin-top:24px;">
      <?php foreach ($questions as $idx => $q):
        render_quiz_question_field($q, $idx);
      endforeach; ?>
      <?php if ($questions): ?>
        <button class="btn" type="submit">Submit quiz</button>
      <?php endif; ?>
    </form>
  <?php elseif ($lastAttempt && $canParticipate): ?>
    <div class="grade-banner" style="margin-top:16px;">
      Last attempt: <?= $lastAttempt['needs_grading'] ? 'Awaiting grading' : e(number_format((float)$lastAttempt['score'], 1)) . ' / ' . e((string)$quiz['points']) ?>
      · <?= e(format_datetime($lastAttempt['submitted_at'])) ?>
      · <a href="<?= url("quiz.php?course_id={$courseId}&id={$quizId}&attempt_id=" . (int)$lastAttempt['id']) ?>">Review answers</a>
    </div>
    <p style="margin-top:24px;color:#71717a;font-size:14px;">
      <?= ($pastDue && ($quiz['lock_after_due'] ?? 0))
          ? 'This quiz is closed — the due date has passed.'
          : 'You have used all ' . ($maxAttempts ?: 1) . ' allowed attempt(s).' ?>
    </p>
  <?php elseif ($canParticipate): ?>
    <p style="margin-top:24px;color:#71717a;font-size:14px;">
      <?= ($pastDue && ($quiz['lock_after_due'] ?? 0))
          ? 'This quiz is closed — the due date has passed.'
          : 'You have used all ' . ($maxAttempts ?: 1) . ' allowed attempt(s).' ?>
    </p>
  <?php else: ?>
    <p style="margin-top:24px;color:#71717a;font-size:14px;">
      <?= user_is_course_guest($pdo, $courseId, $user)
          ? 'Guests can view this quiz but cannot submit answers.'
          : 'Preview mode — only enrolled students can submit quiz attempts.' ?>
    </p>
  <?php endif; ?>
</div>
<?php
render_course_shell_end();
render_app_shell_end();