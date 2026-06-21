<?php
declare(strict_types=1);

function render_head(string $title): void
{
    $app = config()['app_name'];
    $darkEnabled = app_theme()['enable_dark_mode'] ? '1' : '0';
    echo '<!DOCTYPE html><html lang="en" data-dark-enabled="' . $darkEnabled . '"><head>';
    echo '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="color-scheme" content="light dark">';
    echo '<title>' . e($title) . ' · ' . e($app) . '</title>';
    echo '<link rel="stylesheet" href="' . url('assets/css/style.css') . '">';
    render_favicon_link();
    render_brand_theme();
    echo '</head><body>';
    echo '<a class="skip-link" href="#main-content">Skip to main content</a>';
}

function render_empty_state(string $title, string $description = '', array $actions = []): void
{
    echo '<div class="empty-state">';
    echo '<p class="empty-state-title">' . e($title) . '</p>';
    if ($description !== '') {
        echo '<p class="empty-state-desc">' . e($description) . '</p>';
    }
    if ($actions) {
        echo '<div class="empty-state-actions">';
        foreach ($actions as $action) {
            $primary = !empty($action['primary']);
            $cls = $primary ? 'btn' : 'btn btn-outline';
            echo '<a class="' . $cls . '" href="' . e($action['href']) . '">' . e($action['label']) . '</a>';
        }
        echo '</div>';
    }
    echo '</div>';
}

function render_flash(): void
{
    foreach (['success', 'error'] as $type) {
        $msg = flash($type);
        if ($msg) {
            echo '<div class="flash flash-' . $type . '">' . e($msg) . '</div>';
        }
    }
}

function render_back_button(string $fallbackPath, string $label = 'Back'): void
{
    $href = str_starts_with($fallbackPath, 'http') ? $fallbackPath : url($fallbackPath);
    echo '<button type="button" class="back-btn" data-fallback="' . e($href) . '" onclick="appGoBack(this)" aria-label="' . e($label) . '">';
    echo '<span class="back-btn-icon" aria-hidden="true">←</span>';
    echo '<span class="back-btn-label">' . e($label) . '</span>';
    echo '</button>';
}

function render_page_header(string $title, ?string $eyebrow = null): void
{
    echo '<header class="page-header">';
    if ($eyebrow) {
        echo '<div class="eyebrow">' . e($eyebrow) . '</div>';
    }
    echo '<h1>' . e($title) . '</h1>';
    echo '</header>';
}

function render_app_shell_start(array $user, string $active = 'dashboard', string $backFallback = '/dashboard.php'): void
{
    global $pdo;
    require_once __DIR__ . '/notifications.php';
    $nav = [
        'dashboard' => ['label' => 'Dashboard', 'href' => url('dashboard.php'), 'icon' => '▦'],
        'courses' => ['label' => 'Courses', 'href' => url('courses.php'), 'icon' => '☰'],
        'calendar' => ['label' => 'Calendar', 'href' => url('calendar.php'), 'icon' => '📅'],
        'search' => ['label' => 'Search', 'href' => url('search.php'), 'icon' => '⌕'],
        'admin' => ['label' => 'Teach', 'href' => url('admin/index.php'), 'icon' => '✎'],
    ];
    echo '<div class="app-shell">';
    echo '<header class="site-header">';
    echo '<div class="site-header-inner">';
    echo '<a class="site-brand" href="' . url('dashboard.php') . '">';
    render_brand_mark();
    echo '<span class="site-brand-name">' . e(config()['app_name']) . '</span></a>';
    render_notification_bell($pdo, (int) $user['id'], 'site-header-mobile-bell');
    echo '<button type="button" class="site-menu-toggle" id="site-menu-toggle" aria-controls="site-menu" aria-expanded="false" aria-label="Open menu">';
    echo '<span class="site-menu-toggle-icon" aria-hidden="true"><span></span><span></span><span></span></span>';
    echo '</button>';
    echo '<div class="site-menu-backdrop" id="site-menu-backdrop" hidden></div>';
    echo '<div class="site-menu" id="site-menu">';
    echo '<nav class="site-nav" aria-label="Main">';
    foreach ($nav as $key => $item) {
        if ($key === 'admin' && !user_can_access_teach_menu($pdo, $user)) {
            continue;
        }
        $cls = $active === $key ? 'site-nav-link active' : 'site-nav-link';
        $aria = $active === $key ? ' aria-current="page"' : '';
        echo '<a class="' . $cls . '" href="' . $item['href'] . '"' . $aria . '>' . e($item['label']) . '</a>';
    }
    echo '</nav>';
    echo '<div class="site-header-actions">';
    if (app_theme()['enable_dark_mode']) {
        echo '<button type="button" class="theme-toggle" aria-label="Toggle dark mode" title="Toggle theme"><span aria-hidden="true">◐</span></button>';
    }
    render_back_button($backFallback);
    render_notification_bell($pdo, (int) $user['id']);
    echo '<a class="account-profile-link" href="' . url('profile.php') . '">' . e($user['full_name']) . '</a>';
    echo '<span class="account-role">' . e(account_role_label($user['role'])) . '</span>';
    echo '<a class="btn btn-sm account-logout-btn" href="' . url('logout.php') . '">Log out</a>';
    echo '</div></div></div></header>';
    echo '<div class="app-main" id="main-content" tabindex="-1">';
    render_flash();
}

function render_auth_shell_start(string $backFallback = '/login.php'): void
{
    echo '<header class="auth-topbar">';
    echo '<div class="auth-topbar-left">';
    render_back_button($backFallback);
    echo '<a class="auth-topbar-brand" href="' . url('login.php') . '">';
    render_brand_mark('auth-brand-mark');
    echo '<span>' . e(config()['app_name']) . '</span></a>';
    echo '</div>';
    echo '<a class="btn btn-sm" href="' . url('login.php') . '">Log in</a>';
    echo '</header>';
}

function render_back_script(): void
{
    render_csrf_script();
    echo '<script>
(function () {
  var root = document.documentElement;
  if (root.dataset.darkEnabled !== "1") return;
  var key = "lms-theme";
  var stored = localStorage.getItem(key);
  var prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
  if (stored === "dark" || (!stored && prefersDark)) {
    root.setAttribute("data-theme", "dark");
  }
  document.querySelectorAll(".theme-toggle").forEach(function (btn) {
    btn.addEventListener("click", function () {
      var isDark = root.getAttribute("data-theme") === "dark";
      if (isDark) {
        root.removeAttribute("data-theme");
        localStorage.setItem(key, "light");
      } else {
        root.setAttribute("data-theme", "dark");
        localStorage.setItem(key, "dark");
      }
    });
  });
})();
function appGoBack(btn) {
  const fallback = btn && btn.dataset ? btn.dataset.fallback : null;
  try {
    const ref = document.referrer;
    if (ref) {
      const refUrl = new URL(ref);
      if (refUrl.origin === window.location.origin && refUrl.href !== window.location.href) {
        history.back();
        return;
      }
    }
  } catch (e) {}
  if (fallback) {
    window.location.href = fallback;
    return;
  }
  if (window.history.length > 1) {
    history.back();
  }
}
</script>';
}

function render_auth_shell_end(): void
{
    render_back_script();
}

function render_app_shell_end(): void
{
    render_back_script();
    echo '<script src="' . url('assets/js/a11y.js') . '"></script>';
    echo '</div></div></body></html>';
}

function render_course_shell_start(array $course, string $active, int $courseId, ?array $user = null): void
{
    global $pdo;
    if ($user === null) {
        $user = current_user() ?? [];
    }
    $base = url("course.php?id={$courseId}");
    $tabs = [
        'home' => ['label' => 'Home', 'href' => $base],
        'assignments' => ['label' => 'Assignments', 'href' => url("assignments.php?course_id={$courseId}")],
        'quizzes' => ['label' => 'Quizzes', 'href' => url("quizzes.php?course_id={$courseId}")],
        'discussions' => ['label' => 'Discussions', 'href' => url("discussions.php?course_id={$courseId}")],
        'announcements' => ['label' => 'Announcements', 'href' => url("announcements.php?course_id={$courseId}")],
        'grades' => ['label' => 'Grades', 'href' => url("grades.php?course_id={$courseId}")],
        'files' => ['label' => 'Files', 'href' => url("files.php?course_id={$courseId}")],
        'tools' => ['label' => 'Tools', 'href' => url("tools.php?course_id={$courseId}")],
    ];
    if ($user && user_can_grade($pdo, $courseId, $user)) {
        $tabs['gradebook'] = ['label' => 'Gradebook', 'href' => url("gradebook.php?course_id={$courseId}")];
    }

    echo '<div class="course-layout" id="course-layout">';
    echo '<button type="button" class="course-nav-toggle" id="course-nav-toggle" aria-controls="course-sidebar" aria-expanded="false" aria-label="Open course menu"><span aria-hidden="true">☰</span> Menu</button>';
    echo '<div class="course-nav-backdrop" id="course-nav-backdrop" hidden></div>';
    echo '<aside class="course-sidebar" id="course-sidebar">';
    echo '<div class="course-meta"><div class="course-code">' . e($course['code']) . '</div>';
    echo '<h1 class="course-title">' . e($course['name']) . '</h1>';
    if ($course['term']) {
        echo '<div class="course-term">' . e($course['term']) . '</div>';
    }
    echo '</div><nav class="course-tabs" aria-label="' . e($course['name']) . ' sections">';
    foreach ($tabs as $key => $tab) {
        $cls = $active === $key ? 'course-tab active' : 'course-tab';
        $aria = $active === $key ? ' aria-current="page"' : '';
        echo '<a class="' . $cls . '" href="' . $tab['href'] . '"' . $aria . '>' . e($tab['label']) . '</a>';
    }
    echo '</nav></aside><main class="course-content" role="main">';
    render_student_preview_banner($courseId);
}

function render_course_shell_end(): void
{
    echo '</main></div>';
}

function render_course_header(string $title, string $right = ''): void
{
    echo '<header class="course-header">';
    echo '<div class="course-header-title">' . e($title) . '</div>';
    if ($right) {
        echo '<div class="course-header-right">' . $right . '</div>';
    }
    echo '</header>';
}