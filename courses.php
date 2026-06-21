<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$courses = courses_for_dashboard($pdo, $user);
$isSiteInstructor = user_is_site_instructor($user);

render_head('Courses');
render_app_shell_start($user, 'courses', '/dashboard.php');
?>
<?php render_page_header('All Courses', null); ?>
<div class="page-body">
  <div class="panel">
    <table class="data-table">
      <thead>
        <tr><th>Code</th><th>Course</th><th>Term</th><?php if ($isSiteInstructor): ?><th>Status</th><?php endif; ?></tr>
      </thead>
      <tbody>
        <?php foreach ($courses as $c): ?>
          <tr>
            <td style="font-family:'JetBrains Mono',monospace;font-size:12px;"><?= e($c['code']) ?></td>
            <td><a href="<?= url('course.php?id=' . $c['id']) ?>"><strong><?= e($c['name']) ?></strong></a></td>
            <td style="color:#71717a;"><?= e($c['term'] ?? '') ?></td>
            <?php if ($isSiteInstructor): ?>
              <td><?= course_is_published($c) ? 'Published' : 'Unpublished' ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php render_app_shell_end(); ?>