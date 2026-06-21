<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/lti.php';

$user = require_login();
$toolId = (int) ($_GET['tool_id'] ?? 0);
$courseId = (int) ($_GET['course_id'] ?? 0);
$course = require_course_access($pdo, $courseId, $user);

$stmt = $pdo->prepare('SELECT * FROM external_tools WHERE id = ? AND course_id = ?');
$stmt->execute([$toolId, $courseId]);
$tool = $stmt->fetch();
if (!$tool) {
    http_response_code(404);
    die('External tool not found.');
}

$launchUrl = $tool['launch_url'];
$returnUrl = url('course.php?id=' . $courseId);
$params = lti_sign_params(
    lti_build_launch_params($tool, $user, $course, $returnUrl),
    $launchUrl,
    $tool['consumer_key'],
    $tool['shared_secret']
);

render_head('Launch: ' . $tool['name']);
render_app_shell_start($user, 'courses', 'course.php?id=' . $courseId);
?>
<div class="page-body" style="max-width:720px;">
  <p>Launching <strong><?= e($tool['name']) ?></strong>…</p>
  <form id="lti-launch-form" method="post" action="<?= e($launchUrl) ?>" target="_blank">
    <?php foreach ($params as $k => $v): ?>
      <input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $v) ?>">
    <?php endforeach; ?>
    <button class="btn" type="submit">Open tool</button>
  </form>
</div>
<script>document.getElementById('lti-launch-form')?.submit();</script>
<?php render_app_shell_end(); ?>