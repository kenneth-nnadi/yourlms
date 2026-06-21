<?php
declare(strict_types=1);

function render_rich_editor_assets(): void
{
    echo '<link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">';
    echo '<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>';
    echo '<script src="' . url('assets/js/rich-editor.js') . '"></script>';
}