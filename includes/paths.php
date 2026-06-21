<?php
declare(strict_types=1);

/** Project root (parent of public/). */
function app_root(): string
{
    return dirname(__DIR__);
}

function public_root(): string
{
    return app_root() . '/public';
}