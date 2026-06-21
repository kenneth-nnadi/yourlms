<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/notifications.php';
$user = require_login();

$courseId = (int) ($_GET['course_id'] ?? 0);
$assignmentId = (int) ($_GET['id'] ?? 0);
$course = require_course_access($pdo, $courseId, $user);

$stmt = $pdo->prepare('SELECT * FROM assignments WHERE id = ? AND course_id = ?');
$stmt->execute([$assignmentId, $courseId]);
$assignment = $stmt->fetch();
if (!$assignment) {
    http_response_code(404);
    die('Assignment not found.');
}
require_published_ref_access($pdo, $courseId, $user, 'assignment', $assignmentId);

$canGrade = user_can_grade($pdo, $courseId, $user);
$canParticipate = user_can_participate($pdo, $courseId, $user);
$allowsSubmit = assignment_allows_submission($assignment);
$cfg = config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($canGrade && isset($_POST['grade_submission'])) {
        $subId = (int) $_POST['submission_id'];
        $grade = $_POST['grade'] !== '' ? (float) $_POST['grade'] : null;
        $feedback = trim($_POST['feedback'] ?? '');
        $rubricScores = null;
        if (!empty($_POST['rubric_score']) && is_array($_POST['rubric_score'])) {
            $rubricScores = [];
            foreach ($_POST['rubric_score'] as $cid => $val) {
                if ($val !== '') {
                    $rubricScores[(int) $cid] = (float) $val;
                }
            }
            if ($rubricScores && $grade === null) {
                $grade = array_sum($rubricScores);
            }
        }
        $pdo->prepare('UPDATE submissions SET grade = ?, feedback = ?, rubric_scores = ?, graded_at = ' . db_now_sql() . ' WHERE id = ? AND assignment_id = ?')
            ->execute([$grade, $feedback, $rubricScores ? json_encode($rubricScores) : null, $subId, $assignmentId]);
        $subRow = $pdo->prepare('SELECT user_id FROM submissions WHERE id = ?');
        $subRow->execute([$subId]);
        $studentId = (int) $subRow->fetchColumn();
        if ($studentId && $grade !== null) {
            notify_user(
                $pdo,
                $studentId,
                $courseId,
                'grade',
                'Grade posted: ' . $assignment['title'],
                'You received ' . number_format($grade, 1) . ' / ' . $assignment['points'] . ' points.',
                "assignment.php?course_id={$courseId}&id={$assignmentId}",
                true
            );
        }
        flash('success', 'Grade saved.');
        redirect("assignment.php?course_id={$courseId}&id={$assignmentId}");
    }

    if ($canGrade && isset($_POST['add_comment'])) {
        $subId = (int) $_POST['submission_id'];
        $content = trim($_POST['comment'] ?? '');
        if ($content !== '') {
            $pdo->prepare('INSERT INTO submission_comments (submission_id, user_id, content) VALUES (?, ?, ?)')
                ->execute([$subId, $user['id'], $content]);
            $subRow = $pdo->prepare('SELECT s.user_id FROM submissions s WHERE s.id = ? AND s.assignment_id = ?');
            $subRow->execute([$subId, $assignmentId]);
            $studentId = (int) $subRow->fetchColumn();
            if ($studentId && $studentId !== (int) $user['id']) {
                notify_user(
                    $pdo,
                    $studentId,
                    $courseId,
                    'comment',
                    'New comment on ' . $assignment['title'],
                    $user['full_name'] . ': ' . mb_substr($content, 0, 120),
                    "assignment.php?course_id={$courseId}&id={$assignmentId}",
                    true
                );
            }
            flash('success', 'Comment added.');
        }
        redirect("assignment.php?course_id={$courseId}&id={$assignmentId}");
    }

    if ($canParticipate && isset($_POST['submit_work'])) {
        if (!$allowsSubmit) {
            flash('error', 'Submissions are closed — the due date has passed.');
            redirect("assignment.php?course_id={$courseId}&id={$assignmentId}");
        }
        $content = trim($_POST['content'] ?? '');
        $filePath = null;
        $fileName = null;

        if (!empty($_FILES['submission_file']['tmp_name'])) {
            $dest = $cfg['upload_dir'] . '/submissions/course_' . $courseId . '/assignment_' . $assignmentId;
            $upload = handle_upload($_FILES['submission_file'], $dest, $cfg['upload_max_mb']);
            if (!$upload['ok']) {
                flash('error', $upload['error']);
                redirect("assignment.php?course_id={$courseId}&id={$assignmentId}");
            }
            $filePath = 'submissions/course_' . $courseId . '/assignment_' . $assignmentId . '/' . $upload['path'];
            $fileName = $upload['name'];
        }

        if ($content === '' && !$filePath) {
            flash('error', 'Add text or attach a file before submitting.');
            redirect("assignment.php?course_id={$courseId}&id={$assignmentId}");
        }

        $isLate = submission_would_be_late($assignment) ? 1 : 0;
        $pdo->prepare(sql_submission_upsert())->execute([$assignmentId, $user['id'], $content ?: null, $filePath, $fileName, $isLate]);
        notify_course_staff(
            $pdo,
            $courseId,
            'submission',
            'New submission: ' . $assignment['title'],
            $user['full_name'] . ' submitted work' . ($isLate ? ' (late)' : '') . '.',
            "assignment.php?course_id={$courseId}&id={$assignmentId}",
            (int) $user['id']
        );
        flash('success', $isLate ? 'Submitted (marked late).' : 'Submitted.');
        redirect("assignment.php?course_id={$courseId}&id={$assignmentId}");
    }
}

function submission_comments_for(PDO $pdo, array $submissionIds): array
{
    if (!$submissionIds) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($submissionIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT c.*, u.full_name FROM submission_comments c
         JOIN users u ON u.id = c.user_id
         WHERE c.submission_id IN ({$ph}) ORDER BY c.created_at ASC"
    );
    $stmt->execute($submissionIds);
    $bySub = [];
    foreach ($stmt->fetchAll() as $row) {
        $bySub[$row['submission_id']][] = $row;
    }
    return $bySub;
}

$submission = null;
$allSubmissions = [];
$commentMap = [];
if (!$canGrade) {
    $s = $pdo->prepare('SELECT * FROM submissions WHERE assignment_id = ? AND user_id = ?');
    $s->execute([$assignmentId, $user['id']]);
    $submission = $s->fetch() ?: null;
    $commentMap = $submission ? submission_comments_for($pdo, [(int) $submission['id']]) : [];
} else {
    $allSubmissions = $pdo->prepare(
        'SELECT s.*, u.full_name FROM submissions s
         JOIN users u ON u.id = s.user_id
         WHERE s.assignment_id = ? ORDER BY s.submitted_at DESC'
    );
    $allSubmissions->execute([$assignmentId]);
    $allSubmissions = $allSubmissions->fetchAll();
    $commentMap = submission_comments_for($pdo, array_column($allSubmissions, 'id'));
}

$pastDue = assignment_is_past_due($assignment);

$rubric = null;
$rubricCriteria = [];
if (!empty($assignment['rubric_id'])) {
    $rs = $pdo->prepare('SELECT * FROM rubrics WHERE id = ?');
    $rs->execute([(int) $assignment['rubric_id']]);
    $rubric = $rs->fetch() ?: null;
    if ($rubric) {
        $rc = $pdo->prepare('SELECT * FROM rubric_criteria WHERE rubric_id = ? ORDER BY position');
        $rc->execute([(int) $rubric['id']]);
        $rubricCriteria = $rc->fetchAll();
    }
}

$commentBank = [];
if ($canGrade) {
    $cb = $pdo->prepare('SELECT * FROM comment_bank WHERE user_id = ? AND (course_id IS NULL OR course_id = ?) ORDER BY title');
    $cb->execute([$user['id'], $courseId]);
    $commentBank = $cb->fetchAll();
}

render_head($assignment['title']);
render_app_shell_start($user, 'courses', "assignments.php?course_id={$courseId}");
render_course_shell_start($course, 'assignments', $courseId);
render_course_header('Assignment', course_header_actions($pdo, $courseId, $user));
?>
<div class="course-page">
  <h1 style="font-size:1.75rem;font-weight:700;margin:0;"><?= e($assignment['title']) ?></h1>
  <p style="font-size:14px;color:#71717a;margin:8px 0 0;">
    Due <?= e(format_datetime($assignment['due_at'], 'F j, Y \a\t g:ia')) ?> · <?= e((string)$assignment['points']) ?> pts
    <?php if ($pastDue): ?><span class="late-badge">Past due</span><?php endif; ?>
    <?php if ((bool)($assignment['lock_after_due'] ?? 0)): ?><span class="late-badge">Locks after due</span><?php endif; ?>
  </p>
  <div class="content-box user-html-content" style="margin-top:24px;">
    <?php if (($assignment['description_format'] ?? 'text') === 'html'): ?>
      <?= render_user_html($assignment['description'] ?? '') ?>
    <?php else: ?>
      <?= nl2br(e($assignment['description'] ?? '')) ?>
    <?php endif; ?>
  </div>
  <?php if (!empty($assignment['attachment_path'])): ?>
    <div class="assignment-attachment" style="margin-top:16px;">
      <a class="btn btn-outline btn-sm" href="<?= url('download.php?assignment_id=' . (int)$assignmentId) ?>">
        📎 <?= e($assignment['attachment_name'] ?? 'Assignment file') ?>
      </a>
    </div>
  <?php endif; ?>

  <?php if (!$canGrade): ?>
    <section style="margin-top:32px;">
      <h2 style="font-size:1.1rem;font-weight:600;margin-bottom:12px;">Your Submission</h2>
      <?php if ($submission && $submission['grade'] !== null): ?>
        <div class="grade-banner">
          <strong>Grade: <?= e((string)$submission['grade']) ?> / <?= e((string)$assignment['points']) ?></strong>
          <?php if ($submission['feedback']): ?>
            <div style="margin-top:4px;"><?= e($submission['feedback']) ?></div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <?php if ($submission && $submission['is_late']): ?>
        <p class="late-notice">This submission was marked late.</p>
      <?php endif; ?>
      <?php if ($canParticipate): ?>
        <?php if (!$allowsSubmit): ?>
          <p style="font-size:14px;color:#71717a;">Submissions are closed. The due date has passed and late submissions are not allowed.</p>
        <?php else: ?>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="submit_work" value="1">
            <textarea name="content" rows="8" placeholder="Type your submission…"><?= e($submission['content'] ?? '') ?></textarea>
            <div class="form-group" style="margin-top:12px;">
              <label style="font-size:12px;font-weight:600;">Attach file (optional)</label>
              <input type="file" name="submission_file">
              <?php if ($submission && $submission['file_path']): ?>
                <p style="font-size:12px;color:#71717a;margin-top:4px;">Current file: <a href="<?= url('download.php?submission_id=' . (int)$submission['id']) ?>"><?= e($submission['file_name'] ?? 'Download') ?></a></p>
              <?php endif; ?>
            </div>
            <?php if ($pastDue): ?>
              <p class="late-notice">Submitting now will be marked as late.</p>
            <?php endif; ?>
            <button class="btn" type="submit" style="margin-top:12px;"><?= $submission ? 'Resubmit' : 'Submit' ?></button>
          </form>
        <?php endif; ?>
      <?php else: ?>
        <p style="font-size:14px;color:#71717a;">Guests can view assignments but cannot submit work.</p>
      <?php endif; ?>
      <?php if ($submission): ?>
        <p style="font-size:12px;color:#71717a;margin-top:8px;">Last submitted <?= e(format_datetime($submission['submitted_at'])) ?></p>
        <?php foreach ($commentMap[$submission['id']] ?? [] as $c): ?>
          <div class="submission-comment">
            <strong><?= e($c['full_name']) ?></strong>
            <span style="font-size:11px;color:#71717a;"> · <?= e(format_datetime($c['created_at'])) ?></span>
            <p style="margin:4px 0 0;font-size:14px;"><?= e($c['content']) ?></p>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </section>
  <?php else: ?>
    <section style="margin-top:32px;">
      <h2 style="font-size:1.1rem;font-weight:600;margin-bottom:12px;">Submissions (<?= count($allSubmissions) ?>)</h2>
      <div class="panel">
        <?php foreach ($allSubmissions as $s): ?>
          <div class="panel-row">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <strong><?= e($s['full_name']) ?></strong>
              <span style="font-size:12px;color:#71717a;">
                <?= e(format_datetime($s['submitted_at'])) ?>
                <?php if ($s['is_late']): ?><span class="late-badge">Late</span><?php endif; ?>
              </span>
            </div>
            <?php if ($s['content']): ?>
              <div class="content-box" style="margin-top:8px;"><?= e($s['content']) ?></div>
            <?php endif; ?>
            <?php if ($s['file_path']): ?>
              <p style="margin-top:8px;font-size:14px;"><a href="<?= url('download.php?submission_id=' . (int)$s['id']) ?>">📎 <?= e($s['file_name'] ?? 'Attached file') ?></a></p>
            <?php endif; ?>
            <?php
              $savedRubric = $s['rubric_scores'] ? json_decode($s['rubric_scores'], true) : [];
            ?>
            <form method="post" style="margin-top:12px;" class="grade-form">
              <input type="hidden" name="grade_submission" value="1">
              <input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>">
              <?php if ($rubricCriteria): ?>
                <fieldset style="border:1px solid var(--brand-border);border-radius:6px;padding:12px;margin-bottom:12px;">
                  <legend style="font-size:12px;font-weight:600;padding:0 4px;">Rubric: <?= e($rubric['title']) ?></legend>
                  <?php foreach ($rubricCriteria as $c):
                    $cid = (int) $c['id'];
                  ?>
                    <div style="display:flex;gap:8px;align-items:center;margin-bottom:6px;font-size:14px;">
                      <span style="flex:1;"><?= e($c['description']) ?></span>
                      <input type="number" step="0.1" name="rubric_score[<?= $cid ?>]" value="<?= e((string)($savedRubric[$cid] ?? $savedRubric[(string)$cid] ?? '')) ?>" style="width:72px;" max="<?= e((string)$c['points']) ?>" aria-label="Score for <?= e($c['description']) ?>">
                      <span style="font-size:12px;color:#71717a;">/ <?= e((string)$c['points']) ?></span>
                    </div>
                  <?php endforeach; ?>
                </fieldset>
              <?php endif; ?>
              <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                <div>
                  <label style="font-size:12px;font-weight:600;">Grade</label>
                  <div style="display:flex;align-items:center;gap:4px;">
                    <input type="number" step="0.1" name="grade" value="<?= e($s['grade'] !== null ? (string)$s['grade'] : '') ?>" style="width:80px;">
                    <span style="font-size:12px;color:#71717a;">/ <?= e((string)$assignment['points']) ?></span>
                  </div>
                </div>
                <div style="flex:1;min-width:200px;">
                  <label style="font-size:12px;font-weight:600;">Feedback</label>
                  <input type="text" name="feedback" value="<?= e($s['feedback'] ?? '') ?>" class="feedback-field">
                </div>
                <?php if ($commentBank): ?>
                  <div>
                    <label style="font-size:12px;font-weight:600;">Comment bank</label>
                    <select class="comment-bank-pick" aria-label="Insert comment from bank">
                      <option value="">Insert comment…</option>
                      <?php foreach ($commentBank as $cm): ?>
                        <option value="<?= e($cm['body']) ?>"><?= e($cm['title']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                <?php endif; ?>
                <button class="btn btn-sm" type="submit">Save grade</button>
              </div>
            </form>
            <?php foreach ($commentMap[$s['id']] ?? [] as $c): ?>
              <div class="submission-comment">
                <strong><?= e($c['full_name']) ?></strong>
                <span style="font-size:11px;color:#71717a;"> · <?= e(format_datetime($c['created_at'])) ?></span>
                <p style="margin:4px 0 0;font-size:14px;"><?= e($c['content']) ?></p>
              </div>
            <?php endforeach; ?>
            <form method="post" class="submission-comment-form">
              <input type="hidden" name="add_comment" value="1">
              <input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>">
              <textarea name="comment" rows="2" placeholder="Add a comment for the student…" required></textarea>
              <button class="btn btn-sm btn-outline" type="submit" style="margin-top:6px;">Add comment</button>
            </form>
          </div>
        <?php endforeach; ?>
        <?php if (!$allSubmissions): ?>
          <div class="panel-row" style="color:#71717a;">No submissions yet.</div>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>
</div>
<?php
render_course_shell_end();
render_app_shell_end();