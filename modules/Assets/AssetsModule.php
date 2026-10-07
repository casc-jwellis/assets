<?php

declare(strict_types=1);

namespace App\Modules\Assets;

use App\Core\Module;
use App\Core\Request;
use App\Core\Router;

/**
 * Read-only asset list (search + pagination). Serves as the reference
 * implementation for new modules: routes, nav, scoped PDO query, view.
 */
final class AssetsModule extends Module
{
    private const PER_PAGE = 25;

    public function routes(Router $router): void
    {
        $router->get('/assets', [$this, 'index']);
    }

    public function nav(): array
    {
        return [['label' => 'Assets', 'path' => '/assets', 'icon' => 'box', 'order' => 20]];
    }

    public function index(Request $req): string
    {
        $db = $this->app->db;
        $search = trim((string) $req->query('q'));
        $page = max(1, (int) $req->query('page', '1'));

        [$where, $params] = $this->app->auth->departmentScope('a.department_id');

        if ($search !== '') {
            // '!' is the LIKE escape character so user-typed % and _ are matched literally.
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $where .= " AND (a.asset_number LIKE ? ESCAPE '!' OR a.serial_number LIKE ? ESCAPE '!' OR a.description LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like);
        }

        $total = (int) $db->value("SELECT COUNT(*) FROM assets a WHERE {$where}", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE; // ints only: safe to inline

        $rows = $db->all(
            "SELECT a.asset_id, a.asset_number, a.serial_number, a.description, a.cost, a.room, a.verified_date,
                    t.name AS type_name, d.abbr AS dept, b.abbr AS building
               FROM assets a
               LEFT JOIN asset_types t ON t.type_id = a.type_id
               LEFT JOIN departments d ON d.department_id = a.department_id
               LEFT JOIN buildings b ON b.building_id = a.building_id
              WHERE {$where}
              ORDER BY a.asset_number
              LIMIT " . self::PER_PAGE . " OFFSET {$offset}",
            $params
        );

        return $this->render('index', [
            'title'  => 'Assets',
            'rows'   => $rows,
            'search' => $search,
            'page'   => $page,
            'pages'  => $pages,
            'total'  => $total,
        ]);
    }
}
