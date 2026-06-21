<?php
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/ref_publish_ui.php';
require_once __DIR__ . '/../includes/quiz_admin.php';
require_once __DIR__ . '/../includes/quiz_types.php';
$user = require_teach_access($pdo);

$courses = array_map(
    fn($c) => ['id' => $c['id'], 'code' => $c['code']],
    teach_admin_courses($pdo, $user)
);
$courseId = (int) ($_GET['course_id'] ?? ($courses[0]['id'] ?? 0));
$quizId = (int) ($_GET['quiz_id'] ?? 0);
$editQuizId = (int) ($_GET['edit_quiz'] ?? 0);
$editQuestionId = (int) ($_GET['edit_question'] ?? 0);
$showNew = isset($_GET['new']);

if ($courseId) {
    require_course_content_editor($pdo, $courseId, $user);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $courseId = (int) ($_POST['course_id'] ?? 0);
    require_course_content_editor($pdo, $courseId, $user);
    $redirectBase = '/admin/quizzes.php?course_id=' . $courseId;
    if (isset($_POST['quiz_id']) && (int) $_POST['quiz_id']) {
        $redirectBase .= '&quiz_id=' . (int) $_POST['quiz_id'];
    }
    if (isset($_POST['publish_ref']) || isset($_POST['go_live_ref']) || isset($_POST['unpublish_ref']) || isset($_POST['add_ref_to_module'])) {
        handle_ref_publish_post($pdo, $courseId, $redirectBase, (int) $user['id']);
    }

    if (isset($_POST['move_question'])) {
        $qid = (int) $_POST['quiz_id'];
        admin_swap_quiz_question($pdo, (int) $_POST['question_id'], $qid, $_POST['direction'] ?? 'up');
        flash('success', 'Question order updated.');
        redirect($redirectBase);
    }

    if (isset($_POST['update_question'])) {
        $qid = (int) $_POST['quiz_id'];
        $questionId = (int) $_POST['question_id'];
        try {
            admin_persist_quiz_question($pdo, $qid, $_POST, $questionId);
            flash('success', 'Question updated.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/quizzes.php?course_id=' . $courseId . '&quiz_id=' . $qid);
    }

    if (isset($_POST['delete_quiz'])) {
        $qid = (int) $_POST['quiz_id'];
        $pdo->prepare('DELETE FROM quizzes WHERE id = ? AND course_id = ?')->execute([$qid, $courseId]);
        flash('success', 'Quiz deleted.');
        redirect('/admin/quizzes.php?course_id=' . $courseId);
    }

    if (isset($_POST['delete_question'])) {
        $qid = (int) $_POST['quiz_id'];
        $pdo->prepare('DELETE FROM quiz_questions WHERE id = ? AND quiz_id = ?')->execute([(int) $_POST['question_id'], $qid]);
        flash('success', 'Question deleted.');
        redirect('/admin/quizzes.php?course_id=' . $courseId . '&quiz_id=' . $qid);
    }

    if (isset($_POST['update_quiz'])) {
        $qid = (int) $_POST['quiz_id'];
        $groupId = ($_POST['group_id'] ?? '') !== '' ? (int) $_POST['group_id'] : null;
        $descFormat = content_format_value($_POST['description_format'] ?? 'text');
        $pdo->prepare(
            'UPDATE quizzes SET title = ?, description = ?, description_format = ?, points = ?, due_at = ?, max_attempts = ?, lock_after_due = ?, group_id = ? WHERE id = ? AND course_id = ?'
        )->execute([
            trim($_POST['title'] ?? ''),
            trim($_POST['description'] ?? ''),
            $descFormat,
            (float) ($_POST['points'] ?? 25),
            ($_POST['due_at'] ?? '') ?: null,
            (int) ($_POST['max_attempts'] ?? 1),
            isset($_POST['lock_after_due']) ? 1 : 0,
            $groupId,
            $qid,
            $courseId,
        ]);
        flash('success', 'Quiz updated.');
        redirect('/admin/quizzes.php?course_id=' . $courseId . '&quiz_id=' . $qid);
    }

    if (isset($_POST['create_quiz'])) {
        $groupId = ($_POST['group_id'] ?? '') !== '' ? (int) $_POST['group_id'] : null;
        $descFormat = content_format_value($_POST['description_format'] ?? 'text');
        $pdo->prepare('INSERT INTO quizzes (course_id, title, description, description_format, points, due_at, max_attempts, lock_after_due, group_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $courseId,
                trim($_POST['title']),
                trim($_POST['description']),
                $descFormat,
                (float) ($_POST['points'] ?? 25),
                ($_POST['due_at'] ?? '') ?: null,
                (int) ($_POST['max_attempts'] ?? 1),
                isset($_POST['lock_after_due']) ? 1 : 0,
                $groupId,
            ]);
        flash('success', 'Quiz created. Add questions below, then link it in Modules.');
        redirect('/admin/quizzes.php?course_id=' . $courseId . '&quiz_id=' . $pdo->lastInsertId());
    }

    if (isset($_POST['add_question'])) {
        $quizId = (int) $_POST['quiz_id'];
        try {
            admin_persist_quiz_question($pdo, $quizId, $_POST);
            flash('success', 'Question added.');
        } catch (Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/quizzes.php?course_id=' . $courseId . '&quiz_id=' . $quizId);
    }
}

$quizzes = [];
$questionCounts = [];
if ($courseId) {
    $s = $pdo->prepare(
        'SELECT q.*, (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.quiz_id = q.id) AS question_count
         FROM quizzes q WHERE q.course_id = ? ORDER BY q.title'
    );
    $s->execute([$courseId]);
    $quizzes = $s->fetchAll();
}

$editingQuiz = null;
if ($editQuizId && $courseId) {
    foreach ($quizzes as $qz) {
        if ((int) $qz['id'] === $editQuizId) {
            $editingQuiz = $qz;
            $quizId = $editQuizId;
            break;
        }
    }
}

$questions = [];
$activeQuiz = null;
if ($quizId) {
    $q = $pdo->prepare('SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY position');
    $q->execute([$quizId]);
    $questions = $q->fetchAll();
    foreach ($quizzes as $qz) {
        if ((int) $qz['id'] === $quizId) {
            $activeQuiz = $qz;
            break;
        }
    }
}

$groups = $courseId ? assignment_groups_for_course($pdo, $courseId) : [];
$quizFormFields = static function (?array $quiz, bool $isEdit) use ($groups) {
    $dueVal = ($quiz && $quiz['due_at']) ? date('Y-m-d\TH:i', strtotime($quiz['due_at'])) : '';
    ?>
    <div class="form-group"><label>Title</label><input name="title" required value="<?= e($quiz['title'] ?? '') ?>"></div>
    <div class="form-group">
      <label>Description</label>
      <textarea name="description" rows="4" data-rich-editor><?= e($quiz['description'] ?? '') ?></textarea>
      <input type="hidden" name="description_format" value="<?= e(($quiz['description_format'] ?? '') === 'html' ? 'html' : 'text') ?>" data-rich-format>
    </div>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;">
      <div class="form-group"><label>Points</label><input name="points" type="number" step="0.1" value="<?= e((string)($quiz['points'] ?? 25)) ?>"></div>
      <div class="form-group"><label>Due date & time</label><input name="due_at" type="datetime-local" value="<?= e($dueVal) ?>"></div>
      <div class="form-group"><label>Max attempts (0 = unlimited)</label><input name="max_attempts" type="number" min="0" value="<?= (int)($quiz['max_attempts'] ?? 1) ?>"></div>
    </div>
    <?php if ($groups): ?>
      <div class="form-group">
        <label>Assignment group</label>
        <select name="group_id">
          <option value="">— None —</option>
          <?php foreach ($groups as $g): ?>
            <option value="<?= (int)$g['id'] ?>" <?= (int)($quiz['group_id'] ?? 0) === (int)$g['id'] ? 'selected' : '' ?>><?= e($g['name']) ?> (<?= e((string)$g['weight']) ?>%)</option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <label style="display:flex;align-items:center;gap:8px;font-size:14px;margin:12px 0;">
      <input type="checkbox" name="lock_after_due" value="1" <?= ($quiz['lock_after_due'] ?? 0) ? 'checked' : '' ?>>
      Lock quiz after due date
    </label>
    <?php
};

render_head('Quizzes');
render_app_shell_start($user, 'admin', '/admin/index.php');
?>
<?php render_page_header('Quizzes', 'Teach'); ?>
<div class="page-body">
  <form method="get" style="margin-bottom:20px;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
    <div>
      <label style="font-size:12px;font-weight:600;color:#71717a;">Course</label>
      <select name="course_id" onchange="this.form.submit()" style="max-width:320px;">
        <?php foreach ($courses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $courseId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($courseId): ?>
      <?php render_admin_preview_link($courseId); ?>
    <?php endif; ?>
  </form>

  <p style="font-size:14px;color:#71717a;margin:0 0 20px;">
    Create and edit quizzes here. Use <strong>Go live</strong> or <strong>Add to module</strong> on each quiz so students can see it.
  </p>

  <h2 class="section-title">Existing quizzes (<?= count($quizzes) ?>)</h2>
  <?php if ($quizzes): ?>
    <div class="panel" style="margin-bottom:28px;">
      <?php foreach ($quizzes as $qz):
        $isActive = $quizId === (int) $qz['id'];
      ?>
        <div class="panel-row admin-quiz-row-wrap <?= $isActive ? 'admin-quiz-row-active' : '' ?>">
          <div class="admin-quiz-row">
          <div class="admin-quiz-row-main">
            <strong><?= e($qz['title']) ?></strong>
            <span class="admin-quiz-meta">
              <?= (int)$qz['question_count'] ?> questions · <?= e((string)$qz['points']) ?> pts
              · Due <?= e(format_datetime($qz['due_at'])) ?>
              <?php if ($qz['lock_after_due']): ?> · locks after due<?php endif; ?>
            </span>
          </div>
          <div class="admin-quiz-row-actions">
            <a class="btn btn-sm <?= $isActive && !$editQuizId ? '' : 'btn-outline' ?>" href="<?= url('admin/quizzes.php?course_id=' . $courseId . '&quiz_id=' . $qz['id']) ?>">Questions</a>
            <a class="btn btn-sm <?= $editQuizId === (int)$qz['id'] ? '' : 'btn-outline' ?>" href="<?= url('admin/quizzes.php?course_id=' . $courseId . '&edit_quiz=' . $qz['id']) ?>">Edit</a>
            <form method="post" onsubmit="return confirm('Delete this quiz and all attempts?');">
              <input type="hidden" name="course_id" value="<?= $courseId ?>">
              <input type="hidden" name="delete_quiz" value="1">
              <input type="hidden" name="quiz_id" value="<?= (int)$qz['id'] ?>">
              <button class="btn btn-sm btn-outline" type="submit">Delete</button>
            </form>
          </div>
          </div>
          <?php render_ref_publish_bar($pdo, $courseId, 'quiz', (int) $qz['id'], $qz['title'], ''); ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <?php render_empty_state('No quizzes in this course yet.', 'Create a quiz below and add questions.', [
        ['label' => 'New quiz form', 'href' => '#quiz-form', 'primary' => true],
    ]); ?>
  <?php endif; ?>

  <?php if ($editingQuiz): ?>
    <div class="content-box" style="margin-bottom:28px;background:#fafafa;border:2px solid var(--brand-accent);">
      <h3 style="margin:0 0 12px;">Edit quiz: <?= e($editingQuiz['title']) ?></h3>
      <form method="post">
        <input type="hidden" name="course_id" value="<?= $courseId ?>">
        <input type="hidden" name="update_quiz" value="1">
        <input type="hidden" name="quiz_id" value="<?= (int)$editingQuiz['id'] ?>">
        <?php $quizFormFields($editingQuiz, true); ?>
        <button class="btn" type="submit">Save quiz</button>
        <a class="btn btn-outline" href="<?= url('admin/quizzes.php?course_id=' . $courseId . '&quiz_id=' . $editingQuiz['id']) ?>" style="margin-left:8px;">Cancel</a>
      </form>
    </div>
  <?php elseif ($showNew || !$quizzes): ?>
    <div class="content-box" id="quiz-form" style="margin-bottom:28px;background:#fafafa;">
      <h3 style="margin:0 0 12px;">New quiz</h3>
      <form method="post">
        <input type="hidden" name="course_id" value="<?= $courseId ?>">
        <input type="hidden" name="create_quiz" value="1">
        <?php $quizFormFields(null, false); ?>
        <button class="btn" type="submit">Create quiz</button>
      </form>
    </div>
  <?php else: ?>
    <p style="margin-bottom:28px;"><a class="btn btn-outline btn-sm" href="<?= url('admin/quizzes.php?course_id=' . $courseId . '&new=1') ?>">+ New quiz</a></p>
  <?php endif; ?>

  <?php if ($quizId && $activeQuiz && !$editQuizId): ?>
    <h2 class="section-title">Questions — <?= e($activeQuiz['title']) ?></h2>
    <div class="content-box" style="margin-bottom:24px;">
      <h4 style="margin:0 0 12px;">Add question</h4>
      <form method="post" id="add-question-form">
        <input type="hidden" name="add_question" value="1">
        <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
        <input type="hidden" name="course_id" value="<?= $courseId ?>">
        <?php render_quiz_question_form_fields(null, 'question-type'); ?>
        <button class="btn" type="submit">Add question</button>
      </form>
    </div>
    <?php
      $editingQuestion = null;
      if ($editQuestionId) {
          foreach ($questions as $qn) {
              if ((int) $qn['id'] === $editQuestionId) {
                  $editingQuestion = $qn;
                  break;
              }
          }
      }
    ?>
    <?php if ($editingQuestion): ?>
      <div class="content-box" style="margin-bottom:24px;border:2px solid var(--brand-accent);">
        <h4 style="margin:0 0 12px;">Edit question</h4>
        <form method="post">
          <input type="hidden" name="update_question" value="1">
          <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
          <input type="hidden" name="course_id" value="<?= $courseId ?>">
          <input type="hidden" name="question_id" value="<?= (int)$editingQuestion['id'] ?>">
          <?php render_quiz_question_form_fields($editingQuestion, 'edit-question-type'); ?>
          <button class="btn" type="submit">Save question</button>
          <a class="btn btn-outline" href="<?= url('admin/quizzes.php?course_id=' . $courseId . '&quiz_id=' . $quizId) ?>">Cancel</a>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($questions): ?>
      <div class="panel">
        <?php foreach ($questions as $qi => $qn):
          $isFirstQ = $qi === 0;
          $isLastQ = $qi === count($questions) - 1;
        ?>
          <div class="panel-row question-admin-row">
            <div class="question-admin-main">
              <span style="font-size:11px;color:#71717a;text-transform:uppercase;"><?= e($qn['question_type']) ?></span>
              <div><?= e($qn['question']) ?></div>
            </div>
            <div class="question-admin-actions">
              <form method="post" style="display:inline;">
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <input type="hidden" name="move_question" value="1">
                <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
                <input type="hidden" name="question_id" value="<?= (int)$qn['id'] ?>">
                <input type="hidden" name="direction" value="up">
                <button type="submit" class="btn btn-sm btn-outline" <?= $isFirstQ ? 'disabled' : '' ?>>↑</button>
              </form>
              <form method="post" style="display:inline;">
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <input type="hidden" name="move_question" value="1">
                <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
                <input type="hidden" name="question_id" value="<?= (int)$qn['id'] ?>">
                <input type="hidden" name="direction" value="down">
                <button type="submit" class="btn btn-sm btn-outline" <?= $isLastQ ? 'disabled' : '' ?>>↓</button>
              </form>
              <a class="btn btn-sm" href="<?= url('admin/quizzes.php?course_id=' . $courseId . '&quiz_id=' . $quizId . '&edit_question=' . $qn['id']) ?>">Edit</a>
              <form method="post" onsubmit="return confirm('Delete this question?');" style="display:inline;">
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <input type="hidden" name="delete_question" value="1">
                <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
                <input type="hidden" name="question_id" value="<?= (int)$qn['id'] ?>">
                <button class="btn btn-sm btn-outline" type="submit">Delete</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p style="color:#71717a;font-size:14px;">No questions yet — add one above.</p>
    <?php endif; ?>
    <script src="<?= url('assets/js/quiz-admin.js') ?>"></script>
  <?php endif; ?>
</div>
<?php
require_once __DIR__ . '/../includes/rich_editor.php';
render_rich_editor_assets();
render_app_shell_end();
?>