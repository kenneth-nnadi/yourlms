<?php
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/admin_helpers.php';
require_once __DIR__ . '/../includes/course_export.php';
require_once __DIR__ . '/../includes/course_duplicate.php';
$user = require_teach_access($pdo);

$editId = (int) ($_GET['edit'] ?? 0);
$editing = null;
if ($editId) {
    $stmt = $pdo->prepare('SELECT * FROM courses WHERE id = ?');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
    if ($editing && !user_is_site_instructor($user) && !user_is_course_staff($pdo, $editId, $user)) {
        flash('error', 'You do not have access to this course.');
        redirect('/admin/courses.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_id'])) {
        if (!user_can_manage_site_courses($user)) {
            flash('error', 'Only site instructors can delete courses.');
            redirect('/admin/courses.php');
        }
        $pdo->prepare('DELETE FROM courses WHERE id = ?')->execute([(int) $_POST['delete_id']]);
        flash('success', 'Course deleted.');
        redirect('/admin/courses.php');
    }

    if (isset($_POST['update_id'])) {
        $id = (int) $_POST['update_id'];
        if (!user_is_site_instructor($user) && !user_is_course_teacher($pdo, $id, $user)) {
            flash('error', 'Only course teachers can edit course details.');
            redirect('/admin/courses.php');
        }
        $pdo->prepare(
            'UPDATE courses SET code = ?, name = ?, term = ?, description = ?, color = ?, published = ? WHERE id = ?'
        )->execute([
            trim($_POST['code'] ?? ''),
            trim($_POST['name'] ?? ''),
            trim($_POST['term'] ?? '') ?: null,
            trim($_POST['description'] ?? '') ?: null,
            trim($_POST['color'] ?? '#0055a4'),
            isset($_POST['published']) ? 1 : 0,
            $id,
        ]);
        flash('success', 'Course updated.');
        redirect('/admin/courses.php?edit=' . $id);
    }

    if (isset($_POST['duplicate_id'])) {
        $sourceId = (int) $_POST['duplicate_id'];
        require_course_content_editor($pdo, $sourceId, $user);
        try {
            $report = duplicate_course_with_files($pdo, $sourceId, $config, $user['id']);
            flash('success', 'Course duplicated with files. Open the copy to edit code and name.');
            redirect('/admin/courses.php?edit=' . (int) $report['course_id']);
        } catch (Throwable $e) {
            flash('error', 'Duplicate failed: ' . $e->getMessage());
            redirect('/admin/courses.php');
        }
    }

    if (isset($_POST['toggle_publish_id'])) {
        $id = (int) $_POST['toggle_publish_id'];
        if (!user_is_course_staff($pdo, $id, $user)) {
            flash('error', 'You cannot change publish status for this course.');
            redirect('/admin/courses.php');
        }
        $stmt = $pdo->prepare('SELECT published FROM courses WHERE id = ?');
        $stmt->execute([$id]);
        $current = (int) $stmt->fetchColumn();
        $pdo->prepare('UPDATE courses SET published = ? WHERE id = ?')->execute([$current ? 0 : 1, $id]);
        flash('success', $current ? 'Course unpublished.' : 'Course published.');
        redirect('/admin/courses.php');
    }

    if (!user_can_manage_site_courses($user)) {
        flash('error', 'Only site instructors can create new courses.');
        redirect('/admin/courses.php');
    }

    $code = trim($_POST['code'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $term = trim($_POST['term'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $color = trim($_POST['color'] ?? '#0055a4');
    if ($code && $name) {
        $pdo->prepare('INSERT INTO courses (code, name, term, description, color, created_by) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$code, $name, $term, $description, $color, $user['id']]);
        $courseId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO enrollments (course_id, user_id, role) VALUES (?, ?, ?)')
            ->execute([$courseId, $user['id'], 'instructor']);
        flash('success', 'Course created.');
    }
    redirect('/admin/courses.php');
}

$courses = teach_admin_courses($pdo, $user);
$canCreate = user_can_manage_site_courses($user);

render_head($editing ? 'Edit Course' : 'Manage Courses');
render_app_shell_start($user, 'admin', '/admin/index.php');
?>
<?php render_page_header($editing ? 'Edit course' : 'Courses', 'Teach'); ?>
<div class="page-body">
  <?php if ($editing):
    $canEditDetails = user_is_site_instructor($user) || user_is_course_teacher($pdo, (int) $editing['id'], $user);
  ?>
    <?php if ($canEditDetails): ?>
    <div class="content-box" style="margin-bottom:32px;background:#fafafa;">
      <h3 style="margin:0 0 12px;">Edit: <?= e($editing['name']) ?></h3>
      <form method="post" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <input type="hidden" name="update_id" value="<?= (int)$editing['id'] ?>">
        <div class="form-group"><label>Code</label><input name="code" required value="<?= e($editing['code']) ?>"></div>
        <div class="form-group"><label>Term</label><input name="term" value="<?= e($editing['term'] ?? '') ?>"></div>
        <div class="form-group" style="grid-column:1/-1;"><label>Name</label><input name="name" required value="<?= e($editing['name']) ?>"></div>
        <div class="form-group" style="grid-column:1/-1;"><label>Description</label><textarea name="description" rows="4"><?= e($editing['description'] ?? '') ?></textarea></div>
        <div class="form-group"><label>Card color</label><input name="color" type="text" value="<?= e($editing['color'] ?? '#0055a4') ?>"></div>
        <div class="form-group" style="align-self:end;">
          <label style="display:flex;align-items:center;gap:8px;font-weight:500;">
            <input type="checkbox" name="published" value="1" <?= course_is_published($editing) ? 'checked' : '' ?>>
            Published (visible to students)
          </label>
        </div>
        <div style="align-self:end;display:flex;gap:8px;">
          <button class="btn" type="submit">Save changes</button>
          <a class="btn btn-outline" href="<?= url('admin/courses.php') ?>">Cancel</a>
        </div>
      </form>
    </div>
    <?php else: ?>
      <p style="color:#71717a;">You can view this course but only the course teacher can edit details.</p>
    <?php endif; ?>
  <?php elseif ($canCreate): ?>
    <div class="content-box" style="margin-bottom:32px;background:#fafafa;">
      <h3 style="margin:0 0 12px;">New course</h3>
      <form method="post" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label>Code</label><input name="code" required placeholder="CYBR-101"></div>
        <div class="form-group"><label>Term</label><input name="term" placeholder="Summer 2026"></div>
        <div class="form-group" style="grid-column:1/-1;"><label>Name</label><input name="name" required></div>
        <div class="form-group" style="grid-column:1/-1;"><label>Description</label><textarea name="description" rows="2"></textarea></div>
        <div class="form-group"><label>Color</label><input name="color" type="text" value="#0055a4"></div>
        <div style="align-self:end;"><button class="btn" type="submit">Create course</button></div>
      </form>
    </div>
  <?php endif; ?>

  <div class="panel">
    <?php foreach ($courses as $c):
      $cid = (int) $c['id'];
      $isStaff = user_is_course_staff($pdo, $cid, $user);
      $isTeacher = user_is_course_teacher($pdo, $cid, $user);
    ?>
      <div class="panel-row" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
          <strong style="color:var(--brand-accent);"><?= e($c['code']) ?> — <?= e($c['name']) ?></strong>
          <div style="font-size:13px;color:#71717a;display:flex;align-items:center;gap:8px;margin-top:4px;">
            <?= e($c['term'] ?? '') ?>
            <?= publish_status_badge(course_is_published($c), 'course') ?>
            <span><?= course_is_published($c) ? 'Published' : 'Unpublished' ?></span>
          </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <?php if ($isStaff): ?>
            <form method="post">
              <input type="hidden" name="toggle_publish_id" value="<?= $cid ?>">
              <button class="btn btn-sm btn-outline" type="submit"><?= course_is_published($c) ? 'Unpublish' : 'Publish' ?></button>
            </form>
          <?php endif; ?>
          <a class="btn btn-sm btn-outline" href="<?= url('course.php?id=' . $cid) ?>">View</a>
          <?php if ($isTeacher): ?>
            <a class="btn btn-sm btn-outline" href="<?= url('admin/modules.php?course_id=' . $cid) ?>">Edit content</a>
            <a class="btn btn-sm" href="<?= url('admin/courses.php?edit=' . $cid) ?>">Edit details</a>
            <form method="post" onsubmit="return confirm('Create a copy of this course (content only, no enrollments)?');">
              <input type="hidden" name="duplicate_id" value="<?= $cid ?>">
              <button class="btn btn-sm btn-outline" type="submit">Duplicate</button>
            </form>
          <?php endif; ?>
          <?php if (user_can_manage_site_courses($user)): ?>
            <form method="post" onsubmit="return confirm('Delete this course and all its content?');">
              <input type="hidden" name="delete_id" value="<?= $cid ?>">
              <button class="btn btn-sm" type="submit" style="background:#b91c1c;">Delete</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <?php if (!$courses): ?>
      <div class="panel-row" style="color:#71717a;">No courses assigned to you yet.</div>
    <?php endif; ?>
  </div>
</div>
<?php render_app_shell_end(); ?>