<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    /** Redirect to an application path ("/assets"); the base path is added automatically. */
    public static function redirect(string $path): never
    {
        header('Location: ' . url($path), true, 303);
        exit;
    }
}
