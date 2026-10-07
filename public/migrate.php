<?php
/**
 * Convenience URL for running database migrations from the browser.
 * The real page is /migrate: administrators only, so you will be asked to sign in first.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

header('Location: ' . (new App\Core\Request())->basePath . '/migrate', true, 302);
