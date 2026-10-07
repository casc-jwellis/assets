<?php

declare(strict_types=1);

namespace App\Modules\Dashboard;

use App\Core\Module;
use App\Core\Request;
use App\Core\Router;

final class DashboardModule extends Module
{
    public function routes(Router $router): void
    {
        $router->get('/', [$this, 'index']);
    }

    public function nav(): array
    {
        return [['label' => 'Dashboard', 'path' => '/', 'icon' => 'home', 'order' => 10]];
    }

    public function index(Request $req): string
    {
        $db = $this->app->db;
        [$scope, $params] = $this->app->auth->departmentScope('a.department_id');

        $stats = $db->one(
            "SELECT COUNT(*) AS total,
                    COALESCE(SUM(a.cost), 0) AS total_cost,
                    COALESCE(SUM(CASE WHEN a.verified_date IS NULL THEN 1 ELSE 0 END), 0) AS unverified
               FROM assets a WHERE {$scope}",
            $params
        ) ?? ['total' => 0, 'total_cost' => 0, 'unverified' => 0];

        $recent = $db->all(
            "SELECT a.asset_id, a.asset_number, a.description, a.created_date,
                    t.name AS type_name, d.abbr AS dept
               FROM assets a
               LEFT JOIN asset_types t ON t.type_id = a.type_id
               LEFT JOIN departments d ON d.department_id = a.department_id
              WHERE {$scope}
              ORDER BY a.created_date DESC, a.asset_id DESC
              LIMIT 8",
            $params
        );

        return $this->render('index', [
            'title'  => 'Dashboard',
            'stats'  => $stats,
            'recent' => $recent,
        ]);
    }
}
