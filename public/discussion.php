<?php
require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/notifications.php';
$user = require_login();

$courseId = (int) ($_GET['course_id'] ?? 0);
$discussionId = (int) ($_GET['id'] ?? 0);
$replyTo = (int) ($_GET['reply_to'] ?? 0);
$course = require_course_access($pdo, $courseId, $user);

$stmt = $pdo->prepare('SELECT * FROM discussions WHERE id = ? AND course_id = ?');
$stmt->execute([$discussionId, $courseId]);
$discussion = $stmt->fetch();
if (!$discussion) {
    http_response_code(404);
    die('Discussion not found.');
}
require_published_ref_access($pdo, $courseId, $user, 'discussion', $discussionId);

$canParticipate = user_can_participate($pdo, $courseId, $user);
$canModerate = user_can_manage_course_as_staff($pdo, $courseId, $user);
$editPostId = (int) ($_GET['edit_post'] ?? 0);

function discussion_post_editable(array $post, int $discussionId, array $user, bool $canModerate): bool
{
    return (int) $post['discussion_id'] === $discussionId
        && ((int) $post['user_id'] === (int) $user['id'] || $canModerate);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_post_id'])) {
        $postId = (int) $_POST['delete_post_id'];
        $stmt = $pdo->prepare('SELECT * FROM discussion_posts WHERE id = ? AND discussion_id = ?');
        $stmt->execute([$postId, $discussionId]);
        $post = $stmt->fetch();
        if ($post && discussion_post_editable($post, $discussionId, $user, $canModerate)) {
            $newParent = $post['parent_id'] ? (int) $post['parent_id'] : null;
            $pdo->prepare('UPDATE discussion_posts SET parent_id = ? WHERE parent_id = ? AND discussion_id = ?')
                ->execute([$newParent, $postId, $discussionId]);
            $pdo->prepare('DELETE FROM discussion_posts WHERE id = ?')->execute([$postId]);
            flash('success', 'Post deleted.');
        }
        redirect("discussion.php?course_id={$courseId}&id={$discussionId}");
    }

    if (isset($_POST['update_post_id'])) {
        $postId = (int) $_POST['update_post_id'];
        $content = trim($_POST['content'] ?? '');
        $stmt = $pdo->prepare('SELECT * FROM discussion_posts WHERE id = ? AND discussion_id = ?');
        $stmt->execute([$postId, $discussionId]);
        $post = $stmt->fetch();
        if ($post && discussion_post_editable($post, $discussionId, $user, $canModerate) && $content !== '') {
            $pdo->prepare('UPDATE discussion_posts SET content = ? WHERE id = ?')->execute([$content, $postId]);
            flash('success', 'Post updated.');
        }
        redirect("discussion.php?course_id={$courseId}&id={$discussionId}");
    }

    if ($canModerate && isset($_POST['grade_discussion'])) {
        $studentId = (int) ($_POST['student_id'] ?? 0);
        $points = $_POST['points'] !== '' ? (float) $_POST['points'] : null;
        $feedback = trim($_POST['feedback'] ?? '');
        $pdo->prepare(sql_discussion_grade_upsert())->execute([$discussionId, $studentId, $points, $feedback ?: null]);
        if ($points !== null) {
            notify_user(
                $pdo,
                $studentId,
                $courseId,
                'grade',
                'Discussion graded: ' . $discussion['title'],
                'You received ' . number_format($points, 1) . ' / ' . ($discussion['points'] ?? 0) . ' points.',
                "discussion.php?course_id={$courseId}&id={$discussionId}",
                true
            );
        }
        flash('success', 'Grade saved.');
        redirect("discussion.php?course_id={$courseId}&id={$discussionId}");
    }

    if ($canParticipate) {
        $content = trim($_POST['content'] ?? '');
        $parentId = ($_POST['parent_id'] ?? '') !== '' ? (int) $_POST['parent_id'] : null;
        if ($content !== '') {
            if ($parentId) {
                $check = $pdo->prepare('SELECT id FROM discussion_posts WHERE id = ? AND discussion_id = ?');
                $check->execute([$parentId, $discussionId]);
                if (!$check->fetch()) {
                    $parentId = null;
                }
            }
            $parentAuthorId = null;
            if ($parentId) {
                $parentStmt = $pdo->prepare('SELECT user_id FROM discussion_posts WHERE id = ? AND discussion_id = ?');
                $parentStmt->execute([$parentId, $discussionId]);
                $parentAuthorId = $parentStmt->fetchColumn();
                $parentAuthorId = $parentAuthorId !== false ? (int) $parentAuthorId : null;
            }
            $pdo->prepare('INSERT INTO discussion_posts (discussion_id, user_id, parent_id, content) VALUES (?, ?, ?, ?)')
                ->execute([$discussionId, $user['id'], $parentId, $content]);
            $newPostId = (int) $pdo->lastInsertId();
            notify_discussion_activity(
                $pdo,
                $courseId,
                $discussion,
                $user,
                $content,
                $parentId,
                $parentAuthorId
            );
            flash('success', $parentId ? 'Reply posted.' : 'Posted.');
            $hash = $newPostId > 0 ? '#discussion-post-' . $newPostId : '';
            redirect("discussion.php?course_id={$courseId}&id={$discussionId}{$hash}");
        }
        redirect("discussion.php?course_id={$courseId}&id={$discussionId}");
    }
}

$posts = $pdo->prepare(
    'SELECT p.*, u.full_name FROM discussion_posts p
     JOIN users u ON u.id = p.user_id
     WHERE p.discussion_id = ? ORDER BY p.created_at ASC'
);
$posts->execute([$discussionId]);
$posts = $posts->fetchAll();
$tree = discussion_build_tree($posts);
$postCount = count($posts);

$postById = [];
foreach ($posts as $p) {
    $postById[(int) $p['id']] = $p;
}
$editingPost = $editPostId && isset($postById[$editPostId]) ? $postById[$editPostId] : null;
if ($editingPost && !discussion_post_editable($editingPost, $discussionId, $user, $canModerate)) {
    $editingPost = null;
}

$renderPosts = static function (array $nodes, ?array $parentPost = null, bool $nested = false) use (
    &$renderPosts,
    $courseId,
    $discussionId,
    $canParticipate,
    $replyTo,
    $editPostId,
    $user,
    $canModerate,
    $postById
): void {
    $listClass = 'discussion-thread-list' . ($nested ? ' discussion-thread-list-nested' : '');
    echo '<ul class="' . $listClass . '">';
    foreach ($nodes as $p) {
        $pid = (int) $p['id'];
        $canEditPost = discussion_post_editable($p, $discussionId, $user, $canModerate);
        $replyParent = $parentPost;
        if (!$replyParent && !empty($p['parent_id'])) {
            $replyParent = $postById[(int) $p['parent_id']] ?? null;
        }
        $itemClass = 'discussion-thread-item' . ($nested ? ' discussion-thread-item-reply' : '');
        echo '<li class="' . $itemClass . '" id="discussion-post-' . $pid . '">';
        echo '<article class="post-card discussion-thread-post">';
        if ($replyParent) {
            $parentPostId = (int) $replyParent['id'];
            echo '<a class="discussion-reply-to" href="#discussion-post-' . $parentPostId . '">';
            echo '<span class="discussion-reply-to-icon" aria-hidden="true">↩</span>';
            echo '<span>Replying to <strong>' . e($replyParent['full_name']) . '</strong></span>';
            echo '</a>';
        }
        echo '<div class="discussion-post-head">';
        echo '<span class="post-author">' . e($p['full_name']) . '</span>';
        echo '<span class="post-time">' . e(format_datetime($p['created_at'])) . '</span>';
        echo '</div>';
        if ($editPostId === $pid && $canEditPost) {
            echo '<form method="post" class="discussion-inline-reply">';
            echo '<input type="hidden" name="update_post_id" value="' . $pid . '">';
            echo '<textarea name="content" rows="4" required autofocus>' . e($p['content']) . '</textarea>';
            echo '<div class="discussion-inline-reply-actions">';
            echo '<button class="btn btn-sm" type="submit">Save</button>';
            echo '<a class="btn btn-sm btn-outline" href="' . url("discussion.php?course_id={$courseId}&id={$discussionId}") . '">Cancel</a>';
            echo '</div></form>';
        } else {
            echo '<div class="post-body">';
            render_rich_content($p['content'], 'text');
            echo '</div>';
            echo '<div class="discussion-post-actions">';
            if ($canParticipate) {
                echo '<a class="discussion-reply-link" href="' . url("discussion.php?course_id={$courseId}&id={$discussionId}&reply_to={$pid}") . '">Reply</a>';
            }
            if ($canEditPost) {
                echo '<a class="discussion-reply-link" href="' . url("discussion.php?course_id={$courseId}&id={$discussionId}&edit_post={$pid}") . '">Edit</a>';
                echo '<form method="post" class="discussion-delete-form" onsubmit="return confirm(\'Delete this post?\');">';
                echo '<input type="hidden" name="delete_post_id" value="' . $pid . '">';
                echo '<button type="submit" class="discussion-reply-link discussion-delete-btn">Delete</button>';
                echo '</form>';
            }
            echo '</div>';
        }
        if ($replyTo === $pid && $canParticipate) {
            echo '<form method="post" class="discussion-inline-reply">';
            echo '<input type="hidden" name="parent_id" value="' . $pid . '">';
            echo '<textarea name="content" rows="3" placeholder="Write a reply…" required autofocus></textarea>';
            echo '<div class="discussion-inline-reply-actions">';
            echo '<button class="btn btn-sm" type="submit">Post reply</button>';
            echo '<a class="btn btn-sm btn-outline" href="' . url("discussion.php?course_id={$courseId}&id={$discussionId}") . '">Cancel</a>';
            echo '</div></form>';
        }
        echo '</article>';
        if (!empty($p['children'])) {
            $renderPosts($p['children'], $p, true);
        }
        echo '</li>';
    }
    echo '</ul>';
};

$myGrade = null;
$gradeParticipants = [];
if (!empty($discussion['points'])) {
    $g = $pdo->prepare('SELECT * FROM discussion_grades WHERE discussion_id = ? AND user_id = ?');
    $g->execute([$discussionId, $user['id']]);
    $myGrade = $g->fetch() ?: null;
    if ($canModerate) {
        $gp = $pdo->prepare(
            'SELECT DISTINCT u.id, u.full_name FROM users u
             JOIN discussion_posts p ON p.user_id = u.id
             WHERE p.discussion_id = ? ORDER BY u.full_name'
        );
        $gp->execute([$discussionId]);
        $gradeParticipants = $gp->fetchAll();
        $existing = $pdo->prepare('SELECT * FROM discussion_grades WHERE discussion_id = ?');
        $existing->execute([$discussionId]);
        $gradeByUser = [];
        foreach ($existing->fetchAll() as $row) {
            $gradeByUser[(int) $row['user_id']] = $row;
        }
    }
}

render_head($discussion['title']);
render_app_shell_start($user, 'courses', "discussions.php?course_id={$courseId}");
render_course_shell_start($course, 'discussions', $courseId);
render_course_header('Discussion', course_header_actions($pdo, $courseId, $user));
?>
<div class="course-page">
  <h1 style="font-size:1.75rem;font-weight:700;margin:0;"><?= e($discussion['title']) ?></h1>
  <div class="content-box user-html-content" style="margin-top:16px;"><?php render_rich_content($discussion['prompt'] ?? '', $discussion['prompt_format'] ?? 'text'); ?></div>
  <?php if (!empty($discussion['points'])): ?>
    <p style="font-size:14px;color:#71717a;margin-top:12px;"><?= e((string)$discussion['points']) ?> points · Graded discussion</p>
    <?php if ($myGrade && $myGrade['points'] !== null && !$canModerate): ?>
      <div class="grade-banner" style="margin-top:12px;">
        Your grade: <?= e((string)$myGrade['points']) ?> / <?= e((string)$discussion['points']) ?>
        <?php if ($myGrade['feedback']): ?> — <?= e($myGrade['feedback']) ?><?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($canModerate && !empty($discussion['points']) && $gradeParticipants): ?>
    <section style="margin-top:24px;">
      <h2 style="font-size:1.1rem;font-weight:600;margin-bottom:12px;">Grade participation</h2>
      <div class="panel">
        <?php foreach ($gradeParticipants as $st):
          $g = $gradeByUser[(int) $st['id']] ?? null;
        ?>
          <form method="post" class="panel-row" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
            <input type="hidden" name="grade_discussion" value="1">
            <input type="hidden" name="student_id" value="<?= (int)$st['id'] ?>">
            <strong style="min-width:140px;"><?= e($st['full_name']) ?></strong>
            <div>
              <label style="font-size:12px;">Points</label>
              <input type="number" step="0.1" name="points" value="<?= e($g && $g['points'] !== null ? (string)$g['points'] : '') ?>" style="width:80px;">
            </div>
            <div style="flex:1;min-width:160px;">
              <label style="font-size:12px;">Feedback</label>
              <input type="text" name="feedback" value="<?= e($g['feedback'] ?? '') ?>">
            </div>
            <button class="btn btn-sm" type="submit">Save</button>
          </form>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>

  <h2 style="font-size:1.1rem;font-weight:600;margin:32px 0 12px;">Replies (<?= $postCount ?>)</h2>
  <?php if ($tree): ?>
    <div class="discussion-thread">
      <?php $renderPosts($tree); ?>
    </div>
  <?php else: ?>
    <p style="font-size:14px;color:#71717a;font-style:italic;">Be the first to reply.</p>
  <?php endif; ?>

  <?php if ($canParticipate && !$replyTo): ?>
    <form method="post" style="margin-top:24px;">
      <h3 style="font-size:1rem;font-weight:600;margin:0 0 8px;">Post to discussion</h3>
      <textarea name="content" rows="4" placeholder="Share your thoughts…" required></textarea>
      <button class="btn" type="submit" style="margin-top:12px;">Post</button>
    </form>
  <?php elseif (!$canParticipate): ?>
    <p style="margin-top:24px;font-size:14px;color:#71717a;">Guests can read discussions but cannot post replies.</p>
  <?php endif; ?>
</div>
<?php
render_course_shell_end();
render_app_shell_end();