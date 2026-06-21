<?php
declare(strict_types=1);

function handle_ref_publish_post(PDO $pdo, int $courseId, string $redirectPath, ?int $actorUserId = null): void
{
    $itemType = $_POST['item_type'] ?? '';
    $refId = (int) ($_POST['ref_id'] ?? 0);
    $title = trim($_POST['ref_title'] ?? 'Item');
    if (!in_array($itemType, ['assignment', 'quiz', 'discussion'], true) || !$refId) {
        return;
    }

    if (isset($_POST['add_ref_to_module'])) {
        $moduleId = (int) ($_POST['module_id'] ?? 0);
        $alsoPublish = isset($_POST['publish_on_add']);
        $wasVisible = ref_visible_to_students($pdo, $courseId, $itemType, $refId);
        $itemId = add_ref_to_module($pdo, $courseId, $moduleId, $itemType, $refId, $title, $alsoPublish);
        if ($itemId) {
            if ($alsoPublish && !$wasVisible) {
                notify_students_ref_published($pdo, $courseId, $itemType, $refId, $actorUserId);
            }
            flash('success', $alsoPublish ? 'Added to module and live for students.' : 'Added to module.');
        } else {
            flash('error', 'Could not add to module.');
        }
        redirect($redirectPath);
    }

    if (isset($_POST['publish_ref']) || isset($_POST['go_live_ref'])) {
        $wasVisible = ref_visible_to_students($pdo, $courseId, $itemType, $refId);
        if (go_live_ref($pdo, $courseId, $itemType, $refId)) {
            if (!$wasVisible) {
                notify_students_ref_published($pdo, $courseId, $itemType, $refId, $actorUserId);
            }
            flash('success', 'Live for students — course, module, and item are published.');
        } else {
            flash('error', 'Add this to a module first, then go live.');
        }
        redirect($redirectPath);
    }

    if (isset($_POST['unpublish_ref'])) {
        set_ref_published($pdo, $courseId, $itemType, $refId, false);
        flash('success', 'Unpublished for students.');
        redirect($redirectPath);
    }
}

function render_ref_publish_bar(PDO $pdo, int $courseId, string $itemType, int $refId, string $title, string $redirectPath): void
{
    $links = ref_module_items($pdo, $courseId, $itemType, $refId);
    $visible = ref_visible_to_students($pdo, $courseId, $itemType, $refId);
    $modules = $pdo->prepare('SELECT id, title FROM modules WHERE course_id = ? ORDER BY position');
    $modules->execute([$courseId]);
    $modules = $modules->fetchAll();

    echo '<div class="ref-publish-bar">';
    echo '<span class="ref-publish-label">Student visibility</span>';
    if ($visible) {
        echo '<span class="publish-badge publish-badge-on" title="Published">✓</span> ';
        echo '<span style="font-size:13px;color:#16a34a;font-weight:500;">Published</span>';
        echo '<form method="post" style="display:inline;margin-left:8px;">';
        echo '<input type="hidden" name="course_id" value="' . (int) $courseId . '">';
        echo '<input type="hidden" name="ref_id" value="' . (int) $refId . '">';
        echo '<input type="hidden" name="item_type" value="' . e($itemType) . '">';
        echo '<input type="hidden" name="unpublish_ref" value="1">';
        echo '<button type="submit" class="btn btn-sm btn-outline">Unpublish</button></form>';
    } elseif ($links) {
        echo '<span class="publish-badge publish-badge-off" title="Unpublished">☁</span> ';
        echo '<span style="font-size:13px;color:#71717a;">In module but not live</span>';
        echo '<form method="post" style="display:inline;margin-left:8px;">';
        echo '<input type="hidden" name="course_id" value="' . (int) $courseId . '">';
        echo '<input type="hidden" name="ref_id" value="' . (int) $refId . '">';
        echo '<input type="hidden" name="item_type" value="' . e($itemType) . '">';
        echo '<input type="hidden" name="go_live_ref" value="1">';
        echo '<button type="submit" class="btn btn-sm btn-go-live">Go live</button></form>';
    } else {
        echo '<span class="publish-badge publish-badge-off" title="Not in module">☁</span> ';
        echo '<span style="font-size:13px;color:#71717a;">Not in any module</span>';
    }

    if ($links) {
        echo '<span class="ref-publish-modules" style="font-size:12px;color:#71717a;margin-left:12px;">';
        echo 'In: ';
        $names = array_map(fn($l) => e($l['module_title']) . (item_is_published($l) ? '' : ' (draft)'), $links);
        echo implode(', ', $names);
        echo '</span>';
    }

    if ($modules) {
        echo '<form method="post" class="ref-publish-add-form">';
        echo '<input type="hidden" name="course_id" value="' . (int) $courseId . '">';
        echo '<input type="hidden" name="ref_id" value="' . (int) $refId . '">';
        echo '<input type="hidden" name="item_type" value="' . e($itemType) . '">';
        echo '<input type="hidden" name="ref_title" value="' . e($title) . '">';
        echo '<input type="hidden" name="add_ref_to_module" value="1">';
        echo '<select name="module_id" required>';
        foreach ($modules as $m) {
            echo '<option value="' . (int) $m['id'] . '">' . e($m['title']) . '</option>';
        }
        echo '</select>';
        echo '<label class="ref-publish-check"><input type="checkbox" name="publish_on_add" value="1" checked> Go live</label>';
        echo '<button type="submit" class="btn btn-sm btn-outline">' . ($links ? 'Add to another module' : 'Add to module') . '</button>';
        echo '</form>';
    } else {
        echo '<span style="font-size:12px;color:#71717a;margin-left:8px;"><a href="' . url('admin/modules.php?course_id=' . $courseId) . '">Create a module</a> first.</span>';
    }
    echo '</div>';
}