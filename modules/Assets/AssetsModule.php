<?php

declare(strict_types=1);

namespace App\Modules\Assets;

use App\Core\Migrator;
use App\Core\Module;
use App\Core\Request;
use App\Core\Router;

/**
 * Assets: searchable list, detail page, add/edit, and "mark verified".
 *
 * Reading is limited to the user's departments (any permission row); changing needs
 * 'rw' on the asset's department (and on the new one when moving it). Administrators
 * can do everything, and are the only ones who may change an existing asset number.
 */
final class AssetsModule extends Module
{
    private const PER_PAGE = 25;

    /** Editing relies on these schema changes (see migrations/). */
    private const REQUIRED_MIGRATIONS = ['003_widen_transfers_user_id.sql', '005_asset_edit_columns.sql'];

    private ?bool $ready = null;

    public function routes(Router $router): void
    {
        $router->get('/assets', [$this, 'index']);
        // Fixed paths before the {id} ones.
        $router->get('/assets/new', [$this, 'showNew']);
        $router->post('/assets/new', [$this, 'create']);
        $router->get('/assets/{id:\d+}', [$this, 'show']);
        $router->get('/assets/{id:\d+}/edit', [$this, 'showEdit']);
        $router->post('/assets/{id:\d+}/edit', [$this, 'update']);
        $router->post('/assets/{id:\d+}/verify', [$this, 'verify']);
    }

    public function nav(): array
    {
        return [['label' => 'Assets', 'path' => '/assets', 'icon' => 'box', 'order' => 20]];
    }

    // ------------------------------------------------------------------ read

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

        $updatedBy = '';
        if (!empty($asset['updated_by'])) {
            $u = $db->one('SELECT firstname, lastname FROM users WHERE user_id = ?', [$asset['updated_by']]);
            $updatedBy = trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? '')) ?: (string) $asset['updated_by'];
        }

        return $this->render('show', [
            'title'        => 'Asset ' . $asset['asset_number'],
            'a'            => $asset,
            'transfers'    => $transfers,
            'depreciation' => $depreciation,
            'updatedBy'    => $updatedBy,
            'canEdit'      => $this->editingReady() && $this->app->auth->canWrite((int) $asset['department_id']),
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
            'canAdd' => $this->editingReady() && $this->app->auth->canWriteAny(),
        ]);
    }

    // ----------------------------------------------------------------- write

    public function showNew(Request $req): string
    {
        $this->requireWriter();
        $writable = $this->app->auth->writableDepartmentIds();
        $home = (int) ($this->app->auth->user()['department_id'] ?? 0);
        $dept = $this->app->auth->canWrite($home) && $home > 0 ? $home : (int) ($writable[0] ?? 0);

        return $this->form(null, [
            'asset_number' => '', 'serial_number' => '', 'type_id' => 0, 'po_number' => '', 'cost' => '',
            'purchaser_id' => 0, 'purchase_date' => date('Y-m-d'), 'department_id' => $dept, 'building_id' => 0,
            'room' => '', 'description' => '', 'notes' => '', 'reason' => '',
        ], [], 0);
    }

    public function create(Request $req): string
    {
        $this->requireWriter();
        return $this->save($req, null);
    }

    public function showEdit(Request $req, array $params): string
    {
        $this->requireEditing();
        $a = $this->findEditable((int) $params['id']);
        return $this->form($a, [
            'asset_number'  => $a['asset_number'],
            'serial_number' => $a['serial_number'],
            'type_id'       => (int) $a['type_id'],
            'po_number'     => $a['po_number'],
            'cost'          => number_format((float) $a['cost'], 2, '.', ''),
            'purchaser_id'  => (int) $a['purchaser_id'],
            'purchase_date' => substr((string) $a['purchase_date'], 0, 10),
            'department_id' => (int) $a['department_id'],
            'building_id'   => (int) $a['building_id'],
            'room'          => $a['room'],
            'description'   => $a['description'],
            'notes'         => $a['notes'],
            'reason'        => '',
        ], [], (int) $a['version']);
    }

    public function update(Request $req, array $params): string
    {
        $this->requireEditing();
        return $this->save($req, $this->findEditable((int) $params['id']));
    }

    /** One click "I physically checked this asset today". */
    public function verify(Request $req, array $params): never
    {
        $this->requireEditing();
        $a = $this->findEditable((int) $params['id']);
        $now = date('Y-m-d H:i:s');
        // Deliberately does not bump `version`: it only touches verified_date, which the edit form doesn't carry,
        // so it can't clobber (or be clobbered by) someone's in-progress edit.
        $this->app->db->execute(
            'UPDATE assets SET verified_date = ?, updated_date = ?, updated_by = ? WHERE asset_id = ?',
            [$now, $now, $this->app->auth->user()['user_id'], $a['asset_id']]
        );
        $this->app->session->flash('success', 'Asset ' . $a['asset_number'] . ' marked as verified.');
        $this->redirect('/assets/' . (int) $a['asset_id']);
    }

    private function save(Request $req, ?array $existing): string
    {
        $db = $this->app->db;
        $auth = $this->app->auth;
        $isNew = $existing === null;
        $canRenumber = $isNew || $auth->isAdmin();

        $f = [
            'asset_number'  => $canRenumber ? trim((string) $req->post('asset_number')) : (string) $existing['asset_number'],
            'serial_number' => trim((string) $req->post('serial_number')),
            'type_id'       => (int) $req->post('type_id'),
            'po_number'     => trim((string) $req->post('po_number')),
            'cost'          => trim((string) $req->post('cost')),
            'purchaser_id'  => (int) $req->post('purchaser_id'),
            'purchase_date' => trim((string) $req->post('purchase_date')),
            'department_id' => (int) $req->post('department_id'),
            'building_id'   => (int) $req->post('building_id'),
            'room'          => trim((string) $req->post('room')),
            'description'   => trim((string) $req->post('description')),
            'notes'         => rtrim(str_replace("\r\n", "\n", (string) $req->post('notes'))),
            'reason'        => trim((string) $req->post('reason')),
        ];
        $version = (int) $req->post('version');

        $lookups = $this->lookups();
        $errors = $this->validate($f, $lookups, $existing);

        if ($errors === []) {
            try {
                $id = $isNew ? $this->insert($f) : $this->updateRow($existing, $f, $version, $lookups);
                if ($id === null) { // optimistic-lock conflict
                    $cur = $db->one('SELECT version, updated_date, updated_by FROM assets WHERE asset_id = ?', [$existing['asset_id']]);
                    $who = '';
                    if ($cur !== null && !empty($cur['updated_by'])) {
                        $u = $db->one('SELECT firstname, lastname FROM users WHERE user_id = ?', [$cur['updated_by']]);
                        $who = trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? '')) ?: (string) $cur['updated_by'];
                    }
                    http_response_code(409);
                    return $this->form($existing, $f, [
                        'This asset was changed' . ($who !== '' ? ' by ' . $who : '')
                        . (!empty($cur['updated_date']) ? ' on ' . $cur['updated_date'] : '')
                        . ' while you were editing, so your changes were NOT saved. Your edits are kept below. '
                        . 'Open the current version to compare; saving again will overwrite it.',
                    ], (int) ($cur['version'] ?? $version), true);
                }
                $this->app->session->flash('success', 'Asset ' . $f['asset_number'] . ($isNew ? ' created.' : ' saved.'));
                $this->redirect('/assets/' . $id);
            } catch (\PDOException $e) {
                if ((string) $e->getCode() === '23000') {
                    $errors[] = 'Another asset already uses that asset number.';
                } else {
                    error_log('asset save failed: ' . $e->getMessage());
                    $errors[] = 'The asset could not be saved because of a database error.';
                }
            }
        }

        http_response_code(422);
        return $this->form($existing, $f, $errors, $version);
    }

    /** @return list<string> */
    private function validate(array &$f, array $lookups, ?array $existing): array
    {
        $db = $this->app->db;
        $e = [];
        $len = static fn(string $s): int => mb_strlen($s, 'UTF-8');
        $oneLine = static fn(string $s): bool => !preg_match('/[\x00-\x1f\x7f]/', $s);

        if ($f['asset_number'] === '' || $len($f['asset_number']) > 32 || !$oneLine($f['asset_number'])) {
            $e[] = 'Enter an asset number (up to 32 characters).';
        } elseif ($db->one('SELECT 1 FROM assets WHERE asset_number = ? AND asset_id <> ?', [$f['asset_number'], (int) ($existing['asset_id'] ?? 0)]) !== null) {
            $e[] = 'Another asset already uses that asset number.';
        }
        if ($f['description'] === '' || $len($f['description']) > 64 || !$oneLine($f['description'])) {
            $e[] = 'Enter a description (up to 64 characters).';
        }
        if ($len($f['serial_number']) > 32 || !$oneLine($f['serial_number'])) {
            $e[] = 'The serial number can be at most 32 characters.';
        }
        if ($len($f['po_number']) > 32 || !$oneLine($f['po_number'])) {
            $e[] = 'The PO number can be at most 32 characters.';
        }
        if ($len($f['room']) > 8 || !$oneLine($f['room'])) {
            $e[] = 'The room can be at most 8 characters.';
        }
        if ($len($f['reason']) > 16 || !$oneLine($f['reason'])) {
            $e[] = 'The transfer reason can be at most 16 characters.';
        }
        if ($len($f['notes']) > 10240) {
            $e[] = 'Notes can be at most 10,240 characters.';
        }
        // The text columns are utf8mb3: 4-byte characters (emoji) cannot be stored.
        if (preg_match('/[\x{10000}-\x{10FFFF}]/u', implode('', [$f['asset_number'], $f['serial_number'], $f['po_number'],
            $f['room'], $f['description'], $f['notes'], $f['reason']]))) {
            $e[] = 'Emoji and other special symbols cannot be stored. Please remove them.';
        }

        if (!in_array($f['type_id'], $lookups['types'], true)) {
            $e[] = 'Choose an asset type.';
        }
        if (!in_array($f['building_id'], $lookups['buildings'], true)) {
            $e[] = 'Choose a building.';
        }
        if (!in_array($f['purchaser_id'], $lookups['departments'], true)) {
            $e[] = 'Choose the purchasing department.';
        }
        if (!in_array($f['department_id'], $lookups['departments'], true) || !$this->app->auth->canWrite($f['department_id'])) {
            $e[] = 'Choose a department you have write access to.';
        }

        $cost = str_replace([',', '$', ' '], '', $f['cost']);
        if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $cost)) {
            $e[] = 'Enter the cost as an amount such as 1249.99.';
        } else {
            $f['cost'] = number_format((float) $cost, 2, '.', '');
        }

        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $f['purchase_date']);
        if ($d === false || $d->format('Y-m-d') !== $f['purchase_date']) {
            $e[] = 'Enter a valid purchase date.';
        }
        return $e;
    }

    /** @return int the new asset_id */
    private function insert(array $f): int
    {
        $db = $this->app->db;
        $db->execute(
            'INSERT INTO assets (asset_number, serial_number, type_id, po_number, cost, purchaser_id, purchase_date,
                                 department_id, building_id, room, description, verified_date, notes, user_id, created_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?)',
            [$f['asset_number'], $f['serial_number'], $f['type_id'], $f['po_number'], $f['cost'], $f['purchaser_id'],
             $f['purchase_date'] . ' 00:00:00', $f['department_id'], $f['building_id'], $f['room'], $f['description'],
             $f['notes'], $this->app->auth->user()['user_id'], date('Y-m-d H:i:s')]
        );
        return (int) $db->pdo()->lastInsertId();
    }

    /**
     * Update an asset if nobody else has saved it since the form was loaded, and log a
     * transfer when its department, building or room changed (same transaction).
     *
     * @return int|null the asset_id, or null if the version no longer matches (conflict)
     */
    private function updateRow(array $old, array $f, int $version, array $lookups): ?int
    {
        $db = $this->app->db;
        $pdo = $db->pdo();
        $now = date('Y-m-d H:i:s');
        $uid = (string) $this->app->auth->user()['user_id'];

        // Keep the stored timestamp untouched if the date itself wasn't changed.
        $purchase = substr((string) $old['purchase_date'], 0, 10) === $f['purchase_date']
            ? $old['purchase_date']
            : $f['purchase_date'] . ' 00:00:00';

        $pdo->beginTransaction();
        try {
            $n = $db->execute(
                'UPDATE assets SET asset_number = ?, serial_number = ?, type_id = ?, po_number = ?, cost = ?, purchaser_id = ?,
                        purchase_date = ?, department_id = ?, building_id = ?, room = ?, description = ?, notes = ?,
                        updated_date = ?, updated_by = ?, version = version + 1
                  WHERE asset_id = ? AND version = ?',
                [$f['asset_number'], $f['serial_number'], $f['type_id'], $f['po_number'], $f['cost'], $f['purchaser_id'],
                 $purchase, $f['department_id'], $f['building_id'], $f['room'], $f['description'], $f['notes'],
                 $now, $uid, $old['asset_id'], $version]
            );
            if ($n === 0) {
                $pdo->rollBack();
                return null;
            }

            $moved = (int) $old['department_id'] !== $f['department_id']
                || (int) $old['building_id'] !== $f['building_id']
                || (string) $old['room'] !== $f['room'];
            if ($moved) {
                $db->execute(
                    'INSERT INTO transfers (asset_id, user_id, department_from, department_to, location_from, location_to, reason, transfer_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$old['asset_id'], $uid,
                     $lookups['dept_abbr'][(int) $old['department_id']] ?? '', $lookups['dept_abbr'][$f['department_id']] ?? '',
                     ($lookups['building_abbr'][(int) $old['building_id']] ?? '') . $old['room'],
                     ($lookups['building_abbr'][$f['building_id']] ?? '') . $f['room'],
                     $f['reason'] !== '' ? $f['reason'] : null, $now]
                );
            }
            $pdo->commit();
            return (int) $old['asset_id'];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // --------------------------------------------------------------- helpers

    /** Editing needs the schema changes in REQUIRED_MIGRATIONS. */
    private function editingReady(): bool
    {
        if ($this->ready === null) {
            $migrator = new Migrator($this->app->db, APP_ROOT . '/migrations');
            $this->ready = array_reduce(
                self::REQUIRED_MIGRATIONS,
                static fn(bool $ok, string $m): bool => $ok && $migrator->isApplied($m),
                true
            );
        }
        return $this->ready;
    }

    private function requireEditing(): void
    {
        if (!$this->editingReady()) {
            $this->app->error(
                503,
                'Editing is not available yet',
                'The database needs updating first: an administrator must apply the pending migrations ('
                . implode(', ', self::REQUIRED_MIGRATIONS) . ') on the Migrations page.'
            );
        }
    }

    /** Ready, and the user can write somewhere. */
    private function requireWriter(): void
    {
        $this->requireEditing();
        if (!$this->app->auth->canWriteAny()) {
            $this->app->error(403, 'Access denied', 'You do not have write access to any department.');
        }
    }

    /** The asset row, if the user may read it (404 otherwise) and write it (403 otherwise). */
    private function findEditable(int $id): array
    {
        [$scope, $params] = $this->app->auth->departmentScope('a.department_id');
        $a = $this->app->db->one("SELECT a.* FROM assets a WHERE a.asset_id = ? AND {$scope}", [$id, ...$params]);
        if ($a === null) {
            $this->app->error(404, 'Asset not found');
        }
        if (!$this->app->auth->canWrite((int) $a['department_id'])) {
            $this->app->error(403, 'Access denied', 'You have read-only access to this asset\'s department.');
        }
        return $a;
    }

    /** @return array{types:list<int>, buildings:list<int>, departments:list<int>, dept_abbr:array<int,string>, building_abbr:array<int,string>} */
    private function lookups(): array
    {
        $db = $this->app->db;
        $depts = $db->all('SELECT department_id, abbr FROM departments');
        $buildings = $db->all('SELECT building_id, abbr FROM buildings');
        return [
            'types'         => array_map('intval', array_column($db->all('SELECT type_id FROM asset_types'), 'type_id')),
            'buildings'     => array_map('intval', array_column($buildings, 'building_id')),
            'departments'   => array_map('intval', array_column($depts, 'department_id')),
            'dept_abbr'     => array_column($depts, 'abbr', 'department_id'),
            'building_abbr' => array_column($buildings, 'abbr', 'building_id'),
        ];
    }

    private function form(?array $existing, array $f, array $errors, int $version, bool $conflict = false): string
    {
        $db = $this->app->db;
        $writable = $this->app->auth->writableDepartmentIds();
        $departments = $db->all('SELECT department_id, abbr, name FROM departments ORDER BY abbr');

        return $this->render('form', [
            'title'       => $existing === null ? 'New asset' : 'Edit ' . $existing['asset_number'],
            'isNew'       => $existing === null,
            'asset'       => $existing,
            'f'           => $f,
            'errors'      => $errors,
            'version'     => $version,
            'conflict'    => $conflict,
            'canRenumber' => $existing === null || $this->app->auth->isAdmin(),
            'types'       => $db->all('SELECT type_id, name FROM asset_types ORDER BY name'),
            'buildings'   => $db->all('SELECT building_id, abbr, name FROM buildings ORDER BY name'),
            'departments' => $departments,
            'writableDepartments' => $writable === null
                ? $departments
                : array_values(array_filter($departments, static fn(array $d): bool => in_array((int) $d['department_id'], $writable, true))),
        ]);
    }
}
