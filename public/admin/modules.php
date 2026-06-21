<?php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/admin_helpers.php';
$user = require_teach_access($pdo);

$courses = $pdo->query('SELECT id, code, name FROM courses ORDER BY code')->fetchAll();
$courseId = (int) ($_GET['course_id'] ?? ($courses[0]['id'] ?? 0));
if ($courseId) {
    require_course_content_editor($pdo, $courseId, $user);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $courseId = (int) ($_POST['course_id'] ?? 0);
    require_course_content_editor($pdo, $courseId, $user);

    if (isset($_POST['toggle_course_publish'])) {
        $course = course_or_404($pdo, $courseId);
        $next = course_is_published($course) ? 0 : 1;
        $pdo->prepare('UPDATE courses SET published = ? WHERE id = ?')->execute([$next, $courseId]);
        flash('success', $next ? 'Course published for students.' : 'Course unpublished.');
    }

    if (isset($_POST['publish_all_modules'])) {
        $pdo->prepare('UPDATE modules SET published = 1 WHERE course_id = ?')->execute([$courseId]);
        flash('success', 'All modules published.');
    }

    if (isset($_POST['unpublish_all_modules'])) {
        $pdo->prepare('UPDATE modules SET published = 0 WHERE course_id = ?')->execute([$courseId]);
        flash('success', 'All modules unpublished.');
    }

    if (isset($_POST['toggle_module_publish'])) {
        $moduleId = (int) $_POST['module_id'];
        if (admin_module_in_course($pdo, $moduleId, $courseId)) {
            $stmt = $pdo->prepare('SELECT published FROM modules WHERE id = ?');
            $stmt->execute([$moduleId]);
            $current = (int) $stmt->fetchColumn();
            $pdo->prepare('UPDATE modules SET published = ? WHERE id = ?')->execute([$current ? 0 : 1, $moduleId]);
            flash('success', $current ? 'Module unpublished.' : 'Module published.');
        }
    }

    if (isset($_POST['toggle_item_publish'])) {
        $itemId = (int) $_POST['item_id'];
        if (admin_item_in_course($pdo, $itemId, $courseId)) {
            $stmt = $pdo->prepare('SELECT published FROM module_items WHERE id = ?');
            $stmt->execute([$itemId]);
            $current = (int) $stmt->fetchColumn();
            $pdo->prepare('UPDATE module_items SET published = ? WHERE id = ?')->execute([$current ? 0 : 1, $itemId]);
            flash('success', $current ? 'Item unpublished.' : 'Item published.');
        }
    }

    $bulkSetPublished = static function (PDO $pdo, int $courseId, array $moduleIds, int $published): int {
        $count = 0;
        foreach ($moduleIds as $moduleId) {
            if (admin_module_in_course($pdo, (int) $moduleId, $courseId)) {
                $pdo->prepare('UPDATE modules SET published = ? WHERE id = ?')->execute([$published, (int) $moduleId]);
                $count++;
            }
        }
        return $count;
    };

    $bulkSetItemsPublished = static function (PDO $pdo, int $courseId, array $itemIds, int $published): int {
        $count = 0;
        foreach ($itemIds as $itemId) {
            if (admin_item_in_course($pdo, (int) $itemId, $courseId)) {
                $pdo->prepare('UPDATE module_items SET published = ? WHERE id = ?')->execute([$published, (int) $itemId]);
                $count++;
            }
        }
        return $count;
    };

    if (isset($_POST['bulk_publish_modules'])) {
        $ids = array_map('intval', $_POST['module_ids'] ?? []);
        $n = $bulkSetPublished($pdo, $courseId, $ids, 1);
        flash('success', $n ? "{$n} module(s) published." : 'Select at least one module.');
    }

    if (isset($_POST['bulk_unpublish_modules'])) {
        $ids = array_map('intval', $_POST['module_ids'] ?? []);
        $n = $bulkSetPublished($pdo, $courseId, $ids, 0);
        flash('success', $n ? "{$n} module(s) unpublished." : 'Select at least one module.');
    }

    if (isset($_POST['bulk_publish_items'])) {
        $ids = array_map('intval', $_POST['item_ids'] ?? []);
        $n = $bulkSetItemsPublished($pdo, $courseId, $ids, 1);
        flash('success', $n ? "{$n} item(s) published." : 'Select at least one item.');
    }

    if (isset($_POST['bulk_unpublish_items'])) {
        $ids = array_map('intval', $_POST['item_ids'] ?? []);
        $n = $bulkSetItemsPublished($pdo, $courseId, $ids, 0);
        flash('success', $n ? "{$n} item(s) unpublished." : 'Select at least one item.');
    }

    if (isset($_POST['save_order'])) {
        $moduleOrder = array_map('intval', $_POST['module_order'] ?? []);
        $moduleOrder = array_values(array_filter($moduleOrder));
        if ($moduleOrder) {
            admin_apply_module_order($pdo, $courseId, $moduleOrder);
        }
        $itemOrders = $_POST['item_order'] ?? [];
        if (is_array($itemOrders)) {
            $normalized = [];
            foreach ($itemOrders as $modId => $ids) {
                $normalized[(int) $modId] = array_values(array_filter(array_map('intval', (array) $ids)));
            }
            admin_apply_item_order($pdo, $courseId, $normalized);
        }
        flash('success', 'Module order saved.');
    }

    if (isset($_POST['add_module'])) {
        $title = trim($_POST['module_title'] ?? '');
        if ($title) {
            $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM modules WHERE course_id = ?');
            $posStmt->execute([$courseId]);
            $pos = (int) $posStmt->fetchColumn();
            $pdo->prepare('INSERT INTO modules (course_id, title, position, published) VALUES (?, ?, ?, 0)')->execute([$courseId, $title, $pos]);
            flash('success', 'Module added.');
        }
    }

    if (isset($_POST['update_module'])) {
        $moduleId = (int) $_POST['module_id'];
        $title = trim($_POST['module_title'] ?? '');
        if ($title && admin_module_in_course($pdo, $moduleId, $courseId)) {
            $pdo->prepare('UPDATE modules SET title = ? WHERE id = ?')->execute([$title, $moduleId]);
            flash('success', 'Module renamed.');
        }
    }

    if (isset($_POST['delete_module'])) {
        $moduleId = (int) $_POST['module_id'];
        if (admin_module_in_course($pdo, $moduleId, $courseId)) {
            $pdo->prepare('DELETE FROM modules WHERE id = ?')->execute([$moduleId]);
            flash('success', 'Module deleted.');
        }
    }

    if (isset($_POST['delete_item'])) {
        $itemId = (int) $_POST['item_id'];
        if (admin_item_in_course($pdo, $itemId, $courseId)) {
            $pdo->prepare('DELETE FROM module_items WHERE id = ?')->execute([$itemId]);
            flash('success', 'Item deleted.');
        }
    }

    if (isset($_POST['add_item'])) {
        $moduleId = (int) $_POST['module_id'];
        if (!admin_module_in_course($pdo, $moduleId, $courseId)) {
            redirect('/admin/modules.php?course_id=' . $courseId);
        }
        $title = trim($_POST['item_title'] ?? '');
        $type = $_POST['item_type'] ?? 'page';
        $content = trim($_POST['content'] ?? '');
        $refId = $_POST['ref_id'] !== '' ? (int) $_POST['ref_id'] : null;
        $filePath = null;
        $mime = null;

        if ($type === 'file') {
            if (empty($_FILES['module_file']['tmp_name'])) {
                flash('error', 'Choose a file to upload.');
                redirect('/admin/modules.php?course_id=' . $courseId);
            }
            $dest = $config['upload_dir'] . '/modules/course_' . $courseId;
            $upload = handle_upload($_FILES['module_file'], $dest, $config['upload_max_mb']);
            if (!$upload['ok']) {
                flash('error', $upload['error']);
                redirect('/admin/modules.php?course_id=' . $courseId);
            }
            $filePath = 'modules/course_' . $courseId . '/' . $upload['path'];
            $mime = $upload['mime'] ?? null;
            if ($title === '') {
                $title = $upload['name'];
            }
        }

        $posStmt = $pdo->prepare('SELECT COALESCE(MAX(position), -1) + 1 FROM module_items WHERE module_id = ?');
        $posStmt->execute([$moduleId]);
        $pos = (int) $posStmt->fetchColumn();
        $format = $type === 'page' && str_contains($content, '<') ? 'html' : 'text';
        $pdo->prepare(
            'INSERT INTO module_items (module_id, title, item_type, content, content_format, ref_id, file_path, position, published) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
        )->execute([
            $moduleId,
            $title,
            $type,
            in_array($type, ['page', 'external', 'file'], true) ? ($type === 'file' ? $mime : ($content ?: null)) : null,
            $format,
            in_array($type, ['assignment', 'quiz', 'discussion', 'announcement', 'lti'], true) ? $refId : null,
            $filePath,
            $pos,
        ]);
        flash('success', 'Module item added.');
    }

    redirect('/admin/modules.php?course_id=' . $courseId);
}

$modules = [];
$assignments = [];
$quizzes = [];
$discussions = [];
$announcements = [];
$ltiTools = [];
$course = null;
if ($courseId) {
    $course = $pdo->prepare('SELECT * FROM courses WHERE id = ?');
    $course->execute([$courseId]);
    $course = $course->fetch();
    $m = $pdo->prepare('SELECT * FROM modules WHERE course_id = ? ORDER BY position');
    $m->execute([$courseId]);
    $modules = $m->fetchAll();
    $a = $pdo->prepare('SELECT id, title FROM assignments WHERE course_id = ?');
    $a->execute([$courseId]);
    $assignments = $a->fetchAll();
    $q = $pdo->prepare('SELECT id, title FROM quizzes WHERE course_id = ?');
    $q->execute([$courseId]);
    $quizzes = $q->fetchAll();
    $d = $pdo->prepare('SELECT id, title FROM discussions WHERE course_id = ?');
    $d->execute([$courseId]);
    $discussions = $d->fetchAll();
    $an = $pdo->prepare('SELECT id, title FROM announcements WHERE course_id = ? ORDER BY created_at DESC');
    $an->execute([$courseId]);
    $announcements = $an->fetchAll();
    $lt = $pdo->prepare('SELECT id, name AS title FROM external_tools WHERE course_id = ? ORDER BY name');
    $lt->execute([$courseId]);
    $ltiTools = $lt->fetchAll();
}

render_head('Edit modules');
render_app_shell_start($user, 'admin', '/admin/index.php');
?>
<?php render_page_header('Modules & items', 'Content editor'); ?>
<div class="page-body">
  <form method="get" style="margin-bottom:16px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
    <select name="course_id" onchange="this.form.submit()" style="max-width:420px;">
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $courseId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code'] . ' — ' . $c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if ($courseId): ?>
      <?php render_admin_preview_link($courseId); ?>
      <a class="btn btn-sm btn-outline" href="<?= url('admin/courses.php?edit=' . $courseId) ?>">Edit course details</a>
    <?php endif; ?>
  </form>

  <?php if ($course):
    $coursePublished = course_is_published($course);
    $publishedModCount = count(array_filter($modules, fn($m) => module_is_published($m)));
  ?>
    <div class="publish-toolbar">
      <div class="publish-toolbar-status">
        <span class="publish-label">Course</span>
        <?= publish_status_badge($coursePublished, 'course') ?>
        <span class="publish-status-text"><?= $coursePublished ? 'Published' : 'Unpublished' ?></span>
        <span class="publish-meta">· <?= $publishedModCount ?>/<?= count($modules) ?> modules published</span>
      </div>
      <div class="publish-toolbar-actions">
        <form method="post" style="display:inline;">
          <input type="hidden" name="course_id" value="<?= $courseId ?>">
          <input type="hidden" name="toggle_course_publish" value="1">
          <button class="btn btn-sm" type="submit"><?= $coursePublished ? 'Unpublish course' : 'Publish course' ?></button>
        </form>
        <form method="post" style="display:inline;">
          <input type="hidden" name="course_id" value="<?= $courseId ?>">
          <input type="hidden" name="publish_all_modules" value="1">
          <button class="btn btn-sm btn-outline" type="submit">Publish all modules</button>
        </form>
        <form method="post" style="display:inline;" onsubmit="return confirm('Unpublish every module in this course?');">
          <input type="hidden" name="course_id" value="<?= $courseId ?>">
          <input type="hidden" name="unpublish_all_modules" value="1">
          <button class="btn btn-sm btn-outline" type="submit">Unpublish all modules</button>
        </form>
      </div>
    </div>
    <p style="color:#71717a;font-size:14px;margin-top:0;">
      Editing <strong><?= e($course['name']) ?></strong> — <?= count($modules) ?> modules.
      Publish modules one at a time, or use <strong>Publish all modules</strong> when you are ready.
      Use <strong>↑ ↓</strong> to rearrange, then <strong>Save order</strong>.
    </p>
  <?php endif; ?>

  <div id="order-save-bar" class="order-save-bar" hidden>
    <span class="order-save-msg">You have unsaved order changes.</span>
    <div style="display:flex;gap:8px;">
      <button type="button" class="btn btn-sm btn-outline" id="discard-order-btn">Discard</button>
      <button type="submit" class="btn btn-sm" form="save-order-form">Save order</button>
    </div>
  </div>

  <form id="save-order-form" method="post">
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
    <input type="hidden" name="save_order" value="1">
    <div id="order-inputs"></div>
  </form>

  <div class="content-box" style="margin-bottom:24px;background:#fafafa;">
    <form method="post" style="display:flex;gap:12px;">
      <input type="hidden" name="course_id" value="<?= $courseId ?>">
      <input type="hidden" name="add_module" value="1">
      <input name="module_title" placeholder="New module title" required style="flex:1;">
      <button class="btn" type="submit">Add module</button>
    </form>
  </div>

  <?php if ($modules): ?>
  <form id="bulk-modules-form" method="post">
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
  </form>
  <form id="bulk-items-form" method="post">
    <input type="hidden" name="course_id" value="<?= $courseId ?>">
  </form>

  <div class="bulk-bar">
    <span class="bulk-bar-label">Modules</span>
    <button type="button" class="btn btn-sm btn-outline" id="select-all-modules">Select all</button>
    <button type="button" class="btn btn-sm btn-outline" id="unselect-all-modules">Unselect all</button>
    <button type="submit" class="btn btn-sm" form="bulk-modules-form" name="bulk_publish_modules" value="1">Publish selected</button>
    <button type="submit" class="btn btn-sm btn-outline" form="bulk-modules-form" name="bulk_unpublish_modules" value="1">Unpublish selected</button>
  </div>
  <div class="bulk-bar bulk-bar-items">
    <span class="bulk-bar-label">Items</span>
    <button type="button" class="btn btn-sm btn-outline" id="select-all-items">Select all</button>
    <button type="button" class="btn btn-sm btn-outline" id="unselect-all-items">Unselect all</button>
    <button type="submit" class="btn btn-sm" form="bulk-items-form" name="bulk_publish_items" value="1">Publish selected</button>
    <button type="submit" class="btn btn-sm btn-outline" form="bulk-items-form" name="bulk_unpublish_items" value="1">Unpublish selected</button>
  </div>
  <?php endif; ?>

  <div id="module-list">
  <?php foreach ($modules as $mi => $mod):
    $itemStmt = $pdo->prepare('SELECT * FROM module_items WHERE module_id = ? ORDER BY position');
    $itemStmt->execute([$mod['id']]);
    $items = $itemStmt->fetchAll();
    $isFirstMod = $mi === 0;
    $isLastMod = $mi === count($modules) - 1;
  ?>
    <?php $modPublished = module_is_published($mod); ?>
    <section class="panel module-block <?= $modPublished ? '' : 'module-unpublished' ?>" style="margin-bottom:24px;" data-module-id="<?= (int)$mod['id'] ?>">
      <div class="panel-row module-head-row">
        <label class="bulk-check" title="Select module">
          <input type="checkbox" class="module-select" name="module_ids[]" value="<?= (int)$mod['id'] ?>" form="bulk-modules-form">
        </label>
        <?= publish_status_badge($modPublished) ?>
        <form method="post" class="module-title-form">
          <input type="hidden" name="course_id" value="<?= $courseId ?>">
          <input type="hidden" name="module_id" value="<?= (int)$mod['id'] ?>">
          <input type="hidden" name="update_module" value="1">
          <input name="module_title" value="<?= e($mod['title']) ?>" class="module-title-input">
          <button class="btn btn-sm" type="submit">Save name</button>
        </form>
        <div class="module-head-actions">
          <button type="button" class="btn btn-sm btn-outline move-module-up" <?= $isFirstMod ? 'disabled' : '' ?> title="Move up (draft)">↑</button>
          <button type="button" class="btn btn-sm btn-outline move-module-down" <?= $isLastMod ? 'disabled' : '' ?> title="Move down (draft)">↓</button>
          <div class="kebab-menu">
            <button type="button" class="kebab-btn" aria-label="Module options" aria-haspopup="true">⋮</button>
            <div class="kebab-dropdown" hidden>
              <?php if ($modPublished): ?>
                <form method="post">
                  <input type="hidden" name="course_id" value="<?= $courseId ?>">
                  <input type="hidden" name="module_id" value="<?= (int)$mod['id'] ?>">
                  <input type="hidden" name="toggle_module_publish" value="1">
                  <button type="submit" class="kebab-item">Unpublish module</button>
                </form>
              <?php else: ?>
                <form method="post">
                  <input type="hidden" name="course_id" value="<?= $courseId ?>">
                  <input type="hidden" name="module_id" value="<?= (int)$mod['id'] ?>">
                  <input type="hidden" name="toggle_module_publish" value="1">
                  <button type="submit" class="kebab-item">Publish module</button>
                </form>
              <?php endif; ?>
              <form method="post" onsubmit="return confirm('Delete this module and all its items?');">
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <input type="hidden" name="module_id" value="<?= (int)$mod['id'] ?>">
                <input type="hidden" name="delete_module" value="1">
                <button type="submit" class="kebab-item kebab-item-danger">Delete module</button>
              </form>
            </div>
          </div>
        </div>
      </div>

      <div class="item-list" data-module-id="<?= (int)$mod['id'] ?>">
      <?php foreach ($items as $ii => $it):
        $isFirstItem = $ii === 0;
        $isLastItem = $ii === count($items) - 1;
      ?>
        <?php $itemPublished = item_is_published($it); ?>
        <div class="panel-row item-row <?= $itemPublished ? '' : 'item-unpublished' ?>" data-item-id="<?= (int)$it['id'] ?>">
          <label class="bulk-check" title="Select item">
            <input type="checkbox" class="item-select" name="item_ids[]" value="<?= (int)$it['id'] ?>" form="bulk-items-form">
          </label>
          <?= publish_status_badge($itemPublished) ?>
          <div class="item-row-main">
            <strong><?= e($it['title']) ?></strong>
            <span class="item-type-label"><?= e($it['item_type']) ?></span>
          </div>
          <div class="item-row-actions">
            <a class="btn btn-sm" href="<?= url('admin/edit-item.php?course_id=' . $courseId . '&id=' . $it['id']) ?>">Edit</a>
            <button type="button" class="btn btn-sm btn-outline move-item-up" <?= $isFirstItem ? 'disabled' : '' ?>>↑</button>
            <button type="button" class="btn btn-sm btn-outline move-item-down" <?= $isLastItem ? 'disabled' : '' ?>>↓</button>
            <div class="kebab-menu">
              <button type="button" class="kebab-btn kebab-btn-sm" aria-label="Item options" aria-haspopup="true">⋮</button>
              <div class="kebab-dropdown" hidden>
                <?php if ($itemPublished): ?>
                  <form method="post">
                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                    <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                    <input type="hidden" name="toggle_item_publish" value="1">
                    <button type="submit" class="kebab-item">Unpublish item</button>
                  </form>
                <?php else: ?>
                  <form method="post">
                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                    <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                    <input type="hidden" name="toggle_item_publish" value="1">
                    <button type="submit" class="kebab-item">Publish item</button>
                  </form>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('Delete this item?');">
                  <input type="hidden" name="course_id" value="<?= $courseId ?>">
                  <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                  <input type="hidden" name="delete_item" value="1">
                  <button type="submit" class="kebab-item kebab-item-danger">Delete item</button>
                </form>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      </div>

      <?php if (!$items): ?>
        <div class="panel-row" style="color:#71717a;font-size:13px;">No items in this module.</div>
      <?php endif; ?>

      <div class="panel-row" style="background:#fafafa;">
        <details>
          <summary style="cursor:pointer;font-weight:500;font-size:14px;">+ Add item to this module</summary>
          <form method="post" enctype="multipart/form-data" style="margin-top:16px;">
            <input type="hidden" name="course_id" value="<?= $courseId ?>">
            <input type="hidden" name="add_item" value="1">
            <input type="hidden" name="module_id" value="<?= (int)$mod['id'] ?>">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
              <div class="form-group"><label>Item title</label><input name="item_title"></div>
              <div class="form-group">
                <label>Type</label>
                <select name="item_type" class="item-type-select" data-mod="<?= (int)$mod['id'] ?>">
                  <option value="page">Page</option>
                  <option value="assignment">Assignment link</option>
                  <option value="quiz">Quiz link</option>
                  <option value="discussion">Discussion link</option>
                  <option value="announcement">Announcement link</option>
                  <option value="file">File upload</option>
                  <option value="external">External URL</option>
                  <option value="lti">External tool (LTI)</option>
                </select>
              </div>
              <div class="form-group ref-field-<?= (int)$mod['id'] ?>" style="grid-column:1/-1;display:none;">
                <label>Link to</label>
                <select name="ref_id">
                  <optgroup label="Assignments"><?php foreach ($assignments as $a): ?><option value="<?= (int)$a['id'] ?>"><?= e($a['title']) ?></option><?php endforeach; ?></optgroup>
                  <optgroup label="Quizzes"><?php foreach ($quizzes as $q): ?><option value="<?= (int)$q['id'] ?>"><?= e($q['title']) ?></option><?php endforeach; ?></optgroup>
                  <optgroup label="Discussions"><?php foreach ($discussions as $di): ?><option value="<?= (int)$di['id'] ?>"><?= e($di['title']) ?></option><?php endforeach; ?></optgroup>
                  <optgroup label="Announcements"><?php foreach ($announcements as $an): ?><option value="<?= (int)$an['id'] ?>"><?= e($an['title']) ?></option><?php endforeach; ?></optgroup>
                  <optgroup label="External tools"><?php foreach ($ltiTools as $lt): ?><option value="<?= (int)$lt['id'] ?>"><?= e($lt['title']) ?></option><?php endforeach; ?></optgroup>
                </select>
              </div>
              <div class="form-group file-field-<?= (int)$mod['id'] ?>" style="grid-column:1/-1;display:none;">
                <label>File</label>
                <input type="file" name="module_file">
              </div>
              <div class="form-group content-field-<?= (int)$mod['id'] ?>" style="grid-column:1/-1;">
                <label>Content / URL</label>
                <textarea name="content" rows="3" placeholder="Page HTML/text or external URL"></textarea>
              </div>
            </div>
            <button class="btn btn-sm" type="submit" style="margin-top:8px;">Add item</button>
          </form>
        </details>
      </div>
    </section>
  <?php endforeach; ?>
  </div>

  <?php if (!$modules): ?>
    <?php render_empty_state('No modules yet.', 'Add a module above or import an IMS package.', [
        ['label' => 'Import IMS package', 'href' => url('admin/import.php'), 'primary' => true],
    ]); ?>
  <?php endif; ?>

</div>
<script>
(function () {
  const saveBar = document.getElementById('order-save-bar');
  const saveForm = document.getElementById('save-order-form');
  const orderInputs = document.getElementById('order-inputs');
  let orderDirty = false;

  function setDirty(dirty) {
    orderDirty = dirty;
    if (saveBar) saveBar.hidden = !dirty;
    document.querySelectorAll('.module-block').forEach(el => {
      el.classList.toggle('order-dirty', dirty);
    });
  }

  function swapNodes(a, b) {
    const parent = a.parentNode;
    const after = b.nextSibling === a ? b : b.nextSibling;
    parent.insertBefore(a, after);
  }

  function refreshMoveButtons() {
    const modules = [...document.querySelectorAll('#module-list .module-block')];
    modules.forEach((mod, i) => {
      mod.querySelector('.move-module-up').disabled = i === 0;
      mod.querySelector('.move-module-down').disabled = i === modules.length - 1;
    });
    document.querySelectorAll('.item-list').forEach(list => {
      const rows = [...list.querySelectorAll('.item-row')];
      rows.forEach((row, i) => {
        row.querySelector('.move-item-up').disabled = i === 0;
        row.querySelector('.move-item-down').disabled = i === rows.length - 1;
      });
    });
  }

  document.getElementById('module-list')?.addEventListener('click', e => {
    const mod = e.target.closest('.module-block');
    if (e.target.classList.contains('move-module-up')) {
      const prev = mod?.previousElementSibling;
      if (prev) { swapNodes(mod, prev); setDirty(true); refreshMoveButtons(); }
    }
    if (e.target.classList.contains('move-module-down')) {
      const next = mod?.nextElementSibling;
      if (next) { swapNodes(next, mod); setDirty(true); refreshMoveButtons(); }
    }
    const row = e.target.closest('.item-row');
    if (row && e.target.classList.contains('move-item-up')) {
      const prev = row.previousElementSibling;
      if (prev?.classList.contains('item-row')) { swapNodes(row, prev); setDirty(true); refreshMoveButtons(); }
    }
    if (row && e.target.classList.contains('move-item-down')) {
      const next = row.nextElementSibling;
      if (next?.classList.contains('item-row')) { swapNodes(next, row); setDirty(true); refreshMoveButtons(); }
    }
  });

  document.getElementById('discard-order-btn')?.addEventListener('click', () => {
    if (!orderDirty || confirm('Discard unsaved order changes?')) {
      location.reload();
    }
  });

  saveForm?.addEventListener('submit', () => {
    orderInputs.innerHTML = '';
    document.querySelectorAll('#module-list .module-block').forEach(mod => {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'module_order[]';
      input.value = mod.dataset.moduleId;
      orderInputs.appendChild(input);
    });
    document.querySelectorAll('.item-list').forEach(list => {
      const modId = list.dataset.moduleId;
      list.querySelectorAll('.item-row').forEach(row => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'item_order[' + modId + '][]';
        input.value = row.dataset.itemId;
        orderInputs.appendChild(input);
      });
    });
  });

  window.addEventListener('beforeunload', e => {
    if (orderDirty) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  document.getElementById('select-all-modules')?.addEventListener('click', () => {
    document.querySelectorAll('.module-select').forEach(cb => { cb.checked = true; });
  });
  document.getElementById('unselect-all-modules')?.addEventListener('click', () => {
    document.querySelectorAll('.module-select').forEach(cb => { cb.checked = false; });
  });
  document.getElementById('select-all-items')?.addEventListener('click', () => {
    document.querySelectorAll('.item-select').forEach(cb => { cb.checked = true; });
  });
  document.getElementById('unselect-all-items')?.addEventListener('click', () => {
    document.querySelectorAll('.item-select').forEach(cb => { cb.checked = false; });
  });

  document.addEventListener('click', e => {
    const btn = e.target.closest('.kebab-btn');
    if (btn) {
      e.stopPropagation();
      const menu = btn.parentElement?.querySelector('.kebab-dropdown');
      document.querySelectorAll('.kebab-dropdown').forEach(d => {
        if (d !== menu) d.hidden = true;
      });
      if (menu) menu.hidden = !menu.hidden;
      return;
    }
    if (!e.target.closest('.kebab-menu')) {
      document.querySelectorAll('.kebab-dropdown').forEach(d => { d.hidden = true; });
    }
  });

  document.querySelectorAll('.item-type-select').forEach(sel => {
    const id = sel.dataset.mod;
    const toggle = () => {
      const type = sel.value;
      const ref = document.querySelector('.ref-field-' + id);
      const content = document.querySelector('.content-field-' + id);
      const file = document.querySelector('.file-field-' + id);
      if (ref) ref.style.display = ['assignment','quiz','discussion','announcement','lti'].includes(type) ? 'block' : 'none';
      if (file) file.style.display = type === 'file' ? 'block' : 'none';
      if (content) content.style.display = (type === 'page' || type === 'external') ? 'block' : 'none';
    };
    sel.addEventListener('change', toggle);
    toggle();
  });
})();
</script>
<?php render_app_shell_end(); ?>