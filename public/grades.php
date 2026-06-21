<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();

$courseId = (int) ($_GET['course_id'] ?? 0);
$course = require_course_access($pdo, $courseId, $user);

$canViewAllGrades = user_can_grade($pdo, $courseId, $user);
$targetUserId = $canViewAllGrades && isset($_GET['student_id'])
    ? (int) $_GET['student_id']
    : $user['id'];

$groups = assignment_groups_for_course($pdo, $courseId);

$assignments = $pdo->prepare('SELECT id, title, points, group_id FROM assignments WHERE course_id = ?');
$assignments->execute([$courseId]);
$assignments = filter_rows_by_published_refs($pdo, $courseId, $user, $assignments->fetchAll(), 'assignment');

$quizzes = $pdo->prepare('SELECT id, title, points, group_id FROM quizzes WHERE course_id = ?');
$quizzes->execute([$courseId]);
$quizzes = filter_rows_by_published_refs($pdo, $courseId, $user, $quizzes->fetchAll(), 'quiz');

$discussions = $pdo->prepare('SELECT id, title, points, group_id FROM discussions WHERE course_id = ? AND points IS NOT NULL AND points > 0');
$discussions->execute([$courseId]);
$discussions = filter_rows_by_published_refs($pdo, $courseId, $user, $discussions->fetchAll(), 'discussion');

$subs = $pdo->prepare('SELECT * FROM submissions WHERE user_id = ?');
$subs->execute([$targetUserId]);
$subMap = [];
foreach ($subs->fetchAll() as $s) {
    $subMap[$s['assignment_id']] = $s;
}

$attempts = $pdo->prepare('SELECT * FROM quiz_attempts WHERE user_id = ? ORDER BY submitted_at DESC');
$attempts->execute([$targetUserId]);
$attemptMap = [];
foreach ($attempts->fetchAll() as $a) {
    if (!isset($attemptMap[$a['quiz_id']])) {
        $attemptMap[$a['quiz_id']] = $a;
    }
}

$discGrades = $pdo->prepare('SELECT * FROM discussion_grades WHERE user_id = ?');
$discGrades->execute([$targetUserId]);
$discGradeMap = [];
foreach ($discGrades->fetchAll() as $g) {
    $discGradeMap[$g['discussion_id']] = $g;
}

$rows = [];
foreach ($assignments as $a) {
    $sub = $subMap[$a['id']] ?? null;
    $rows[] = [
        'kind' => 'Assignment',
        'title' => $a['title'],
        'points' => $a['points'],
        'group_id' => $a['group_id'] ?? null,
        'score' => $sub['grade'] ?? null,
        'status' => $sub ? ($sub['grade'] !== null ? 'Graded' : 'Submitted') : 'Not submitted',
    ];
}
foreach ($quizzes as $q) {
    $att = $attemptMap[$q['id']] ?? null;
    $rows[] = [
        'kind' => 'Quiz',
        'title' => $q['title'],
        'points' => $q['points'],
        'group_id' => $q['group_id'] ?? null,
        'score' => $att && !$att['needs_grading'] ? $att['score'] : ($att ? null : null),
        'status' => $att ? ($att['needs_grading'] ? 'Awaiting grading' : 'Attempted') : 'Not attempted',
    ];
}
foreach ($discussions as $d) {
    $g = $discGradeMap[$d['id']] ?? null;
    $rows[] = [
        'kind' => 'Discussion',
        'title' => $d['title'],
        'points' => $d['points'],
        'group_id' => $d['group_id'] ?? null,
        'score' => $g['points'] ?? null,
        'status' => $g && $g['points'] !== null ? 'Graded' : 'Not graded',
    ];
}

$earned = array_sum(array_map(fn($r) => (float) ($r['score'] ?? 0), $rows));
$possible = array_sum(array_map(fn($r) => (float) $r['points'], $rows));
$weighted = weighted_grade_summary($groups, $rows);

$students = $canViewAllGrades ? course_students($pdo, $courseId) : [];

render_head('Grades');
render_app_shell_start($user, 'courses', "course.php?id={$courseId}");
render_course_shell_start($course, 'grades', $courseId);
$headerRight = course_header_actions($pdo, $courseId, $user);
if ($canViewAllGrades) {
    $headerRight .= '<a class="btn btn-sm" href="' . url("gradebook.php?course_id={$courseId}") . '">Open gradebook</a>';
}
render_course_header('Grades', $headerRight);
?>
<div class="course-page">
  <?php if ($canViewAllGrades && $students): ?>
    <form method="get" style="margin-bottom:16px;display:flex;gap:8px;align-items:center;">
      <input type="hidden" name="course_id" value="<?= $courseId ?>">
      <label style="font-size:13px;font-weight:600;">Student</label>
      <select name="student_id" onchange="this.form.submit()">
        <?php foreach ($students as $st): ?>
          <option value="<?= (int)$st['id'] ?>" <?= $targetUserId === (int)$st['id'] ? 'selected' : '' ?>><?= e($st['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  <?php endif; ?>

  <div class="score-card">
    <div class="eyebrow"><?= $weighted ? 'Weighted grade' : 'Current Score' ?></div>
    <?php if ($weighted): ?>
      <div class="score"><?= number_format($weighted['weighted_percent'], 1) ?><span>%</span></div>
      <p style="font-size:12px;color:#71717a;margin:8px 0 0;">
        Based on assignment groups (<?= number_format($weighted['total_weight'], 0) ?>% total weight).
        Raw points: <?= number_format($earned, 1) ?> / <?= number_format($possible, 1) ?>.
      </p>
      <?php if ($weighted['parts']): ?>
        <p style="font-size:11px;color:#a1a1aa;margin:4px 0 0;"><?= e(implode(' · ', $weighted['parts'])) ?></p>
      <?php endif; ?>
      <?php if (($weighted['ungrouped']['possible'] ?? 0) > 0): ?>
        <p style="font-size:11px;color:#b45309;margin:4px 0 0;">Items without a group count in raw points but are excluded from the weighted %.</p>
      <?php endif; ?>
    <?php else: ?>
      <div class="score"><?= number_format($earned, 1) ?><span> / <?= number_format($possible, 1) ?></span></div>
    <?php endif; ?>
    <?php if (!$canViewAllGrades): ?>
      <p style="font-size:12px;color:#71717a;margin:8px 0 0;">Only published, module-linked items count toward your score.</p>
    <?php endif; ?>
  </div>
  <div class="panel">
    <table class="data-table">
      <thead>
        <tr><th>Item</th><th>Type</th><th>Status</th><th style="text-align:right;">Score</th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= e($r['title']) ?></td>
            <td style="color:#71717a;"><?= e($r['kind']) ?></td>
            <td style="color:#71717a;"><?= e($r['status']) ?></td>
            <td style="text-align:right;font-family:'JetBrains Mono',monospace;">
              <?= $r['score'] !== null ? number_format((float)$r['score'], 1) . ' / ' . e((string)$r['points']) : '— / ' . e((string)$r['points']) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (!$rows): ?>
    <?php render_empty_state(
        'No graded items visible yet.',
        $canViewAllGrades
            ? 'Publish assignments, quizzes, or discussions to a module to see grades here.'
            : 'Grades appear once your instructor publishes and grades work.'
    ); ?>
  <?php endif; ?>
</div>
<?php
render_course_shell_end();
render_app_shell_end();