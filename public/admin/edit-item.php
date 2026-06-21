<?php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/admin_helpers.php';
$user = require_login();
$courseId = (int) ($_GET['course_id'] ?? $_POST['course_id'] ?? 0);
require_course_content_editor($pdo, $courseId, $user);
$itemId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$item = admin_item_in_course($pdo, $itemId, $courseId);
if (!$item) {
    flash('error', 'Item not found.');
    redirect('/admin/modules.php?course_id=' . $courseId);
}

$course = course_or_404($pdo, $courseId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $type = $_POST['item_type'] ?? $item['item_type'];
    $content = $_POST['content'] ?? '';
    $format = ($_POST['content_format'] ?? 'text') === 'html' ? 'html' : 'text';
    $refId = $_POST['ref_id'] !== '' ? (int) $_POST['ref_id'] : null;

    if ($title === '') {
        flash('error', 'Title is required.');
        redirect("/admin/edit-item.php?course_id={$courseId}&id={$itemId}");
    }

    if (in_array($type, ['assignment', 'quiz', 'discussion', 'lti'], true) && !$refId) {
        flash('error', 'Please select a linked item.');
        redirect("/admin/edit-item.php?course_id={$courseId}&id={$itemId}");
    }

    if ($type === 'external' && trim($content) === '') {
        flash('error', 'URL is required for external links.');
        redirect("/admin/edit-item.php?course_id={$courseId}&id={$itemId}");
    }

    $pdo->prepare(
        'UPDATE module_items SET title = ?, item_type = ?, content = ?, content_format = ?, ref_id = ? WHERE id = ?'
    )->execute([
        $title,
        $type,
        in_array($type, ['page', 'external'], true) ? $content : null,
        $type === 'page' ? $format : 'text',
        in_array($type, ['assignment', 'quiz', 'discussion', 'lti'], true) ? $refId : null,
        $itemId,
    ]);

    flash('success', 'Item saved.');
    redirect('/admin/modules.php?course_id=' . $courseId);
}

$assignments = $pdo->prepare('SELECT id, title FROM assignments WHERE course_id = ? ORDER BY title');
$assignments->execute([$courseId]);
$assignments = $assignments->fetchAll();
$quizzes = $pdo->prepare('SELECT id, title FROM quizzes WHERE course_id = ? ORDER BY title');
$quizzes->execute([$courseId]);
$quizzes = $quizzes->fetchAll();
$discussions = $pdo->prepare('SELECT id, title FROM discussions WHERE course_id = ? ORDER BY title');
$discussions->execute([$courseId]);
$discussions = $discussions->fetchAll();
$ltiTools = $pdo->prepare('SELECT id, name AS title FROM external_tools WHERE course_id = ? ORDER BY name');
$ltiTools->execute([$courseId]);
$ltiTools = $ltiTools->fetchAll();

$isHtml = ($item['content_format'] ?? 'text') === 'html';

render_head('Edit item');
render_app_shell_start($user, 'admin', '/admin/modules.php?course_id=' . $courseId);
?>
<?php render_page_header('Edit module item', $course['code']); ?>
<div class="page-body" style="max-width:900px;">
  <form method="post">
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
    <input type="hidden" name="id" value="<?= $itemId ?>">

    <div class="form-group">
      <label>Title</label>
      <input name="title" required value="<?= e($item['title']) ?>">
    </div>

    <div class="form-group">
      <label>Type</label>
      <select name="item_type" id="item-type" onchange="toggleEditFields()">
        <option value="page" <?= $item['item_type'] === 'page' ? 'selected' : '' ?>>Page</option>
        <option value="external" <?= $item['item_type'] === 'external' ? 'selected' : '' ?>>External link</option>
        <option value="assignment" <?= $item['item_type'] === 'assignment' ? 'selected' : '' ?>>Assignment link</option>
        <option value="quiz" <?= $item['item_type'] === 'quiz' ? 'selected' : '' ?>>Quiz link</option>
        <option value="discussion" <?= $item['item_type'] === 'discussion' ? 'selected' : '' ?>>Discussion link</option>
        <option value="file" <?= $item['item_type'] === 'file' ? 'selected' : '' ?>>File (path managed on import)</option>
        <option value="lti" <?= $item['item_type'] === 'lti' ? 'selected' : '' ?>>External tool (LTI)</option>
      </select>
    </div>

    <div id="ref-field" class="form-group" style="display:none;">
      <label>Link to</label>
      <select name="ref_id">
        <option value="">— Select —</option>
        <optgroup label="Assignments">
          <?php foreach ($assignments as $a): ?>
            <option value="<?= (int)$a['id'] ?>" <?= (int)($item['ref_id'] ?? 0) === (int)$a['id'] ? 'selected' : '' ?>><?= e($a['title']) ?></option>
          <?php endforeach; ?>
        </optgroup>
        <optgroup label="Quizzes">
          <?php foreach ($quizzes as $q): ?>
            <option value="<?= (int)$q['id'] ?>" <?= (int)($item['ref_id'] ?? 0) === (int)$q['id'] ? 'selected' : '' ?>><?= e($q['title']) ?></option>
          <?php endforeach; ?>
        </optgroup>
        <optgroup label="Discussions">
          <?php foreach ($discussions as $d): ?>
            <option value="<?= (int)$d['id'] ?>" <?= (int)($item['ref_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>><?= e($d['title']) ?></option>
          <?php endforeach; ?>
        </optgroup>
        <optgroup label="External tools">
          <?php foreach ($ltiTools as $lt): ?>
            <option value="<?= (int)$lt['id'] ?>" <?= (int)($item['ref_id'] ?? 0) === (int)$lt['id'] ? 'selected' : '' ?>><?= e($lt['title']) ?></option>
          <?php endforeach; ?>
        </optgroup>
      </select>
    </div>

    <div id="content-wrap">
      <p id="format-field" style="font-size:12px;color:#71717a;margin:0 0 8px;">Rich text editor saves as HTML for pages.</p>
      <div class="form-group">
        <label id="content-label">Page content</label>
        <textarea name="content" id="content-field" rows="18" data-rich-editor><?= e($item['content'] ?? '') ?></textarea>
        <input type="hidden" name="content_format" id="content-format-field" value="<?= $isHtml ? 'html' : 'text' ?>" data-rich-format>
        <p id="content-hint" style="font-size:12px;color:#71717a;margin:8px 0 0;">For imported HTML pages, keep format as HTML. You can edit text, links, and headings directly.</p>
      </div>
    </div>

    <?php if ($item['item_type'] === 'file' && $item['file_path']): ?>
      <p style="font-size:13px;color:#71717a;">File: <code><?= e($item['file_path']) ?></code> — upload replacements via Course → Files.</p>
    <?php endif; ?>

    <div style="display:flex;gap:12px;margin-top:20px;">
      <button class="btn" type="submit">Save item</button>
      <a class="btn btn-outline" href="<?= url('admin/modules.php?course_id=' . $courseId) ?>">Cancel</a>
      <?php if ($item['item_type'] === 'page'): ?>
        <a class="btn btn-outline" href="<?= url('page.php?course_id=' . $courseId . '&item_id=' . $itemId) ?>" target="_blank">Preview</a>
      <?php endif; ?>
    </div>
  </form>
</div>
<script>
function toggleEditFields() {
  const type = document.getElementById('item-type').value;
  const needsRef = ['assignment','quiz','discussion','lti'].includes(type);
  const showContent = type === 'page' || type === 'external';
  document.getElementById('ref-field').style.display = needsRef ? 'block' : 'none';
  document.getElementById('content-wrap').style.display = showContent ? 'block' : 'none';
  var formatHint = document.getElementById('format-field');
  if (formatHint) formatHint.style.display = type === 'page' ? 'block' : 'none';
  document.getElementById('content-label').textContent = type === 'external' ? 'External URL' : 'Page content';
  const field = document.getElementById('content-field');
  if (type === 'external') { field.rows = 2; document.getElementById('content-hint').style.display = 'none'; }
  else { field.rows = 18; document.getElementById('content-hint').style.display = 'block'; }
}
toggleEditFields();
</script>
<?php
require_once dirname(__DIR__, 2) . '/includes/rich_editor.php';
render_rich_editor_assets();
render_app_shell_end();
?>