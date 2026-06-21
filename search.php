<?php
require __DIR__ . '/includes/bootstrap.php';
$user = require_login();

$q = trim($_GET['q'] ?? '');
$courseId = (int) ($_GET['course_id'] ?? 0);
$results = $q !== '' ? course_search($pdo, $user, $q, $courseId ?: null) : [];

$courses = courses_for_dashboard($pdo, $user);

render_head('Search');
render_app_shell_start($user, 'dashboard', '/dashboard.php');
render_page_header('Search', $courseId ? 'Course' : 'All courses');
?>
<div class="page-body" style="max-width:720px;">
  <form method="get" class="search-form">
    <?php if ($courseId): ?>
      <input type="hidden" name="course_id" value="<?= $courseId ?>">
    <?php endif; ?>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search assignments, quizzes, pages…" autofocus>
    <button class="btn" type="submit">Search</button>
  </form>

  <?php if (!$courseId && $courses): ?>
    <p style="font-size:13px;color:#71717a;margin:16px 0;">
      Or search within:
      <?php $slice = array_slice($courses, 0, 6); foreach ($slice as $i => $c): ?>
        <a href="<?= url('search.php?course_id=' . $c['id'] . '&q=' . urlencode($q)) ?>"><?= e($c['code']) ?></a><?= $i < count($slice) - 1 ? ' · ' : '' ?>
      <?php endforeach; ?>
    </p>
  <?php endif; ?>

  <?php if ($q !== ''): ?>
    <h2 class="section-title" style="margin-top:28px;"><?= count($results) ?> result<?= count($results) === 1 ? '' : 's' ?></h2>
    <div class="panel">
      <?php foreach ($results as $r): ?>
        <a class="panel-row search-result-row" href="<?= e($r['href']) ?>">
          <span class="search-result-kind"><?= e(ucfirst($r['kind'])) ?></span>
          <strong><?= e($r['title']) ?></strong>
          <span class="search-result-meta"><?= e($r['code']) ?></span>
        </a>
      <?php endforeach; ?>
      <?php if (!$results): ?>
        <div class="panel-row" style="color:#71717a;">No matches for “<?= e($q) ?>”.</div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php render_app_shell_end(); ?>