<?php
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/admin_helpers.php';
$user = require_teach_access($pdo);

$teachCourses = teach_admin_courses($pdo, $user);
$hubCourseId = (int) ($teachCourses[0]['id'] ?? 0);

$courses = $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn();
$users = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$students = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();

render_head('Teach');
render_app_shell_start($user, 'admin', '/dashboard.php');
?>
<?php render_page_header('Teaching tools', 'Instructor'); ?>
<div class="page-body">
  <p style="color:#71717a;margin-top:0;">Manage courses, people, content, and publishing for <?= e(config()['app_name']) ?>.</p>
  <?php if ((int) $courses === 0): ?>
    <div class="getting-started-banner" style="margin-bottom:24px;">
      <div>
        <strong>New install?</strong>
        <p>Start with the step-by-step guide — import IMS from Canvas or build modules from scratch.</p>
      </div>
      <a class="btn btn-sm" href="<?= url('getting-started.php') ?>">Getting started</a>
    </div>
  <?php endif; ?>
  <div style="display:flex;gap:24px;margin-bottom:32px;font-size:14px;">
    <div><strong><?= (int)$courses ?></strong> courses</div>
    <div><strong><?= (int)$users ?></strong> users</div>
    <div><strong><?= (int)$students ?></strong> students</div>
  </div>
  <div class="admin-grid">
    <a class="admin-card" href="<?= url('admin/courses.php') ?>">
      <h3>Courses</h3><p>Create, edit, publish, and duplicate courses.</p>
    </a>
    <a class="admin-card" href="<?= url('admin/modules.php' . ($hubCourseId ? '?course_id=' . $hubCourseId : '')) ?>">
      <h3>Modules</h3><p>Edit, reorder, add, or delete modules and pages in your courses.</p>
    </a>
    <a class="admin-card" href="<?= url('admin/assignments.php') ?>">
      <h3>Assignments</h3><p>Create assignments with due dates and points.</p>
    </a>
    <a class="admin-card" href="<?= url('admin/quizzes.php') ?>">
      <h3>Quizzes</h3><p>Build multiple-choice quizzes with auto-grading.</p>
    </a>
    <a class="admin-card" href="<?= url('admin/discussions.php') ?>">
      <h3>Discussions</h3><p>Create discussion prompts for class engagement.</p>
    </a>
    <a class="admin-card" href="<?= url('admin/people.php') ?>">
      <h3>People</h3><p>Enroll users one-by-one or via CSV; create accounts as you add them.</p>
    </a>
    <a class="admin-card" href="<?= url('admin/import.php') ?>">
      <h3>Import IMS</h3><p>Upload an IMS Common Cartridge <code>.zip</code> into a new or existing course.</p>
    </a>
    <a class="admin-card" href="<?= url('admin/import-json.php') ?>">
      <h3>Import JSON</h3><p>Restore a round-trip <code>open-lms-course-v1</code> backup (.zip or .json).</p>
    </a>
    <a class="admin-card" href="<?= url('admin/export.php') ?>">
      <h3>Export course</h3><p>Download a ZIP backup with course files, or JSON metadata only.</p>
    </a>
    <a class="admin-card" href="<?= url('admin/backup.php') ?>">
      <h3>Full backup</h3><p>Database dump + uploads folder for disaster recovery.</p>
    </a>
    <a class="admin-card" href="<?= url('admin/rubrics.php' . ($hubCourseId ? '?course_id=' . $hubCourseId : '')) ?>">
      <h3>Rubrics</h3><p>Criteria-based grading for assignments.</p>
    </a>
    <a class="admin-card" href="<?= url('admin/comment-bank.php') ?>">
      <h3>Comment bank</h3><p>Reusable feedback snippets when grading.</p>
    </a>
    <a class="admin-card" href="<?= url('admin/lti-tools.php' . ($hubCourseId ? '?course_id=' . $hubCourseId : '')) ?>">
      <h3>External tools</h3><p>LTI 1.0 launches for third-party apps.</p>
    </a>
    <a class="admin-card" href="<?= url('admin/api-tokens.php') ?>">
      <h3>API tokens</h3><p>REST API access for courses, students, and grades.</p>
    </a>
    <a class="admin-card" href="<?= url('admin/groups.php' . ($hubCourseId ? '?course_id=' . $hubCourseId : '')) ?>">
      <h3>Assignment groups</h3><p>Set weighted grade categories for assignments, quizzes, and discussions.</p>
    </a>
    <?php if ($hubCourseId): ?>
      <a class="admin-card" href="<?= url("gradebook.php?course_id={$hubCourseId}") ?>">
        <h3>Gradebook</h3><p>View all students and gradeable items for <?= e($teachCourses[0]['code']) ?>.</p>
      </a>
      <a class="admin-card" href="<?= url("files.php?course_id={$hubCourseId}") ?>">
        <h3>Course files</h3><p>Upload and manage files for <?= e($teachCourses[0]['code']) ?>.</p>
      </a>
      <a class="admin-card" href="<?= url("announcements.php?course_id={$hubCourseId}") ?>">
        <h3>Announcements</h3><p>Post and publish announcements for <?= e($teachCourses[0]['code']) ?>.</p>
      </a>
    <?php endif; ?>
  </div>
</div>
<?php render_app_shell_end(); ?>