<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
$user = require_login();

$calYear = (int) ($_GET['year'] ?? (int) date('Y'));
$calMonth = (int) ($_GET['month'] ?? (int) date('n'));
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

$monthList = [];
foreach ($calItems as $ymd => $items) {
    foreach ($items as $item) {
        $monthList[] = array_merge($item, ['ymd' => $ymd]);
    }
}
usort($monthList, fn($a, $b) => strcmp($a['due_at'], $b['due_at']));

render_head('Calendar');
render_app_shell_start($user, 'dashboard', '/dashboard.php');
render_page_header('Calendar', $monthLabel);
?>
<div class="page-body calendar-page">
  <div class="calendar-page-toolbar">
    <a class="btn btn-sm btn-outline" href="<?= url("calendar.php?year={$prevYear}&month={$prevMonth}") ?>">‹ <?= e(date('M', strtotime("{$prevYear}-{$prevMonth}-01"))) ?></a>
    <a class="btn btn-sm btn-outline" href="<?= url('calendar-export.php') ?>">Export iCal</a>
    <a class="btn btn-sm btn-outline" href="<?= url("calendar.php?year={$nextYear}&month={$nextMonth}") ?>"><?= e(date('M', strtotime("{$nextYear}-{$nextMonth}-01"))) ?> ›</a>
  </div>

  <div class="dashboard-calendar dashboard-calendar-full">
    <div class="dashboard-calendar-weekdays">
      <span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span>
    </div>
    <div class="dashboard-calendar-grid dashboard-calendar-grid-full">
      <?php for ($i = 0; $i < $firstWeekday; $i++): ?>
        <span class="dashboard-calendar-day dashboard-calendar-day-empty"></span>
      <?php endfor; ?>
      <?php for ($day = 1; $day <= $daysInMonth; $day++):
        $ymd = sprintf('%04d-%02d-%02d', $calYear, $calMonth, $day);
        $dayItems = $calItems[$ymd] ?? [];
        $isToday = $ymd === $todayYmd;
      ?>
        <div class="dashboard-calendar-day dashboard-calendar-day-full<?= $isToday ? ' dashboard-calendar-day-today' : '' ?>">
          <span class="dashboard-calendar-day-num"><?= $day ?></span>
          <?php foreach ($dayItems as $item):
            $href = ($item['item_kind'] ?? 'assignment') === 'quiz'
                ? url("quiz.php?course_id={$item['course_id']}&id={$item['id']}")
                : url("assignment.php?course_id={$item['course_id']}&id={$item['id']}");
            $kindLabel = ($item['item_kind'] ?? 'assignment') === 'quiz' ? 'Quiz' : 'Assignment';
          ?>
            <a class="calendar-page-item" href="<?= e($href) ?>" style="border-left-color:<?= e($item['color'] ?? '#3b82f6') ?>">
              <span class="calendar-page-item-time"><?= e(date('g:ia', strtotime($item['due_at']))) ?></span>
              <?= e($item['title']) ?>
              <span class="calendar-page-item-meta"><?= e($item['code']) ?> · <?= e($kindLabel) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endfor; ?>
    </div>
  </div>

  <?php if ($monthList): ?>
    <h2 class="section-title spaced">Due this month</h2>
    <div class="panel">
      <?php foreach ($monthList as $item):
        $href = ($item['item_kind'] ?? 'assignment') === 'quiz'
            ? url("quiz.php?course_id={$item['course_id']}&id={$item['id']}")
            : url("assignment.php?course_id={$item['course_id']}&id={$item['id']}");
      ?>
        <a class="panel-row search-result-row" href="<?= e($href) ?>">
          <span class="search-result-kind"><?= e(date('M j', strtotime($item['due_at']))) ?></span>
          <strong><?= e($item['title']) ?></strong>
          <span class="search-result-meta"><?= e($item['code']) ?> · <?= e(format_datetime($item['due_at'])) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php render_app_shell_end(); ?>