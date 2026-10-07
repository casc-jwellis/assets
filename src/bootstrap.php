<?php

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

// Minimal PSR-4 style autoloader (no Composer needed).
spl_autoload_register(static function (string $class): void {
    $map = [
        'App\\Core\\'    => APP_ROOT . '/src/Core/',
        'App\\Modules\\' => APP_ROOT . '/modules/',
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

require __DIR__ . '/helpers.php';
