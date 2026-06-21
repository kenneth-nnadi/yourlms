<?php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/bulk_enroll.php';
$user = require_login();

$courses = courses_for_people_admin($pdo, $user);
if (!$courses) {
    flash('error', 'You do not have permission to manage people in any course.');
    redirect('/dashboard.php');
}

$courseId = (int) ($_GET['course_id'] ?? ($courses[0]['id'] ?? 0));

if ($courseId && !user_can_manage_course_people($pdo, $courseId, $user)) {
    flash('error', 'You do not have permission to manage people in this course.');
    redirect('/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $courseId = (int) ($_POST['course_id'] ?? 0);
    course_or_404($pdo, $courseId);
    if (!user_can_manage_course_people($pdo, $courseId, $user)) {
        flash('error', 'You do not have permission to manage people in this course.');
        redirect('/dashboard.php');
    }

    if (isset($_POST['remove_enrollment'])) {
        $pdo->prepare('DELETE FROM enrollments WHERE course_id = ? AND user_id = ?')
            ->execute([$courseId, (int) $_POST['user_id']]);
        flash('success', 'Person removed from course.');
    }

    if (isset($_POST['update_role'])) {
        $role = $_POST['role'] ?? 'student';
        if (in_array($role, enrollment_roles(), true)) {
            $pdo->prepare('UPDATE enrollments SET role = ? WHERE course_id = ? AND user_id = ?')
                ->execute([$role, $courseId, (int) $_POST['user_id']]);
            flash('success', 'Course role updated.');
        }
    }

    if (isset($_POST['bulk_enroll'])) {
        try {
            if (empty($_FILES['csv']['tmp_name'])) {
                throw new RuntimeException('Choose a CSV file to upload.');
            }
            $contents = (string) file_get_contents($_FILES['csv']['tmp_name']);
            $rows = parse_bulk_enroll_csv($contents);
            $createMissing = isset($_POST['bulk_create']);
            $defaultPassword = trim($_POST['bulk_default_password'] ?? '');
            $stats = bulk_enroll_csv_rows($pdo, $courseId, $rows, $createMissing, $defaultPassword);
            $msg = "Bulk enroll: {$stats['enrolled']} enrolled";
            if ($stats['created']) {
                $msg .= ", {$stats['created']} accounts created";
            }
            if ($stats['skipped']) {
                $msg .= ", {$stats['skipped']} skipped";
            }
            flash($stats['errors'] ? 'error' : 'success', $msg . '.');
            if ($stats['errors']) {
                $_SESSION['bulk_enroll_errors'] = $stats['errors'];
            }
        } catch (Throwable $e) {
            flash('error', 'Bulk enroll failed: ' . $e->getMessage());
        }
        redirect('/admin/people.php?course_id=' . $courseId);
    }

    if (isset($_POST['create_simple_user'])) {
        if (!user_is_site_instructor($user)) {
            flash('error', 'Only site instructors can create username-only accounts.');
            redirect('/admin/people.php?course_id=' . $courseId);
        }
        $username = $_POST['simple_username'] ?? '';
        $password = $_POST['simple_password'] ?? '';
        $accountRole = $_POST['simple_account_role'] ?? 'student';
        $enrollRole = $_POST['simple_enroll_role'] ?? 'student';

        if (!in_array($enrollRole, enrollment_roles(), true)) {
            flash('error', 'Invalid course role.');
            redirect('/admin/people.php?course_id=' . $courseId);
        }

        $err = create_simple_user_account($pdo, $username, $password, $accountRole);
        if ($err) {
            flash('error', $err);
            redirect('/admin/people.php?course_id=' . $courseId);
        }

        $uid = (int) $pdo->lastInsertId();
        enroll_user_in_course($pdo, $courseId, $uid, $enrollRole);
        flash('success', 'Username account "' . normalize_username($username) . '" created and added to course.');
        redirect('/admin/people.php?course_id=' . $courseId);
    }

    if (isset($_POST['reset_managed_password'])) {
        if (!user_is_site_instructor($user)) {
            flash('error', 'Only site instructors can reset passwords for username-only accounts.');
            redirect('/admin/people.php?course_id=' . $courseId);
        }
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        $err = admin_set_user_password($pdo, $targetId, $newPassword);
        flash($err ? 'error' : 'success', $err ?? 'Password updated.');
        redirect('/admin/people.php?course_id=' . $courseId);
    }

    if (isset($_POST['add_person'])) {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $role = $_POST['enroll_role'] ?? 'student';
        $createNew = isset($_POST['create_if_missing']);
        $fullName = trim($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!in_array($role, enrollment_roles(), true)) {
            flash('error', 'Invalid course role.');
            redirect('/admin/people.php?course_id=' . $courseId);
        }

        $u = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $u->execute([$email]);
        $existing = $u->fetch();

        if (!$existing && $createNew && $fullName && strlen($password) >= 6) {
            $accountRole = match ($role) {
                'instructor' => 'instructor',
                'ta' => 'ta',
                'guest' => 'guest',
                default => 'student',
            };
            $err = create_user_account($pdo, $email, $password, $fullName, $accountRole);
            if ($err) {
                flash('error', $err);
                redirect('/admin/people.php?course_id=' . $courseId);
            }
            $u->execute([$email]);
            $existing = $u->fetch();
        }

        if ($existing) {
            enroll_user_in_course($pdo, $courseId, (int) $existing['id'], $role);
            flash('success', enrollment_role_label($role) . ' added to course.');
        } else {
            flash('error', 'No account found for that email. Check "Create account if this email is not registered yet".');
        }
    }

    redirect('/admin/people.php?course_id=' . $courseId);
}

$bulkErrors = $_SESSION['bulk_enroll_errors'] ?? [];
unset($_SESSION['bulk_enroll_errors']);

$enrolled = [];
$course = null;
if ($courseId) {
    $stmt = $pdo->prepare('SELECT * FROM courses WHERE id = ?');
    $stmt->execute([$courseId]);
    $course = $stmt->fetch();
    $stmt = $pdo->prepare(
        'SELECT u.id, u.full_name, u.email, u.username, u.admin_managed_password, u.role AS account_role, e.role, e.created_at
         FROM enrollments e
         JOIN users u ON u.id = e.user_id
         WHERE e.course_id = ?
         ORDER BY ' . sql_enrollment_role_order() . ', u.full_name'
    );
    $stmt->execute([$courseId]);
    $enrolled = $stmt->fetchAll();
}

render_head('People');
$peopleBack = user_can_access_teach_menu($pdo, $user) ? '/admin/index.php' : '/dashboard.php';
render_app_shell_start($user, 'admin', $peopleBack);
?>
<?php render_page_header('People', 'Course roster & accounts'); ?>
<div class="page-body">
  <form method="get" style="margin-bottom:24px;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
    <select name="course_id" onchange="this.form.submit()" style="max-width:420px;">
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $courseId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['code'] . ' — ' . $c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php if ($course): ?>
    <p style="color:#71717a;font-size:14px;margin-top:0;">
      <strong><?= e($course['name']) ?></strong> — <?= count($enrolled) ?> people enrolled.
      Assign course roles — Teacher, TA, Student, or Guest. New accounts can be created right here when you add someone.
    </p>
  <?php endif; ?>

  <?php if (user_is_site_instructor($user)): ?>
    <div class="content-box" style="margin-bottom:24px;">
      <h3 style="margin:0 0 12px;">+ Quick account (username only)</h3>
      <p class="text-muted" style="font-size:13px;margin:0 0 16px;line-height:1.5;">
        Create a login with just a username and password — no email. The student cannot change their own password; reset it here when needed.
      </p>
      <form method="post">
        <input type="hidden" name="course_id" value="<?= $courseId ?>">
        <input type="hidden" name="create_simple_user" value="1">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
          <div class="form-group"><label>Username</label><input type="text" name="simple_username" required minlength="3" maxlength="64" pattern="[A-Za-z0-9._-]+" autocomplete="off" placeholder="e.g. jsmith"></div>
          <div class="form-group"><label>Password</label><input type="password" name="simple_password" required minlength="6" autocomplete="new-password"></div>
          <div class="form-group">
            <label>Site account role</label>
            <select name="simple_account_role">
              <?php foreach (account_roles() as $r): ?>
                <option value="<?= e($r) ?>" <?= $r === 'student' ? 'selected' : '' ?>><?= e(account_role_label($r)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Course role</label>
            <select name="simple_enroll_role">
              <?php foreach (enrollment_roles() as $r): ?>
                <option value="<?= e($r) ?>" <?= $r === 'student' ? 'selected' : '' ?>><?= e(enrollment_role_label($r)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <button class="btn" type="submit" style="margin-top:12px;">Create &amp; enroll</button>
      </form>
    </div>
  <?php endif; ?>

  <div class="content-box" style="margin-bottom:24px;">
    <h3 style="margin:0 0 12px;">+ Add people (email)</h3>
    <form method="post" id="add-person-form">
      <input type="hidden" name="course_id" value="<?= $courseId ?>">
      <input type="hidden" name="add_person" value="1">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group"><label>Email</label><input type="email" name="email" required placeholder="name@school.edu"></div>
        <div class="form-group">
          <label>Course role</label>
          <select name="enroll_role">
            <?php foreach (enrollment_roles() as $r): ?>
              <option value="<?= e($r) ?>"><?= e(enrollment_role_label($r)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="grid-column:1/-1;">
          <label style="display:flex;align-items:center;gap:8px;font-weight:500;">
            <input type="checkbox" name="create_if_missing" value="1" id="create-if-missing">
            Create account if this email is not registered yet
          </label>
        </div>
        <div class="form-group create-fields" style="display:none;"><label>Full name</label><input name="full_name"></div>
        <div class="form-group create-fields" style="display:none;"><label>Password</label><input type="password" name="password" minlength="6"></div>
      </div>
      <button class="btn" type="submit" style="margin-top:12px;">Add to course</button>
    </form>
  </div>

  <div class="content-box" style="margin-bottom:24px;">
    <h3 style="margin:0 0 12px;">Bulk enroll (CSV)</h3>
    <p style="font-size:13px;color:#71717a;margin:0 0 12px;">
      Upload a CSV with an <code>email</code> column. Optional: <code>role</code> (student, ta, instructor/teacher, guest), <code>full_name</code>, <code>password</code>.
    </p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="course_id" value="<?= $courseId ?>">
      <input type="hidden" name="bulk_enroll" value="1">
      <div class="form-group">
        <label>CSV file</label>
        <input type="file" name="csv" accept=".csv,text/csv" required>
      </div>
      <label style="display:flex;align-items:center;gap:8px;font-size:14px;margin:12px 0;">
        <input type="checkbox" name="bulk_create" value="1">
        Create accounts for emails not yet registered
      </label>
      <div class="form-group">
        <label>Default password for new accounts (if CSV has no password column)</label>
        <input type="password" name="bulk_default_password" minlength="6" placeholder="Min 6 characters">
      </div>
      <button class="btn btn-outline" type="submit">Import CSV</button>
      <a class="btn btn-sm btn-outline" href="data:text/csv;charset=utf-8,email%2Crole%2Cfull_name%0Astudent%40school.edu%2Cstudent%2CJamie%20Lee" download="enroll-template.csv" style="margin-left:8px;">Download template</a>
    </form>
    <?php if ($bulkErrors): ?>
      <ul style="margin:12px 0 0;padding-left:20px;font-size:13px;color:#b45309;">
        <?php foreach (array_slice($bulkErrors, 0, 10) as $err): ?>
          <li><?= e($err) ?></li>
        <?php endforeach; ?>
        <?php if (count($bulkErrors) > 10): ?>
          <li>…and <?= count($bulkErrors) - 10 ?> more</li>
        <?php endif; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="panel">
    <table class="data-table">
      <thead>
        <tr><th>Name</th><th>Login</th><th>Course role</th><th>Account</th><th>Added</th><th></th></tr>
      </thead>
      <tbody>
        <?php foreach ($enrolled as $e): ?>
          <tr>
            <td><strong><?= e($e['full_name']) ?></strong></td>
            <td>
              <?php if (!empty($e['username'])): ?>
                <code><?= e($e['username']) ?></code>
                <?php if (!empty($e['admin_managed_password'])): ?>
                  <span class="role-pill" style="margin-left:6px;font-size:10px;">Admin password</span>
                <?php endif; ?>
              <?php else: ?>
                <?= e($e['email']) ?>
              <?php endif; ?>
            </td>
            <td>
              <form method="post" style="display:flex;gap:6px;align-items:center;">
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <input type="hidden" name="user_id" value="<?= (int)$e['id'] ?>">
                <input type="hidden" name="update_role" value="1">
                <select name="role" onchange="this.form.submit()" style="min-width:120px;">
                  <?php foreach (enrollment_roles() as $r): ?>
                    <option value="<?= e($r) ?>" <?= $e['role'] === $r ? 'selected' : '' ?>><?= e(enrollment_role_label($r)) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <td><span class="role-pill role-<?= e($e['account_role']) ?>"><?= e(account_role_label($e['account_role'])) ?></span></td>
            <td style="color:#71717a;font-size:13px;"><?= e(format_datetime($e['created_at'], 'M j, Y')) ?></td>
            <td style="white-space:nowrap;">
              <?php if (user_is_site_instructor($user) && !empty($e['admin_managed_password'])): ?>
                <details style="display:inline-block;margin-right:8px;">
                  <summary class="btn btn-sm btn-outline" style="list-style:none;cursor:pointer;">Reset password</summary>
                  <form method="post" style="margin-top:8px;min-width:200px;">
                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                    <input type="hidden" name="user_id" value="<?= (int)$e['id'] ?>">
                    <input type="hidden" name="reset_managed_password" value="1">
                    <div class="form-group" style="margin-bottom:8px;"><input type="password" name="new_password" required minlength="6" placeholder="New password"></div>
                    <button class="btn btn-sm" type="submit">Set password</button>
                  </form>
                </details>
              <?php endif; ?>
              <form method="post" onsubmit="return confirm('Remove <?= e($e['full_name']) ?> from this course?');" style="display:inline;">
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <input type="hidden" name="user_id" value="<?= (int)$e['id'] ?>">
                <input type="hidden" name="remove_enrollment" value="1">
                <button class="btn btn-sm btn-outline" type="submit" style="color:#b91c1c;border-color:#fecaca;">Remove</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$enrolled): ?>
          <tr><td colspan="6" style="text-align:center;color:#71717a;padding:24px;">No one enrolled yet. Add people above.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="content-box" style="margin-top:24px;font-size:14px;color:#52525b;line-height:1.6;">
    <strong>Role guide</strong>
    <ul style="margin:8px 0 0;padding-left:20px;">
      <li><strong>Teacher</strong> — full course access, can edit modules, publish content, and manage enrollments</li>
      <li><strong>TA</strong> — can grade, edit content, manage enrollments, view unpublished content, and post announcements</li>
      <li><strong>Student</strong> — can submit assignments, take quizzes, post in discussions</li>
      <li><strong>Guest</strong> — read-only observer; sees published content but cannot submit</li>
    </ul>
  </div>

</div>
<script>
document.getElementById('create-if-missing')?.addEventListener('change', function () {
  document.querySelectorAll('.create-fields').forEach(el => {
    el.style.display = this.checked ? 'block' : 'none';
  });
});
</script>
<?php render_app_shell_end(); ?>