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
        // Register fixed paths such as /assets/new before this one when adding them.
        $router->get('/assets/{id:\d+}', [$this, 'show']);
    }

    public function nav(): array
    {
        return [['label' => 'Assets', 'path' => '/assets', 'icon' => 'box', 'order' => 20]];
    }

    /** Read-only detail page: every column, plus the asset's transfer history. */
    public function show(Request $req, array $params): string
    {
        $db = $this->app->db;
        [$scope, $scopeParams] = $this->app->auth->departmentScope('a.department_id');

        $asset = $db->one(
            "SELECT a.*,
                    t.name AS type_name, t.depreciation_years,
                    d.abbr AS dept_abbr, d.name AS dept_name,
                    b.abbr AS building_abbr, b.name AS building_name,
                    p.abbr AS purchaser_abbr, p.name AS purchaser_name,
                    u.firstname AS creator_first, u.lastname AS creator_last
               FROM assets a
               LEFT JOIN asset_types t ON t.type_id = a.type_id
               LEFT JOIN departments d ON d.department_id = a.department_id
               LEFT JOIN buildings b ON b.building_id = a.building_id
               LEFT JOIN departments p ON p.department_id = a.purchaser_id
               LEFT JOIN users u ON u.user_id = a.user_id
              WHERE a.asset_id = ? AND {$scope}",
            [(int) $params['id'], ...$scopeParams]
        );
        // Same answer for "doesn't exist" and "not yours" so IDs can't be probed.
        if ($asset === null) {
            $this->app->error(404, 'Asset not found');
        }

        $transfers = $db->all(
            'SELECT tr.transfer_date, tr.department_from, tr.department_to, tr.location_from, tr.location_to,
                    tr.reason, tr.user_id, u.firstname, u.lastname
               FROM transfers tr
               LEFT JOIN users u ON u.user_id = tr.user_id
              WHERE tr.asset_id = ?
              ORDER BY tr.transfer_date DESC, tr.transfer_id DESC',
            [(int) $asset['asset_id']]
        );

        // Straight-line: fully depreciated N years after purchase.
        $depreciation = null;
        $years = (int) $asset['depreciation_years'];
        if ($years > 0 && !empty($asset['purchase_date'])) {
            try {
                $end = (new \DateTimeImmutable((string) $asset['purchase_date']))->modify("+{$years} years");
                $depreciation = ['end' => $end->format('Y-m-d'), 'done' => $end <= new \DateTimeImmutable('now')];
            } catch (\Exception) {
                // unparseable legacy date: just omit the depreciation line
            }
        }

        return $this->render('show', [
            'title'        => 'Asset ' . $asset['asset_number'],
            'a'            => $asset,
            'transfers'    => $transfers,
            'depreciation' => $depreciation,
        ]);
    }

    public function index(Request $req): string
    {
        $db = $this->app->db;
        $search = trim((string) $req->query('q'));
        $page = max(1, (int) $req->query('page', '1'));

        // An empty search is just the plain list: keep the address bar tidy ("/assets", not "/assets?q=").
        if ($req->query('q') !== null && $search === '') {
            $this->redirect('/assets' . ($page > 1 ? '?page=' . $page : ''));
        }

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
