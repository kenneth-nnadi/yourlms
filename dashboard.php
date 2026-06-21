<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$courses = courses_for_dashboard($pdo, $user);
$isSiteInstructor = user_is_site_instructor($user);

$upcoming = dashboard_todo_items($pdo, $user, 10);

$announcements = announcements_for_user($pdo, $user, 5);

$calYear = (int) ($_GET['cal_year'] ?? (int) date('Y'));
$calMonth = (int) ($_GET['cal_month'] ?? (int) date('n'));
if ($calMonth < 1 || $calMonth > 12) {
    $calMonth = (int) date('n');
}
$calItems = dashboard_calendar_items($pdo, $user, $calYear, $calMonth);
$daysInMonth = (int) date('t', strtotime(sprintf('%04d-%02d-01', $calYear, $calMonth)));
$firstWeekday = (int) date('w', strtotime(sprintf('%04d-%02d-01', $calYear, $calMonth)));
$prevMonth = $calMonth === 1 ? 12 : $calMonth - 1;
$prevYear = $calMonth === 1 ? $calYear - 1 : $calYear;
$nextMonth = $calMonth === 12 ? 1 : $calMonth + 1;
$nextYear = $calMonth === 12 ? $calYear + 1 : $calYear;
$monthLabel = date('F Y', strtotime(sprintf('%04d-%02d-01', $calYear, $calMonth)));
$todayYmd = date('Y-m-d');

$firstName = explode(' ', $user['full_name'])[0];

render_head('Dashboard');
render_app_shell_start($user, 'dashboard', '/courses.php');
?>
<div class="dashboard-layout">
  <div class="dashboard-main">
    <?php render_page_header('Welcome back, ' . $firstName . '.', 'Dashboard'); ?>
    <div class="page-body">
      <?php
      $totalCourses = (int) $pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn();
      if ($isSiteInstructor && $totalCourses === 0):
      ?>
        <div class="getting-started-banner">
          <div>
            <strong>Welcome to YourLMS</strong>
            <p>Your install is a clean slate. Follow the getting-started guide to import a Canvas course or build your first class.</p>
          </div>
          <a class="btn" href="<?= url('getting-started.php') ?>">Getting started</a>
        </div>
      <?php endif; ?>
      <h2 class="section-title"><?= $isSiteInstructor ? 'Courses' : 'Published Courses' ?></h2>
      <div class="course-grid">
        <?php foreach ($courses as $c):
          if (!$isSiteInstructor && !course_is_published($c)) {
              continue;
          }
        ?>
          <a class="course-card <?= $isSiteInstructor && !course_is_published($c) ? 'course-card-unpublished' : '' ?>" href="<?= url('course.php?id=' . $c['id']) ?>">
            <div class="course-card-banner" style="background:<?= e($c['color']) ?>"></div>
            <div class="course-card-body">
              <div class="course-card-code">
                <?= e($c['code']) ?> · <?= e($c['term'] ?? '') ?>
                <?php if ($isSiteInstructor && !course_is_published($c)): ?>
                  <span class="unpublished-label">unpublished</span>
                <?php endif; ?>
              </div>
              <h3><?= e($c['name']) ?></h3>
              <p><?= e($c['description'] ?? '') ?></p>
            </div>
          </a>
        <?php endforeach; ?>
        <?php if (!$courses): ?>
          <div style="grid-column:1/-1;">
            <?php
            $dashActions = $isSiteInstructor
                ? [
                    ['label' => 'Create a course', 'href' => url('admin/courses.php'), 'primary' => true],
                    ['label' => 'Import IMS package', 'href' => url('admin/import.php')],
                ]
                : [['label' => 'Browse courses', 'href' => url('courses.php')]];
            render_empty_state('No courses yet.', $isSiteInstructor ? 'Create one or import a package to begin.' : 'You are not enrolled in any courses yet.', $dashActions);
            ?>
          </div>
        <?php endif; ?>
      </div>

      <h2 class="section-title spaced">Recent Announcements</h2>
      <div class="panel dashboard-announcements">
        <?php foreach ($announcements as $a):
          $annHref = url(sprintf(
              'announcements.php?course_id=%d&id=%d',
              (int) $a['course_id'],
              (int) $a['id']
          ));
          $excerpt = rich_content_excerpt($a['body'], $a['body_format'] ?? 'text');
        ?>
          <a class="panel-row dashboard-announcement-row" href="<?= e($annHref) ?>">
            <div class="dashboard-announcement-meta">
              <span class="dashboard-announcement-course"><?= e($a['code']) ?></span>
              <span class="post-time"><?= e(time_ago($a['created_at'])) ?></span>
            </div>
            <h3 class="dashboard-announcement-title"><?= e($a['title']) ?></h3>
            <?php if ($excerpt): ?>
              <p class="dashboard-announcement-excerpt"><?= e($excerpt) ?></p>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
        <?php if (!$announcements): ?>
          <div class="panel-row" style="text-align:center;color:#71717a;">No announcements.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <aside class="dashboard-aside">
    <h4 class="aside-heading">To Do</h4>
    <?php foreach ($upcoming as $item):
      $href = ($item['item_kind'] ?? 'assignment') === 'quiz'
          ? url("quiz.php?course_id={$item['course_id']}&id={$item['id']}")
          : url("assignment.php?course_id={$item['course_id']}&id={$item['id']}");
      $kindLabel = ($item['item_kind'] ?? 'assignment') === 'quiz' ? 'Quiz' : 'Assignment';
    ?>
      <a class="todo-item" href="<?= e($href) ?>">
        <span class="todo-dot"></span>
        <div>
          <div class="todo-title"><?= e($item['title']) ?></div>
          <div class="todo-meta"><?= e($item['code']) ?> · <?= e($kindLabel) ?> · <?= e((string)$item['points']) ?> pts · <?= e(format_datetime($item['due_at'])) ?></div>
        </div>
      </a>
    <?php endforeach; ?>
    <?php if (!$upcoming): ?>
      <div style="font-size:12px;color:#71717a;">Nothing due. Nice.</div>
    <?php endif; ?>

    <div class="dashboard-calendar">
      <div class="dashboard-calendar-header">
        <a class="dashboard-calendar-nav" href="<?= url("dashboard.php?cal_year={$prevYear}&cal_month={$prevMonth}") ?>" aria-label="Previous month">‹</a>
        <a class="dashboard-calendar-title" href="<?= url('calendar.php') ?>" style="text-decoration:none;color:inherit;"><?= e($monthLabel) ?></a>
        <a class="dashboard-calendar-nav" href="<?= url("dashboard.php?cal_year={$nextYear}&cal_month={$nextMonth}") ?>" aria-label="Next month">›</a>
      </div>
      <p style="font-size:11px;margin:0 0 8px;"><a href="<?= url('calendar.php') ?>">Open full calendar</a> · <a href="<?= url('calendar-export.php') ?>">iCal</a></p>
      <div class="dashboard-calendar-weekdays">
        <span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span>
      </div>
      <div class="dashboard-calendar-grid">
        <?php for ($i = 0; $i < $firstWeekday; $i++): ?>
          <span class="dashboard-calendar-day dashboard-calendar-day-empty"></span>
        <?php endfor; ?>
        <?php for ($day = 1; $day <= $daysInMonth; $day++):
          $ymd = sprintf('%04d-%02d-%02d', $calYear, $calMonth, $day);
          $dayItems = $calItems[$ymd] ?? [];
          $isToday = $ymd === $todayYmd;
        ?>
          <div class="dashboard-calendar-day<?= $isToday ? ' dashboard-calendar-day-today' : '' ?><?= $dayItems ? ' dashboard-calendar-day-has-items' : '' ?>">
            <span class="dashboard-calendar-day-num"><?= $day ?></span>
            <?php if ($dayItems): ?>
              <div class="dashboard-calendar-dots">
                <?php foreach (array_slice($dayItems, 0, 3) as $item):
                  $href = ($item['item_kind'] ?? 'assignment') === 'quiz'
                      ? url("quiz.php?course_id={$item['course_id']}&id={$item['id']}")
                      : url("assignment.php?course_id={$item['course_id']}&id={$item['id']}");
                ?>
                  <a class="dashboard-calendar-dot" href="<?= e($href) ?>" title="<?= e($item['title']) ?>" style="background:<?= e($item['color'] ?? '#3b82f6') ?>"></a>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        <?php endfor; ?>
      </div>
      <?php
      $monthList = [];
      foreach ($calItems as $ymd => $items) {
          foreach ($items as $item) {
              $monthList[] = array_merge($item, ['ymd' => $ymd]);
          }
      }
      usort($monthList, fn($a, $b) => strcmp($a['due_at'], $b['due_at']));
      ?>
      <?php if ($monthList): ?>
        <ul class="dashboard-calendar-list">
          <?php foreach (array_slice($monthList, 0, 6) as $item):
            $href = ($item['item_kind'] ?? 'assignment') === 'quiz'
                ? url("quiz.php?course_id={$item['course_id']}&id={$item['id']}")
                : url("assignment.php?course_id={$item['course_id']}&id={$item['id']}");
            $kindLabel = ($item['item_kind'] ?? 'assignment') === 'quiz' ? 'Quiz' : 'Assignment';
          ?>
            <li>
              <a href="<?= e($href) ?>">
                <span class="dashboard-calendar-list-date"><?= e(date('M j', strtotime($item['due_at']))) ?></span>
                <?= e($item['title']) ?>
                <span class="dashboard-calendar-list-meta"><?= e($item['code']) ?> · <?= e($kindLabel) ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </aside>
</div>
<?php
render_app_shell_end();