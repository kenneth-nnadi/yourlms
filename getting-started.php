<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();
if (!user_is_site_instructor($user) && !user_can_access_teach_menu($pdo, $user)) {
    redirect('/dashboard.php');
}

$courseCount = (int) $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn();
$studentCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();

render_head('Getting started');
render_app_shell_start($user, 'admin', '/dashboard.php');
render_page_header('Getting started', 'YourLMS');
?>
<div class="page-body" style="max-width:760px;">
  <p style="font-size:15px;line-height:1.65;color:#475569;margin-top:0;">
    YourLMS is installed and ready. Follow these steps to launch your first course.
    Nothing is pre-loaded — you start with a <strong>clean slate</strong>.
  </p>

  <ol class="getting-started-steps">
    <li class="getting-started-step<?= $courseCount ? ' getting-started-step-done' : '' ?>">
      <div class="getting-started-step-head">
        <span class="getting-started-step-num">1</span>
        <h2>Create your first course</h2>
      </div>
      <p>Add a title, course code, and term. You can create an empty course or skip to import in step 2.</p>
      <a class="btn btn-sm" href="<?= url('admin/courses.php') ?>">Open Courses</a>
    </li>

    <li class="getting-started-step">
      <div class="getting-started-step-head">
        <span class="getting-started-step-num">2</span>
        <h2>Bring in your curriculum</h2>
      </div>
      <p>
        <strong>From Canvas:</strong> export an IMS Common Cartridge <code>.zip</code> from your old course, then import it here.<br>
        <strong>From a backup:</strong> use Import JSON if you have a YourLMS <code>open-lms-course-v1</code> export.<br>
        <strong>From scratch:</strong> build modules and pages under Teach → Modules.
      </p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <a class="btn btn-sm" href="<?= url('admin/import.php') ?>">Import IMS package</a>
        <a class="btn btn-sm btn-outline" href="<?= url('admin/import-json.php') ?>">Import JSON backup</a>
        <a class="btn btn-sm btn-outline" href="<?= url('admin/modules.php') ?>">Build modules</a>
      </div>
    </li>

    <li class="getting-started-step<?= $studentCount > 1 ? ' getting-started-step-done' : '' ?>">
      <div class="getting-started-step-head">
        <span class="getting-started-step-num">3</span>
        <h2>Add students</h2>
      </div>
      <p>Create accounts one at a time or upload a CSV under Teach → People. A demo student account already exists for testing.</p>
      <a class="btn btn-sm" href="<?= url('admin/people.php') ?>">Manage people</a>
    </li>

    <li class="getting-started-step">
      <div class="getting-started-step-head">
        <span class="getting-started-step-num">4</span>
        <h2>Publish for students</h2>
      </div>
      <p>
        Place assignments, quizzes, and discussions into a module, then click <strong>Go live</strong>.
        Publish the course and modules. Use <strong>Preview as student</strong> to double-check.
      </p>
      <a class="btn btn-sm btn-outline" href="<?= url('help.php?doc=publishing') ?>">Publishing guide</a>
      <a class="btn btn-sm btn-outline" href="<?= url('admin/assignments.php') ?>">Assignments</a>
    </li>

    <li class="getting-started-step">
      <div class="getting-started-step-head">
        <span class="getting-started-step-num">5</span>
        <h2>Optional: custom domain &amp; SSL</h2>
      </div>
      <p>YourLMS works on <code>localhost</code> out of the box. When you are ready to share it on the internet, add HTTPS and your own domain.</p>
      <a class="btn btn-sm btn-outline" href="<?= url('help.php?doc=ssl') ?>">SSL &amp; domain guide</a>
    </li>
  </ol>

  <div class="content-box" style="margin-top:28px;background:#f8fafc;">
    <h3 style="margin:0 0 8px;font-size:1rem;">Demo logins (change before going public)</h3>
    <p style="margin:0;font-size:14px;color:#475569;">
      Instructor: <code>instructor@yourlms.test</code> · Student: <code>student@yourlms.test</code> · Password: <code>password123</code>
    </p>
  </div>
</div>
<?php render_app_shell_end(); ?>