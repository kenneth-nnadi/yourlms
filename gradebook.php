<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$courseId = (int) ($_GET['course_id'] ?? 0);
$course = require_course_access($pdo, $courseId, $user);

if (!user_can_grade($pdo, $courseId, $user)) {
    flash('error', 'You do not have permission to view the gradebook.');
    redirect("grades.php?course_id={$courseId}");
}

$students = course_students($pdo, $courseId);
$groups = assignment_groups_for_course($pdo, $courseId);

$assignments = $pdo->prepare('SELECT id, title, points, group_id FROM assignments WHERE course_id = ? ORDER BY due_at, title');
$assignments->execute([$courseId]);
$assignments = $assignments->fetchAll();
$quizzes = $pdo->prepare('SELECT id, title, points, group_id FROM quizzes WHERE course_id = ? ORDER BY due_at, title');
$quizzes->execute([$courseId]);
$quizzes = $quizzes->fetchAll();
$discussions = $pdo->prepare('SELECT id, title, points, group_id FROM discussions WHERE course_id = ? AND points IS NOT NULL AND points > 0 ORDER BY title');
$discussions->execute([$courseId]);
$discussions = $discussions->fetchAll();

$showUnpublished = user_can_view_unpublished($pdo, $courseId, $user);
$columns = [];
foreach ($assignments as $a) {
    $id = (int) $a['id'];
    $live = ref_is_live($pdo, $courseId, 'assignment', $id);
    if (!$showUnpublished && !$live) {
        continue;
    }
    $columns[] = ['kind' => 'assignment', 'id' => $id, 'title' => $a['title'], 'points' => $a['points'], 'group_id' => $a['group_id'] ?? null, 'live' => $live];
}
foreach ($quizzes as $q) {
    $id = (int) $q['id'];
    $live = ref_is_live($pdo, $courseId, 'quiz', $id);
    if (!$showUnpublished && !$live) {
        continue;
    }
    $columns[] = ['kind' => 'quiz', 'id' => $id, 'title' => $q['title'], 'points' => $q['points'], 'group_id' => $q['group_id'] ?? null, 'live' => $live];
}
foreach ($discussions as $d) {
    $id = (int) $d['id'];
    $live = ref_is_live($pdo, $courseId, 'discussion', $id);
    if (!$showUnpublished && !$live) {
        continue;
    }
    $columns[] = ['kind' => 'discussion', 'id' => $id, 'title' => $d['title'], 'points' => $d['points'], 'group_id' => $d['group_id'] ?? null, 'live' => $live];
}

$showWeighted = $groups && array_sum(array_map(fn($g) => (float) $g['weight'], $groups)) > 0;

$subMap = [];
if ($students && $assignments) {
    $ids = array_column($assignments, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM submissions WHERE assignment_id IN ({$ph})");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $s) {
        $subMap[$s['user_id']][$s['assignment_id']] = $s;
    }
}

$quizMap = [];
$quizAttemptCounts = [];
if ($students && $quizzes) {
    $ids = array_column($quizzes, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM quiz_attempts WHERE quiz_id IN ({$ph}) ORDER BY submitted_at DESC");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $a) {
        $uid = (int) $a['user_id'];
        $qid = (int) $a['quiz_id'];
        $quizAttemptCounts[$uid][$qid] = ($quizAttemptCounts[$uid][$qid] ?? 0) + 1;
        if (!isset($quizMap[$uid][$qid])) {
            $quizMap[$uid][$qid] = $a;
        }
    }
}

$discMap = [];
if ($students && $discussions) {
    $ids = array_column($discussions, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT * FROM discussion_grades WHERE discussion_id IN ({$ph})");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $g) {
        $discMap[$g['user_id']][$g['discussion_id']] = $g;
    }
}

render_head('Gradebook');
render_app_shell_start($user, 'courses', "course.php?id={$courseId}");
render_course_shell_start($course, 'gradebook', $courseId);
render_course_header('Gradebook', course_header_actions($pdo, $courseId, $user));
?>
<div class="course-page">
  <p style="font-size:14px;color:#71717a;margin-top:0;">
    All students and gradeable items. Quiz cells link to attempt history when multiple attempts exist.
    <a class="btn btn-sm btn-outline" href="<?= url('admin/grades-export.php?course_id=' . $courseId) ?>" style="margin-left:8px;">Export CSV</a>
    <?php if ($showUnpublished): ?>
      Columns marked <span class="unpublished-label">draft</span> are not yet published to a module.
    <?php endif; ?>
    <?php if ($showWeighted): ?>
      Weighted % uses assignment groups only — ungrouped items are excluded (like Canvas).
    <?php endif; ?>
  </p>
  <?php if (!$columns): ?>
    <?php render_empty_state('No gradeable items yet.', 'Add assignments or quizzes, then publish them to a module.', [
        ['label' => 'Manage assignments', 'href' => url('admin/assignments.php?course_id=' . $courseId), 'primary' => true],
        ['label' => 'Manage quizzes', 'href' => url('admin/quizzes.php?course_id=' . $courseId)],
    ]); ?>
  <?php elseif (!$students): ?>
    <?php render_empty_state('No enrolled students to grade.', 'Add students from People in Teach.', [
        ['label' => 'Add people', 'href' => url('admin/people.php?course_id=' . $courseId), 'primary' => true],
    ]); ?>
  <?php else: ?>
    <div class="gradebook-wrap">
      <table class="data-table gradebook-table">
        <thead>
          <tr>
            <th class="gradebook-sticky">Student</th>
            <?php foreach ($columns as $col): ?>
              <th title="<?= e($col['title']) ?>">
                <span class="gradebook-col-kind"><?= e(ucfirst($col['kind'])) ?></span>
                <?= e(mb_strimwidth($col['title'], 0, 24, '…')) ?>
                <?php if ($showUnpublished && empty($col['live'])): ?>
                  <span class="unpublished-label">draft</span>
                <?php endif; ?>
              </th>
            <?php endforeach; ?>
            <?php if ($showWeighted): ?><th>Weighted</th><?php endif; ?>
            <th>Total</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $st):
            $uid = (int) $st['id'];
            $totalEarned = 0.0;
            $totalPossible = 0.0;
            $gradeRows = [];
          ?>
            <tr>
              <td class="gradebook-sticky"><strong><?= e($st['full_name']) ?></strong></td>
              <?php foreach ($columns as $col):
                $totalPossible += (float) $col['points'];
                $cell = '—';
                $href = null;
                $subHref = null;
                $cls = '';
                $cellScore = null;
                if ($col['kind'] === 'assignment') {
                    $sub = $subMap[$uid][$col['id']] ?? null;
                    if ($sub) {
                        if ($sub['grade'] !== null) {
                            $cellScore = (float) $sub['grade'];
                            $cell = number_format($cellScore, 1);
                            $totalEarned += $cellScore;
                        } else {
                            $cell = 'Submitted';
                            $cls = 'gradebook-pending';
                        }
                        if ($sub['is_late']) {
                            $cls .= ' gradebook-late';
                        }
                    }
                    $href = url("assignment.php?course_id={$courseId}&id={$col['id']}");
                } elseif ($col['kind'] === 'quiz') {
                    $att = $quizMap[$uid][$col['id']] ?? null;
                    $attempts = $quizAttemptCounts[$uid][$col['id']] ?? 0;
                    if ($att) {
                        if ($att['needs_grading']) {
                            $cell = 'Needs grading';
                            $cls = 'gradebook-needs-grade';
                            $href = url("quiz-grade.php?course_id={$courseId}&attempt_id={$att['id']}");
                        } else {
                            $cellScore = (float) $att['score'];
                            $cell = number_format($cellScore, 1);
                            $totalEarned += $cellScore;
                            $href = url("quiz-attempts.php?course_id={$courseId}&quiz_id={$col['id']}&student_id={$uid}");
                        }
                        if ($attempts > 1) {
                            $subHref = url("quiz-attempts.php?course_id={$courseId}&quiz_id={$col['id']}&student_id={$uid}");
                        }
                    } else {
                        $href = url("quiz.php?course_id={$courseId}&id={$col['id']}");
                    }
                } else {
                    $g = $discMap[$uid][$col['id']] ?? null;
                    if ($g && $g['points'] !== null) {
                        $cellScore = (float) $g['points'];
                        $cell = number_format($cellScore, 1);
                        $totalEarned += $cellScore;
                    }
                    $href = url("discussion.php?course_id={$courseId}&id={$col['id']}");
                }
                $gradeRows[] = [
                    'points' => (float) $col['points'],
                    'group_id' => $col['group_id'] ?? null,
                    'score' => $cellScore,
                ];
              ?>
                <td class="<?= trim($cls) ?>">
                  <?php if ($href): ?>
                    <a href="<?= e($href) ?>" class="gradebook-cell-link"><?= e($cell) ?></a>
                  <?php else: ?>
                    <?= e($cell) ?>
                  <?php endif; ?>
                  <?php if ($subHref): ?>
                    <a href="<?= e($subHref) ?>" class="gradebook-attempts-link" title="View all attempts"><?= (int)$attempts ?>×</a>
                  <?php endif; ?>
                </td>
              <?php endforeach; ?>
              <?php if ($showWeighted):
                $weighted = weighted_grade_summary($groups, $gradeRows);
              ?>
                <td class="gradebook-weighted"><?= $weighted ? number_format($weighted['weighted_percent'], 1) . '%' : '—' ?></td>
              <?php endif; ?>
              <td class="gradebook-total"><?= number_format($totalEarned, 1) ?> / <?= number_format($totalPossible, 1) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php
render_course_shell_end();
render_app_shell_end();