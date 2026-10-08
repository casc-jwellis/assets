<?php

declare(strict_types=1);

namespace App\Modules\Assets;

use App\Core\Module;
use App\Core\Request;
use App\Core\Router;

/**
 * Assets: searchable list, detail page, add/edit, mark verified, retire/restore, and bulk
 * retire / transfer from the list.
 *
 * Reading is limited to the user's departments (any permission row); changing needs
 * 'rw' on the asset's department (and on the new one when moving it). Administrators
 * can do everything, and are the only ones who may change an existing asset number or
 * restore a retired asset. Retired assets are locked: no edits, moves or verification.
 */
final class AssetsModule extends Module
{
    private const PER_PAGE = 25;

    /** Most assets one bulk action will touch. */
    private const BULK_LIMIT = 1000;

    /** Editing relies on these schema changes (see migrations/). */
    private const REQUIRED_MIGRATIONS = ['003_widen_transfers_user_id.sql', '005_asset_edit_columns.sql'];
    private const RETIREMENT_MIGRATION = '006_asset_retirement.sql';

    public const DISPOSAL_METHODS = ['Surplus', 'Recycled', 'Sold', 'Donated', 'Traded in', 'Scrapped', 'Lost', 'Stolen', 'Other'];

    private ?bool $ready = null;

    public function routes(Router $router): void
    {
        $router->get('/assets', [$this, 'index']);
        // Fixed paths before the {id} ones.
        $router->get('/assets/new', [$this, 'showNew']);
        $router->post('/assets/new', [$this, 'create']);
        $router->post('/assets/bulk', [$this, 'bulkReview']);
        $router->post('/assets/bulk/apply', [$this, 'bulkApply']);
        $router->get('/assets/{id:\d+}', [$this, 'show']);
        $router->get('/assets/{id:\d+}/edit', [$this, 'showEdit']);
        $router->post('/assets/{id:\d+}/edit', [$this, 'update']);
        $router->post('/assets/{id:\d+}/verify', [$this, 'verify']);
        $router->get('/assets/{id:\d+}/restore', [$this, 'showRestore'], ['admin' => true]);
        $router->post('/assets/{id:\d+}/restore', [$this, 'restore'], ['admin' => true]);
    }

    public function nav(): array
    {
        return [['label' => 'Assets', 'path' => '/assets', 'icon' => 'box', 'order' => 20]];
    }

    // ------------------------------------------------------------------ read

    /** The list view to return to: search text, page and status filter (see Module::listState). */
    protected function rememberList(Request $req): void
    {
        $status = $this->statusFilter($req);
        $this->listQs = $this->listState(
            mb_substr(trim((string) $req->query('q')), 0, 64),
            max(1, (int) $req->query('page', '1')),
            ['status' => $status === 'active' ? null : $status]
        );
    }

    /** 'active' (default), 'retired' or 'all'. Always 'all' until migration 006 exists. */
    private function statusFilter(Request $req): string
    {
        if (!$this->retirementReady()) {
            return 'all';
        }
        $s = (string) $req->query('status');
        return in_array($s, ['retired', 'all'], true) ? $s : 'active';
    }

    /**
     * WHERE clause for the list: department read scope + search text + status filter.
     *
     * @return array{0:string, 1:list<mixed>}
     */
    private function listWhere(string $search, string $status): array
    {
        [$where, $params] = $this->app->auth->departmentScope('a.department_id');
        if ($search !== '') {
            // '!' is the LIKE escape character so user-typed % and _ are matched literally.
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $where .= " AND (a.asset_number LIKE ? ESCAPE '!' OR a.serial_number LIKE ? ESCAPE '!' OR a.description LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like);
        }
        if ($status === 'active') {
            $where .= ' AND a.retired = 0';
        } elseif ($status === 'retired') {
            $where .= ' AND a.retired = 1';
        }
        return [$where, $params];
    }

    /** Read-only detail page: every column, plus the asset's transfer history. */
    public function show(Request $req, array $params): string
    {
        $this->rememberList($req);
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

        $retired = !empty($asset['retired']);
        $canWrite = $this->app->auth->canWrite((int) $asset['department_id']);
        $editing = $this->editingReady();

        return $this->render('show', [
            'title'        => 'Asset ' . $asset['asset_number'],
            'a'            => $asset,
            'transfers'    => $transfers,
            'depreciation' => $depreciation,
            'updatedBy'    => $this->userName($asset['updated_by'] ?? null),
            'retired'      => $retired,
            'retiredBy'    => $this->userName($asset['retired_by'] ?? null),
            'canEdit'      => $editing && $canWrite && !$retired,
            'canRetire'    => $editing && $this->retirementReady() && $canWrite && !$retired,
            'canRestore'   => $this->retirementReady() && $retired && $this->app->auth->isAdmin(),
        ]);
    }

    public function index(Request $req): string
    {
        $db = $this->app->db;
        $search = trim((string) $req->query('q'));
        $page = max(1, (int) $req->query('page', '1'));
        $status = $this->statusFilter($req);
        $statusParam = $status === 'active' ? null : $status;

        // An empty search is just the plain list: keep the address bar tidy ("/assets", not "/assets?q=").
        if ($req->query('q') !== null && $search === '') {
            $this->redirect('/assets' . $this->listState('', $page, ['status' => $statusParam]));
        }

        [$where, $params] = $this->listWhere($search, $status);

        $total = (int) $db->value("SELECT COUNT(*) FROM assets a WHERE {$where}", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE; // ints only: safe to inline
        $this->listQs = $this->listState($search, $page, ['status' => $statusParam]);

        $retirement = $this->retirementReady();
        $rows = $db->all(
            "SELECT a.asset_id, a.asset_number, a.serial_number, a.description, a.cost, a.room, a.verified_date,
                    t.name AS type_name, d.abbr AS dept, b.abbr AS building" . ($retirement ? ', a.retired' : '') . "
               FROM assets a
               LEFT JOIN asset_types t ON t.type_id = a.type_id
               LEFT JOIN departments d ON d.department_id = a.department_id
               LEFT JOIN buildings b ON b.building_id = a.building_id
              WHERE {$where}
              ORDER BY a.asset_number
              LIMIT " . self::PER_PAGE . " OFFSET {$offset}",
            $params
        );

        $canWriteAny = $this->editingReady() && $this->app->auth->canWriteAny();
        return $this->render('index', [
            'title'      => 'Assets',
            'rows'       => $rows,
            'search'     => $search,
            'page'       => $page,
            'pages'      => $pages,
            'total'      => $total,
            'status'     => $status,
            'showStatus' => $retirement,
            'canAdd'     => $canWriteAny,
            'canBulk'    => $canWriteAny && $retirement,
        ]);
    }

    // ----------------------------------------------------------------- write

    public function showNew(Request $req): string
    {
        $this->rememberList($req);
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
        $this->rememberList($req);
        $this->requireWriter();
        return $this->save($req, null);
    }

    public function showEdit(Request $req, array $params): string
    {
        $this->rememberList($req);
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
        $this->rememberList($req);
        $this->requireEditing();
        return $this->save($req, $this->findEditable((int) $params['id']));
    }

    /** One click "I physically checked this asset today". */
    public function verify(Request $req, array $params): never
    {
        $this->rememberList($req);
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
        $this->redirect('/assets/' . (int) $a['asset_id'] . $this->listQs);
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
                    $who = $this->userName($cur['updated_by'] ?? null);
                    http_response_code(409);
                    return $this->form($existing, $f, [
                        'This asset was changed' . ($who !== '' ? ' by ' . $who : '')
                        . (!empty($cur['updated_date']) ? ' on ' . $cur['updated_date'] : '')
                        . ' while you were editing, so your changes were NOT saved. Your edits are kept below. '
                        . 'Open the current version to compare; saving again will overwrite it.',
                    ], (int) ($cur['version'] ?? $version), true);
                }
                $this->app->session->flash('success', 'Asset ' . $f['asset_number'] . ($isNew ? ' created.' : ' saved.'));
                $this->redirect('/assets/' . $id . $this->listQs);
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

        if ($f['asset_number'] === '' || $this->len($f['asset_number']) > 32 || !$this->oneLine($f['asset_number'])) {
            $e[] = 'Enter an asset number (up to 32 characters).';
        } elseif ($db->one('SELECT 1 FROM assets WHERE asset_number = ? AND asset_id <> ?', [$f['asset_number'], (int) ($existing['asset_id'] ?? 0)]) !== null) {
            $e[] = 'Another asset already uses that asset number.';
        }
        if ($f['description'] === '' || $this->len($f['description']) > 64 || !$this->oneLine($f['description'])) {
            $e[] = 'Enter a description (up to 64 characters).';
        }
        if ($this->len($f['serial_number']) > 32 || !$this->oneLine($f['serial_number'])) {
            $e[] = 'The serial number can be at most 32 characters.';
        }
        if ($this->len($f['po_number']) > 32 || !$this->oneLine($f['po_number'])) {
            $e[] = 'The PO number can be at most 32 characters.';
        }
        if ($this->len($f['room']) > 8 || !$this->oneLine($f['room'])) {
            $e[] = 'The room can be at most 8 characters.';
        }
        if ($this->len($f['reason']) > 16 || !$this->oneLine($f['reason'])) {
            $e[] = 'The transfer reason can be at most 16 characters.';
        }
        if ($this->len($f['notes']) > 10240) {
            $e[] = 'Notes can be at most 10,240 characters.';
        }
        if ($this->hasWideChars($f['asset_number'], $f['serial_number'], $f['po_number'], $f['room'], $f['description'], $f['notes'], $f['reason'])) {
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
                $this->logTransfer(
                    (int) $old['asset_id'], $uid, $now, $f['reason'], $lookups,
                    (int) $old['department_id'], (int) $old['building_id'], (string) $old['room'],
                    $f['department_id'], $f['building_id'], $f['room']
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

    /** Insert one row into the transfers history, in the same "BUILDINGROOM" format as the legacy data. */
    private function logTransfer(
        int $assetId, string $userId, string $when, string $reason, array $lookups,
        int $fromDept, int $fromBuilding, string $fromRoom, int $toDept, int $toBuilding, string $toRoom
    ): void {
        $this->app->db->execute(
            'INSERT INTO transfers (asset_id, user_id, department_from, department_to, location_from, location_to, reason, transfer_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$assetId, $userId,
             $lookups['dept_abbr'][$fromDept] ?? '', $lookups['dept_abbr'][$toDept] ?? '',
             ($lookups['building_abbr'][$fromBuilding] ?? '') . $fromRoom,
             ($lookups['building_abbr'][$toBuilding] ?? '') . $toRoom,
             $reason !== '' ? $reason : null, $when]
        );
    }

    // ------------------------------------------------------- bulk retire / transfer

    /**
     * Step 1 of a bulk action: the list's selection bar (or a single asset's Retire button)
     * posts here. Shows exactly what would change and asks for the details; writes nothing.
     */
    public function bulkReview(Request $req): string
    {
        $this->rememberList($req);
        $this->requireBulk();
        $action = $this->bulkAction($req);
        [$targets, $skipped, $truncated] = $this->bulkTargets($req, true);

        if ($targets === []) {
            $this->app->session->flash('danger', 'None of the selected assets can be ' . ($action === 'retire' ? 'retired' : 'transferred')
                . ($skipped !== [] ? ' (' . $this->skipSummary($skipped) . ')' : '') . '.');
            $this->redirect('/assets' . $this->listQs);
        }
        return $this->bulkForm($action, $targets, $skipped, $truncated, $this->bulkDefaults(), []);
    }

    /** Step 2: validate the details and apply the change to every eligible asset, in one transaction. */
    public function bulkApply(Request $req): string
    {
        $this->rememberList($req);
        $this->requireBulk();
        $action = $this->bulkAction($req);
        // Re-check permissions and state now: the review page is not trusted.
        [$targets, $skipped] = $this->bulkTargets($req, false);
        if ($targets === []) {
            $this->app->session->flash('danger', 'None of the selected assets can be changed any more' . ($skipped !== [] ? ' (' . $this->skipSummary($skipped) . ')' : '') . '.');
            $this->redirect('/assets' . $this->listQs);
        }

        $f = [
            'retire_date'     => trim((string) $req->post('retire_date')),
            'disposal_method' => (string) $req->post('disposal_method'),
            'retired_notes'   => trim((string) $req->post('retired_notes')),
            'department_id'   => (int) $req->post('department_id'),
            'building_id'     => (int) $req->post('building_id'),
            'room'            => trim((string) $req->post('room')),
            'reason'          => trim((string) $req->post('reason')),
        ];
        $lookups = $this->lookups();
        $errors = $action === 'retire' ? $this->validateRetire($f) : $this->validateTransfer($f, $lookups);
        if ($errors !== []) {
            http_response_code(422);
            return $this->bulkForm($action, $targets, $skipped, false, $f, $errors);
        }

        $pdo = $this->app->db->pdo();
        $pdo->beginTransaction();
        try {
            $changed = $action === 'retire' ? $this->applyRetire($targets, $f) : $this->applyTransfer($targets, $f, $lookups);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('bulk ' . $action . ' failed: ' . $e->getMessage());
            http_response_code(500);
            return $this->bulkForm($action, $targets, $skipped, false, $f, ['The change could not be saved because of a database error. Nothing was changed.']);
        }

        $unchanged = count($targets) - $changed;
        $verb = $action === 'retire' ? 'Retired' : 'Transferred';
        $msg = $verb . ' ' . $changed . ' asset' . ($changed === 1 ? '' : 's') . '.';
        if ($unchanged > 0) {
            $msg .= ' ' . $unchanged . ' already matched and ' . ($unchanged === 1 ? 'was' : 'were') . ' left alone.';
        }
        if ($skipped !== []) {
            $msg .= ' Skipped: ' . $this->skipSummary($skipped) . '.';
        }
        $this->app->session->flash('success', $msg);
        $this->redirect('/assets' . $this->listQs);
    }

    /** @return list<string> */
    private function validateRetire(array $f): array
    {
        $e = [];
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $f['retire_date']);
        if ($d === false || $d->format('Y-m-d') !== $f['retire_date']) {
            $e[] = 'Enter a valid retirement date.';
        } elseif ($d > new \DateTimeImmutable('today')) {
            $e[] = 'The retirement date cannot be in the future.';
        }
        if (!in_array($f['disposal_method'], self::DISPOSAL_METHODS, true)) {
            $e[] = 'Choose a disposal method.';
        }
        if ($this->len($f['retired_notes']) > 255 || !$this->oneLine($f['retired_notes'])) {
            $e[] = 'Notes can be at most 255 characters on a single line.';
        }
        if ($this->hasWideChars($f['retired_notes'])) {
            $e[] = 'Emoji and other special symbols cannot be stored. Please remove them.';
        }
        return $e;
    }

    /** @return list<string> */
    private function validateTransfer(array $f, array $lookups): array
    {
        $e = [];
        if ($f['department_id'] !== 0 && (!in_array($f['department_id'], $lookups['departments'], true) || !$this->app->auth->canWrite($f['department_id']))) {
            $e[] = 'Choose a department you have write access to.';
        }
        if ($f['building_id'] !== 0 && !in_array($f['building_id'], $lookups['buildings'], true)) {
            $e[] = 'Choose a valid building.';
        }
        if ($this->len($f['room']) > 8 || !$this->oneLine($f['room'])) {
            $e[] = 'The room can be at most 8 characters.';
        }
        if ($this->len($f['reason']) > 16 || !$this->oneLine($f['reason'])) {
            $e[] = 'The reason can be at most 16 characters.';
        }
        if ($this->hasWideChars($f['room'], $f['reason'])) {
            $e[] = 'Emoji and other special symbols cannot be stored. Please remove them.';
        }
        if ($f['department_id'] === 0 && $f['building_id'] === 0 && $f['room'] === '') {
            $e[] = 'Choose at least one thing to change: a department, a building or a room.';
        }
        return $e;
    }

    /** @return int number of assets actually retired */
    private function applyRetire(array $targets, array $f): int
    {
        $db = $this->app->db;
        $now = date('Y-m-d H:i:s');
        $uid = (string) $this->app->auth->user()['user_id'];
        $changed = 0;
        foreach ($targets as $t) {
            $changed += $db->execute(
                'UPDATE assets SET retired = 1, retired_date = ?, retired_by = ?, disposal_method = ?, retired_notes = ?,
                        updated_date = ?, updated_by = ?, version = version + 1
                  WHERE asset_id = ? AND retired = 0',
                [$f['retire_date'] . ' 00:00:00', $uid, $f['disposal_method'], $f['retired_notes'] !== '' ? $f['retired_notes'] : null,
                 $now, $uid, $t['asset_id']]
            );
        }
        return $changed;
    }

    /** @return int number of assets actually moved (blank fields mean "keep each asset's current value") */
    private function applyTransfer(array $targets, array $f, array $lookups): int
    {
        $db = $this->app->db;
        $now = date('Y-m-d H:i:s');
        $uid = (string) $this->app->auth->user()['user_id'];
        $changed = 0;
        foreach ($targets as $t) {
            $dept = $f['department_id'] ?: (int) $t['department_id'];
            $building = $f['building_id'] ?: (int) $t['building_id'];
            $room = $f['room'] !== '' ? $f['room'] : (string) $t['room'];
            if ($dept === (int) $t['department_id'] && $building === (int) $t['building_id'] && $room === (string) $t['room']) {
                continue;
            }
            $n = $db->execute(
                'UPDATE assets SET department_id = ?, building_id = ?, room = ?, updated_date = ?, updated_by = ?, version = version + 1
                  WHERE asset_id = ? AND retired = 0',
                [$dept, $building, $room, $now, $uid, $t['asset_id']]
            );
            if ($n > 0) {
                $this->logTransfer(
                    (int) $t['asset_id'], $uid, $now, $f['reason'], $lookups,
                    (int) $t['department_id'], (int) $t['building_id'], (string) $t['room'], $dept, $building, $room
                );
                $changed++;
            }
        }
        return $changed;
    }

    private function bulkAction(Request $req): string
    {
        $action = (string) $req->post('action');
        if (!in_array($action, ['retire', 'transfer'], true)) {
            $this->app->error(400, 'Unknown action');
        }
        return $action;
    }

    private function bulkDefaults(): array
    {
        return [
            'retire_date' => date('Y-m-d'), 'disposal_method' => '', 'retired_notes' => '',
            'department_id' => 0, 'building_id' => 0, 'room' => '', 'reason' => '',
        ];
    }

    /**
     * Work out which of the posted assets a bulk action may touch.
     *
     * @param bool $allowSelectAll true on the review step: "all N matching" is re-run from the list's
     *             search/status filters. The apply step only ever uses the explicit ids the review showed.
     * @return array{0:list<array<string,mixed>>, 1:list<array{asset_number:string, why:string}>, 2:bool}
     *         [eligible assets, skipped assets with the reason, whether the selection was cut at BULK_LIMIT]
     */
    private function bulkTargets(Request $req, bool $allowSelectAll): array
    {
        $db = $this->app->db;
        $auth = $this->app->auth;
        $truncated = false;

        if ($allowSelectAll && $req->post('select_all') === '1') {
            [$where, $params] = $this->listWhere(trim((string) $req->query('q')), $this->statusFilter($req));
            $ids = array_map('intval', array_column(
                $db->all("SELECT a.asset_id FROM assets a WHERE {$where} ORDER BY a.asset_number LIMIT " . (self::BULK_LIMIT + 1), $params),
                'asset_id'
            ));
        } else {
            $ids = array_values(array_unique(array_filter(array_map('intval', $req->postArray('ids')), static fn(int $i): bool => $i > 0)));
        }
        if (count($ids) > self::BULK_LIMIT) {
            $ids = array_slice($ids, 0, self::BULK_LIMIT);
            $truncated = true;
        }
        if ($ids === []) {
            return [[], [], false];
        }

        [$scope, $scopeParams] = $auth->departmentScope('a.department_id');
        $rows = [];
        foreach (array_chunk($ids, 400) as $chunk) {
            $in = implode(',', array_fill(0, count($chunk), '?'));
            array_push($rows, ...$db->all(
                "SELECT a.asset_id, a.asset_number, a.description, a.department_id, a.building_id, a.room, a.retired,
                        d.abbr AS dept, b.abbr AS building
                   FROM assets a
                   LEFT JOIN departments d ON d.department_id = a.department_id
                   LEFT JOIN buildings b ON b.building_id = a.building_id
                  WHERE a.asset_id IN ({$in}) AND {$scope}",
                [...$chunk, ...$scopeParams]
            ));
        }
        usort($rows, static fn(array $x, array $y): int => strnatcasecmp((string) $x['asset_number'], (string) $y['asset_number']));

        $targets = [];
        $skipped = [];
        foreach ($rows as $r) {
            if (!$auth->canWrite((int) $r['department_id'])) {
                $skipped[] = ['asset_number' => (string) $r['asset_number'], 'why' => 'no write access'];
            } elseif (!empty($r['retired'])) {
                $skipped[] = ['asset_number' => (string) $r['asset_number'], 'why' => 'already retired'];
            } else {
                $targets[] = $r;
            }
        }
        return [$targets, $skipped, $truncated];
    }

    /** "3 no write access, 1 already retired" */
    private function skipSummary(array $skipped): string
    {
        $counts = array_count_values(array_column($skipped, 'why'));
        arsort($counts);
        return implode(', ', array_map(static fn(string $why, int $n): string => "{$n} {$why}", array_keys($counts), $counts));
    }

    private function bulkForm(string $action, array $targets, array $skipped, bool $truncated, array $f, array $errors): string
    {
        $writable = $this->app->auth->writableDepartmentIds();
        $departments = $this->app->db->all('SELECT department_id, abbr, name FROM departments ORDER BY abbr');
        return $this->render('bulk', [
            'title'       => $action === 'retire' ? 'Retire assets' : 'Transfer assets',
            'action'      => $action,
            'targets'     => $targets,
            'skipped'     => $skipped,
            'skipSummary' => $skipped === [] ? '' : $this->skipSummary($skipped),
            'truncated'   => $truncated,
            'bulkLimit'   => self::BULK_LIMIT,
            'f'           => $f,
            'errors'      => $errors,
            'methods'     => self::DISPOSAL_METHODS,
            'buildings'   => $this->app->db->all('SELECT building_id, abbr, name FROM buildings ORDER BY name'),
            'writableDepartments' => $writable === null
                ? $departments
                : array_values(array_filter($departments, static fn(array $d): bool => in_array((int) $d['department_id'], $writable, true))),
        ]);
    }

    // ------------------------------------------------------------- restore (admin)

    public function showRestore(Request $req, array $params): string
    {
        $this->rememberList($req);
        $a = $this->findRetired((int) $params['id']);
        $departments = $this->restoreDepartments();

        // Default to the department the asset was in before it was retired, if the history says so.
        $default = 0;
        $abbrs = array_column($departments, 'department_id', 'abbr');
        $from = $this->app->db->value(
            "SELECT department_from FROM transfers WHERE asset_id = ? AND department_to = 'RETIRE'
              ORDER BY transfer_date DESC, transfer_id DESC LIMIT 1",
            [$a['asset_id']]
        );
        if (isset($abbrs[(string) $from])) {
            $default = (int) $abbrs[(string) $from];
        } elseif (in_array((int) $a['department_id'], array_map('intval', array_column($departments, 'department_id')), true)) {
            $default = (int) $a['department_id'];
        }
        return $this->restoreForm($a, $default, [], $departments);
    }

    public function restore(Request $req, array $params): string
    {
        $this->rememberList($req);
        $a = $this->findRetired((int) $params['id']);
        $departments = $this->restoreDepartments();
        $deptId = (int) $req->post('department_id');

        if (!in_array($deptId, array_map('intval', array_column($departments, 'department_id')), true)) {
            http_response_code(422);
            return $this->restoreForm($a, 0, ['Choose the department this asset is returning to.'], $departments);
        }

        $db = $this->app->db;
        $pdo = $db->pdo();
        $now = date('Y-m-d H:i:s');
        $user = $this->app->auth->user();
        $lookups = $this->lookups();

        // Leave a trail in the notes (there is no separate retirement history table).
        $line = '[Restored ' . date('Y-m-d') . ' by ' . ($this->userName($user['user_id']) ?: $user['user_id'])
            . '; had been retired ' . (!empty($a['retired_date']) ? substr((string) $a['retired_date'], 0, 10) : '(date not recorded)')
            . (!empty($a['disposal_method']) ? ', ' . $a['disposal_method'] : '')
            . (!empty($a['retired_notes']) && strcasecmp((string) $a['retired_notes'], (string) $a['disposal_method']) !== 0
                ? ' - ' . $a['retired_notes'] : '') . ']';
        $notes = trim((string) $a['notes']) === '' ? $line : rtrim((string) $a['notes']) . "\n" . $line;
        if ($this->len($notes) > 10240) {
            $notes = (string) $a['notes']; // never fail a restore over a full notes field
        }

        $pdo->beginTransaction();
        try {
            $db->execute(
                'UPDATE assets SET retired = 0, retired_date = NULL, retired_by = NULL, disposal_method = NULL, retired_notes = NULL,
                        department_id = ?, notes = ?, updated_date = ?, updated_by = ?, version = version + 1
                  WHERE asset_id = ? AND retired = 1',
                [$deptId, $notes, $now, $user['user_id'], $a['asset_id']]
            );
            if ((int) $a['department_id'] !== $deptId) {
                $this->logTransfer(
                    (int) $a['asset_id'], (string) $user['user_id'], $now, 'Restored', $lookups,
                    (int) $a['department_id'], (int) $a['building_id'], (string) $a['room'],
                    $deptId, (int) $a['building_id'], (string) $a['room']
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('restore failed: ' . $e->getMessage());
            http_response_code(500);
            return $this->restoreForm($a, $deptId, ['The asset could not be restored because of a database error.'], $departments);
        }

        $this->app->session->flash('success', 'Asset ' . $a['asset_number'] . ' restored.');
        $this->redirect('/assets/' . (int) $a['asset_id'] . $this->listQs);
    }

    /** Departments an asset can return to: everything except the legacy RETIRE department. */
    private function restoreDepartments(): array
    {
        return $this->app->db->all("SELECT department_id, abbr, name FROM departments WHERE abbr <> 'RETIRE' ORDER BY abbr");
    }

    private function restoreForm(array $a, int $default, array $errors, array $departments): string
    {
        $cur = $this->app->db->one('SELECT abbr, name FROM departments WHERE department_id = ?', [$a['department_id']]);
        return $this->render('restore', [
            'title'       => 'Restore ' . $a['asset_number'],
            'a'           => $a,
            'currentDept' => $cur ? $cur['abbr'] . ' — ' . $cur['name'] : '—',
            'retiredBy'   => $this->userName($a['retired_by'] ?? null),
            'selected'    => $default,
            'errors'      => $errors,
            'departments' => $departments,
        ]);
    }

    // --------------------------------------------------------------- helpers

    private function len(string $s): int
    {
        return mb_strlen($s, 'UTF-8');
    }

    private function oneLine(string $s): bool
    {
        return !preg_match('/[\x00-\x1f\x7f]/', $s);
    }

    /** The text columns are utf8mb3: 4-byte characters (emoji) cannot be stored. */
    private function hasWideChars(string ...$texts): bool
    {
        return (bool) preg_match('/[\x{10000}-\x{10FFFF}]/u', implode('', $texts));
    }

    /** "First Last" for a user_id, falling back to the id itself; '' for none. */
    private function userName(mixed $userId): string
    {
        if ($userId === null || $userId === '') {
            return '';
        }
        $u = $this->app->db->one('SELECT firstname, lastname FROM users WHERE user_id = ?', [$userId]);
        return trim(($u['firstname'] ?? '') . ' ' . ($u['lastname'] ?? '')) ?: (string) $userId;
    }

    /** Editing needs the schema changes in REQUIRED_MIGRATIONS. */
    private function editingReady(): bool
    {
        if ($this->ready === null) {
            $this->ready = array_reduce(
                self::REQUIRED_MIGRATIONS,
                fn(bool $ok, string $m): bool => $ok && $this->app->migrationApplied($m),
                true
            );
        }
        return $this->ready;
    }

    private function retirementReady(): bool
    {
        return $this->app->migrationApplied(self::RETIREMENT_MIGRATION);
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

    /** Bulk retire/transfer: writer, plus the retirement columns must exist. */
    private function requireBulk(): void
    {
        $this->requireWriter();
        if (!$this->retirementReady()) {
            $this->app->error(503, 'Not available yet', 'The database needs updating first: an administrator must apply migration '
                . self::RETIREMENT_MIGRATION . ' on the Migrations page.');
        }
    }

    /**
     * The asset row, if the user may read it (404 otherwise) and write it (403 otherwise).
     * Retired assets are locked: the user is sent back to the asset page with an explanation.
     */
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
        if (!empty($a['retired'])) {
            $this->app->session->flash('danger', 'Asset ' . $a['asset_number'] . ' is retired, so it cannot be changed. An administrator can restore it first.');
            $this->redirect('/assets/' . $id . $this->listQs);
        }
        return $a;
    }

    /** A retired asset (admin-only pages). 404 if it does not exist; back to the asset if it is not retired. */
    private function findRetired(int $id): array
    {
        if (!$this->retirementReady()) {
            $this->app->error(503, 'Not available yet', 'Apply migration ' . self::RETIREMENT_MIGRATION . ' first.');
        }
        $a = $this->app->db->one('SELECT * FROM assets WHERE asset_id = ?', [$id]);
        if ($a === null) {
            $this->app->error(404, 'Asset not found');
        }
        if (empty($a['retired'])) {
            $this->app->session->flash('info', 'Asset ' . $a['asset_number'] . ' is not retired.');
            $this->redirect('/assets/' . $id . $this->listQs);
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
