<?php
/**
 * First-run setup wizard: collects the database credentials and settings,
 * optionally applies database migrations and creates an administrator, then
 * writes config/config.php.
 *
 * It locks itself: once the config file exists this page refuses to run.
 * (To re-run it, delete config/config.php. After setup you may also delete this file.)
 */

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Core\Installer;
use App\Core\Migrator;

$app = App::bootSetup();
$app->session->start();
$app->sendSecurityHeaders();

$render = static function (string $template, array $data = []) use ($app): never {
    echo $app->view->render(
        APP_ROOT . '/templates/' . $template . '.php',
        $data + ['title' => 'Setup', 'wide' => true],
        'auth'
    );
    exit;
};

if (Config::exists()) {
    http_response_code(403);
    $render('setup-locked');
}

$defaults = [
    'app_name' => 'Asset Manager', 'db_host' => 'localhost', 'db_port' => 3306, 'db_name' => 'assets',
    'db_user' => '', 'idle_minutes' => 30, 'migrate' => true,
    'admin_user' => '', 'admin_first' => '', 'admin_last' => '', 'admin_email' => '',
];

if ($app->request->method !== 'POST') {
    $render('setup', ['f' => $defaults, 'errors' => []]);
}

// ---- POST ----
if (!$app->csrf->verify($app->request)) {
    http_response_code(403);
    $render('setup', ['f' => $defaults, 'errors' => ['The form expired. Please try again.']]);
}

[$errors, $v] = Installer::validate($app->request);
$sticky = array_diff_key($v, array_flip(['db_pass', 'admin_pass', 'admin_pass2'])); // never echo passwords back
if ($errors !== []) {
    http_response_code(422);
    $render('setup', ['f' => $sticky, 'errors' => $errors]);
}

$config = Installer::configValues($v);
$steps = [];

try {
    $db = new Database(Config::fromArray($config));
    try {
        $db->pdo();
    } catch (PDOException $e) {
        throw new RuntimeException('Could not connect to the database: ' . $e->getMessage());
    }
    $steps[] = 'Connected to database "' . $v['db_name'] . '" on ' . $v['db_host'] . '.';

    try {
        $db->value('SELECT COUNT(*) FROM users');
    } catch (PDOException) {
        throw new RuntimeException('Connected, but the "users" table was not found. Import assets.schema.sql into this database first.');
    }

    if ($v['migrate']) {
        $done = [];
        (new Migrator($db, APP_ROOT . '/migrations'))->run($done);
        $steps[] = $done === [] ? 'Database is already up to date.' : 'Applied database updates: ' . implode(', ', $done) . '.';
    }

    if ($v['wants_admin']) {
        $steps[] = 'Administrator "' . $v['admin_user'] . '" ' . Installer::saveAdmin($db, $v) . '.';
    }
} catch (Throwable $e) {
    error_log('setup: ' . $e->getMessage());
    http_response_code(422);
    $render('setup', ['f' => $sticky, 'errors' => [$e->getMessage()]]);
}

// Database work succeeded: write the config last, which is what locks the wizard.
$php = Installer::configPhp($config);
try {
    Installer::writeConfig($php);
} catch (RuntimeException $e) {
    http_response_code(500);
    $render('setup-manual', ['reason' => $e->getMessage(), 'path' => Config::path(), 'php' => $php, 'steps' => $steps]);
}

$steps[] = 'Saved settings to ' . Config::path() . '.';
$render('setup-done', ['steps' => $steps]);
