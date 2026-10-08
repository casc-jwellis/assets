<?php
/**
 * Front controller. Every request that is not a static file lands here.
 * Point the web server's document root at this directory.
 */

declare(strict_types=1);

// PHP's built-in dev server: let it serve real files (css/js) itself.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
    if (is_file($file)) {
        return false;
    }
}

// Browsers ask for /favicon.ico on their own. The real icon is linked from every page (static/favicon.svg),
// so answer quietly instead of running the app (which would redirect to login or render a 404 page).
if (basename((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH)) === 'favicon.ico') {
    http_response_code(204);
    exit;
}

require dirname(__DIR__) . '/src/bootstrap.php';

// First run: no config yet, so send the visitor to the setup wizard.
if (!App\Core\Config::exists()) {
    header('Location: ' . (new App\Core\Request())->basePath . '/setup.php', true, 302);
    exit;
}

App\Core\App::boot()->run();
