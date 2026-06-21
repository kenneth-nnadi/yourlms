<?php
require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/backup.php';
$user = require_teach_access($pdo);

if (!user_is_site_instructor($user)) {
    flash('error', 'Only site instructors can download full backups.');
    redirect('/admin/index.php');
}

if (isset($_GET['download'])) {
    try {
        $zipPath = build_full_site_backup($config);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="yourlms-backup-' . date('Y-m-d') . '.zip"');
        header('Content-Length: ' . filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    } catch (Throwable $e) {
        flash('error', 'Backup failed: ' . $e->getMessage());
        redirect('/admin/backup.php');
    }
}

render_head('Full backup');
render_app_shell_start($user, 'admin', '/admin/index.php');
?>
<?php render_page_header('Full site backup', 'Operations'); ?>
<div class="page-body" style="max-width:560px;">
  <p style="color:#71717a;">Downloads a ZIP with your database file (<code>data/yourlms.sqlite</code> on shared hosting) and the <code>uploads/</code> folder. Store this ZIP somewhere safe — it is your full site backup.</p>
  <a class="btn" href="<?= url('admin/backup.php?download=1') ?>">Download full backup</a>
</div>
<?php render_app_shell_end(); ?>