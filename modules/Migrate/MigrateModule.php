<?php

declare(strict_types=1);

namespace App\Modules\Migrate;

use App\Core\Migrator;
use App\Core\Module;
use App\Core\Request;
use App\Core\Router;

/** Administrator-only page to review and apply pending database migrations (migrations/*.sql). */
final class MigrateModule extends Module
{
    public function routes(Router $router): void
    {
        $router->get('/migrate', [$this, 'index'], ['admin' => true]);
        $router->post('/migrate', [$this, 'run'], ['admin' => true]);
    }

    public function nav(): array
    {
        return [['label' => 'Migrations', 'path' => '/migrate', 'icon' => 'database', 'order' => 90, 'admin' => true]];
    }

    public function index(Request $req): string
    {
        return $this->render('index', ['title' => 'Database migrations', 'migrations' => $this->migrator()->status()]);
    }

    public function run(Request $req): never
    {
        $done = [];
        try {
            $this->migrator()->run($done);
            $this->app->session->flash('success', $done === []
                ? 'The database is already up to date.'
                : 'Applied ' . count($done) . ' migration' . (count($done) === 1 ? '' : 's') . ': ' . implode(', ', $done) . '.');
        } catch (\RuntimeException $e) {
            error_log('migrate: ' . $e->getMessage());
            if ($done !== []) {
                $this->app->session->flash('success', 'Applied: ' . implode(', ', $done) . '.');
            }
            $this->app->session->flash('danger', 'Migration failed. ' . $e->getMessage());
        }
        $this->redirect('/migrate');
    }

    private function migrator(): Migrator
    {
        return new Migrator($this->app->db, APP_ROOT . '/migrations');
    }
}
