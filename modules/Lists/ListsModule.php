<?php

declare(strict_types=1);

namespace App\Modules\Lists;

use App\Core\Module;
use App\Core\Request;
use App\Core\Router;

/**
 * Departments and asset types (administrators only): add, rename and delete them.
 *
 * Rules that protect the data:
 *  - A department is tied to the transfer history by its ABBREVIATION (the history stores "IT", not an id), so
 *    renaming an abbreviation also updates those history rows, in the same transaction.
 *  - The legacy "RETIRE" department is what retiring and restoring are recorded against: it cannot be edited or
 *    deleted, and no other department may take that abbreviation.
 *  - Nothing that assets (or users' home departments) still point at can be deleted. Deleting a department that
 *    only has department-access rows and history removes the access rows; the history keeps its text.
 */
final class ListsModule extends Module
{
    /** Abbreviation of the legacy department that retirements are recorded against (see AssetsModule). */
    public const RESERVED_ABBR = 'RETIRE';

    private const NAME_MAX = 64;
    private const ABBR_MAX = 6;
    private const YEARS_MAX = 100;

    /** kind (the URL segment) => table, key column and wording */
    private const KINDS = [
        'departments' => ['table' => 'departments', 'pk' => 'department_id', 'one' => 'department', 'One' => 'Department'],
        'types'       => ['table' => 'asset_types', 'pk' => 'type_id', 'one' => 'asset type', 'One' => 'Asset type'],
    ];

    public function routes(Router $router): void
    {
        $admin = ['admin' => true];
        $k = '{kind:departments|types}';
        $router->get('/lists', [$this, 'index'], $admin);
        // Fixed paths first: "new" would also match the {id} pattern.
        $router->get("/lists/{$k}/new", [$this, 'showNew'], $admin);
        $router->post("/lists/{$k}/new", [$this, 'create'], $admin);
        $router->get("/lists/{$k}/{id:\\d+}", [$this, 'showEdit'], $admin);
        $router->post("/lists/{$k}/{id:\\d+}", [$this, 'update'], $admin);
        $router->get("/lists/{$k}/{id:\\d+}/delete", [$this, 'showDelete'], $admin);
        $router->post("/lists/{$k}/{id:\\d+}/delete", [$this, 'delete'], $admin);
    }

    public function nav(): array
    {
        return [['label' => 'Departments & types', 'path' => '/lists', 'icon' => 'layers', 'order' => 82, 'admin' => true]];
    }

    // ------------------------------------------------------------ the overview

    public function index(Request $req): string
    {
        $db = $this->app->db;
        $count = static fn(array $rows, string $key): array => array_column($rows, 'n', $key);

        $depts = $db->all('SELECT department_id, abbr, name FROM departments ORDER BY abbr');
        $inDept = $count($db->all('SELECT department_id, COUNT(*) AS n FROM assets GROUP BY department_id'), 'department_id');
        $users = $count($db->all('SELECT department_id, COUNT(*) AS n FROM users GROUP BY department_id'), 'department_id');
        $access = $count($db->all('SELECT department_id, COUNT(*) AS n FROM permissions GROUP BY department_id'), 'department_id');
        foreach ($depts as &$d) {
            $id = $d['department_id'];
            $d['assets'] = (int) ($inDept[$id] ?? 0);
            $d['users'] = (int) ($users[$id] ?? 0);
            $d['access'] = (int) ($access[$id] ?? 0);
            $d['locked'] = $this->isReserved($d);
        }
        unset($d);

        $types = $db->all('SELECT type_id, name, depreciation_years FROM asset_types ORDER BY name');
        $ofType = $count($db->all('SELECT type_id, COUNT(*) AS n FROM assets GROUP BY type_id'), 'type_id');
        foreach ($types as &$t) {
            $t['assets'] = (int) ($ofType[$t['type_id']] ?? 0);
        }
        unset($t);

        return $this->render('index', ['title' => 'Departments & types', 'departments' => $depts, 'types' => $types]);
    }

    // ------------------------------------------------------------ add / edit

    public function showNew(Request $req, array $params): string
    {
        $kind = $this->kind($params);
        return $this->form($kind, null, $kind === 'departments' ? ['abbr' => '', 'name' => ''] : ['name' => '', 'years' => '0'], []);
    }

    public function create(Request $req, array $params): string
    {
        return $this->save($req, $this->kind($params), null);
    }

    public function showEdit(Request $req, array $params): string
    {
        $kind = $this->kind($params);
        $row = $this->find($kind, (int) $params['id']);
        $this->refuseReserved($kind, $row);
        return $this->form($kind, $row, $kind === 'departments'
            ? ['abbr' => (string) $row['abbr'], 'name' => (string) $row['name']]
            : ['name' => (string) $row['name'], 'years' => (string) $row['depreciation_years']], []);
    }

    public function update(Request $req, array $params): string
    {
        $kind = $this->kind($params);
        $row = $this->find($kind, (int) $params['id']);
        $this->refuseReserved($kind, $row);
        return $this->save($req, $kind, $row);
    }

    private function save(Request $req, string $kind, ?array $existing): string
    {
        $db = $this->app->db;
        $pdo = $db->pdo();
        $isNew = $existing === null;
        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $req->post('name')));
        $errors = [];

        // ---- name (both kinds): required, one line, short, storable, and not a copy of another one
        if ($name === '' || mb_strlen($name) > self::NAME_MAX || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            $errors[] = 'Enter a name of up to ' . self::NAME_MAX . ' characters.';
        } elseif ($this->hasWideChars($name)) {
            $errors[] = 'Emoji and other special symbols cannot be stored. Please remove them.';
        } elseif (($isNew || mb_strtolower($name) !== mb_strtolower((string) $existing['name'])) && $this->nameTaken($kind, $name, (int) ($existing[self::KINDS[$kind]['pk']] ?? 0))) {
            $errors[] = 'Another ' . self::KINDS[$kind]['one'] . ' already has that name.';
        }

        if ($kind === 'departments') {
            $abbr = strtoupper(trim((string) $req->post('abbr')));
            $values = ['abbr' => $abbr, 'name' => $name];
            if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,' . (self::ABBR_MAX - 1) . '}$/', $abbr)) {
                $errors[] = 'The abbreviation is 1 to ' . self::ABBR_MAX . ' letters, digits, hyphens or underscores, with no spaces.';
            } elseif ($abbr === self::RESERVED_ABBR) {
                $errors[] = '"' . self::RESERVED_ABBR . '" is reserved: retired assets are recorded against it.';
            } elseif (($isNew || $abbr !== strtoupper((string) $existing['abbr'])) && $this->abbrTaken($abbr, (int) ($existing['department_id'] ?? 0))) {
                $errors[] = 'Another department already uses that abbreviation.';
            }
        } else {
            $yearsRaw = trim((string) $req->post('years'));
            $values = ['name' => $name, 'years' => $yearsRaw];
            if (!ctype_digit($yearsRaw) || (int) $yearsRaw > self::YEARS_MAX) {
                $errors[] = 'Depreciation is a whole number of years from 0 to ' . self::YEARS_MAX . ' (0 for none).';
            }
        }

        if ($errors === []) {
            $pdo->beginTransaction();
            try {
                $note = '';
                if ($kind === 'departments') {
                    if ($isNew) {
                        $db->execute('INSERT INTO departments (abbr, name) VALUES (?, ?)', [$values['abbr'], $values['name']]);
                    } else {
                        $old = (string) $existing['abbr'];
                        $db->execute('UPDATE departments SET abbr = ?, name = ? WHERE department_id = ?', [$values['abbr'], $values['name'], $existing['department_id']]);
                        if ($old !== $values['abbr']) {
                            // The history refers to a department by abbreviation text: keep it pointing at this one.
                            $n = $db->execute('UPDATE transfers SET department_from = ? WHERE department_from = ?', [$values['abbr'], $old])
                               + $db->execute('UPDATE transfers SET department_to = ? WHERE department_to = ?', [$values['abbr'], $old]);
                            $note = $n > 0 ? ' The abbreviation was also updated in ' . $n . ' transfer history entr' . ($n === 1 ? 'y' : 'ies') . '.' : '';
                        }
                    }
                } elseif ($isNew) {
                    $db->execute('INSERT INTO asset_types (name, depreciation_years) VALUES (?, ?)', [$values['name'], (int) $values['years']]);
                } else {
                    $db->execute('UPDATE asset_types SET name = ?, depreciation_years = ? WHERE type_id = ?', [$values['name'], (int) $values['years'], $existing['type_id']]);
                }
                $pdo->commit();
            } catch (\PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('lists save failed: ' . $e->getMessage());
                $errors[] = str_contains($e->getMessage(), 'Incorrect string value')
                    ? 'That text contains characters the database cannot store. Please simplify it.'
                    : 'It could not be saved because of a database error.';
                http_response_code(500);
                return $this->form($kind, $existing, $values, $errors);
            }
            $label = $kind === 'departments' ? $values['abbr'] : $values['name'];
            $this->app->session->flash('success', self::KINDS[$kind]['One'] . ' "' . $label . '" ' . ($isNew ? 'added.' : 'saved.') . $note);
            $this->redirect('/lists');
        }

        http_response_code(422);
        return $this->form($kind, $existing, $values, $errors);
    }

    // ------------------------------------------------------------ delete

    public function showDelete(Request $req, array $params): string
    {
        $kind = $this->kind($params);
        $row = $this->find($kind, (int) $params['id']);
        $this->refuseReserved($kind, $row);
        return $this->render('delete', ['title' => 'Delete ' . self::KINDS[$kind]['one'], 'kind' => $kind, 'row' => $row, 'use' => $this->usage($kind, $row)]
            + ['meta' => self::KINDS[$kind]]);
    }

    public function delete(Request $req, array $params): never
    {
        $kind = $this->kind($params);
        $row = $this->find($kind, (int) $params['id']);
        $this->refuseReserved($kind, $row);
        $use = $this->usage($kind, $row);
        $label = $kind === 'departments' ? (string) $row['abbr'] : (string) $row['name'];

        if ($use['blockers'] !== []) { // re-checked here: the confirmation page is not trusted
            $this->app->session->flash('danger', self::KINDS[$kind]['One'] . ' "' . $label . '" is still in use and was not deleted: ' . implode('; ', $use['blockers']) . '.');
            $this->redirect('/lists/' . $kind . '/' . (int) $row[self::KINDS[$kind]['pk']] . '/delete');
        }

        $db = $this->app->db;
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            if ($kind === 'departments') {
                $db->execute('DELETE FROM permissions WHERE department_id = ?', [$row['department_id']]);
                $db->execute('DELETE FROM departments WHERE department_id = ?', [$row['department_id']]);
            } else {
                $db->execute('DELETE FROM asset_types WHERE type_id = ?', [$row['type_id']]);
            }
            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('lists delete failed: ' . $e->getMessage());
            $this->app->session->flash('danger', 'It could not be deleted because of a database error. Nothing was changed.');
            $this->redirect('/lists');
        }
        $this->app->session->flash('success', self::KINDS[$kind]['One'] . ' "' . $label . '" deleted.');
        $this->redirect('/lists');
    }

    // ------------------------------------------------------------ helpers

    private function kind(array $params): string
    {
        $kind = (string) ($params['kind'] ?? '');
        if (!isset(self::KINDS[$kind])) {
            $this->app->error(404, 'Page not found');
        }
        return $kind;
    }

    /** @return array<string,mixed> the row; 404 if there is none */
    private function find(string $kind, int $id): array
    {
        $k = self::KINDS[$kind];
        $row = $this->app->db->one("SELECT * FROM `{$k['table']}` WHERE `{$k['pk']}` = ?", [$id]);
        if ($row === null) {
            $this->app->error(404, ucfirst($k['one']) . ' not found');
        }
        return $row;
    }

    private function isReserved(array $dept): bool
    {
        return strtoupper((string) $dept['abbr']) === self::RESERVED_ABBR;
    }

    private function refuseReserved(string $kind, array $row): void
    {
        if ($kind === 'departments' && $this->isReserved($row)) {
            $this->app->session->flash('info', '"' . self::RESERVED_ABBR . '" is a system department: retiring and restoring assets is recorded against it, so it cannot be changed or deleted.');
            $this->redirect('/lists');
        }
    }

    private function nameTaken(string $kind, string $name, int $exceptId): bool
    {
        $k = self::KINDS[$kind];
        return $this->app->db->one("SELECT 1 FROM `{$k['table']}` WHERE LOWER(name) = LOWER(?) AND `{$k['pk']}` <> ?", [$name, $exceptId]) !== null;
    }

    private function abbrTaken(string $abbr, int $exceptId): bool
    {
        return $this->app->db->one('SELECT 1 FROM departments WHERE LOWER(abbr) = LOWER(?) AND department_id <> ?', [$abbr, $exceptId]) !== null;
    }

    /** Characters outside the Basic Multilingual Plane (emoji): the legacy text columns cannot hold them. */
    private function hasWideChars(string $s): bool
    {
        return preg_match('/[\x{10000}-\x{10FFFF}]/u', $s) === 1;
    }

    /**
     * What points at this row. "blockers" stop a delete; "notes" are consequences of one.
     *
     * @return array{blockers:list<string>, notes:list<string>, counts:array<string,int>}
     */
    private function usage(string $kind, array $row): array
    {
        $db = $this->app->db;
        $n = static fn(mixed $v): int => (int) $v;
        $plural = static fn(int $c, string $word): string => number_format($c) . ' ' . $word . ($c === 1 ? '' : 's');

        if ($kind === 'types') {
            $assets = $n($db->value('SELECT COUNT(*) FROM assets WHERE type_id = ?', [$row['type_id']]));
            return [
                'blockers' => $assets > 0 ? [$plural($assets, 'asset') . ' ' . ($assets === 1 ? 'has' : 'have') . ' this type'] : [],
                'notes'    => [],
                'counts'   => ['assets' => $assets],
            ];
        }

        $id = $row['department_id'];
        $inDept = $n($db->value('SELECT COUNT(*) FROM assets WHERE department_id = ?', [$id]));
        $bought = $n($db->value('SELECT COUNT(*) FROM assets WHERE purchaser_id = ?', [$id]));
        $users = $n($db->value('SELECT COUNT(*) FROM users WHERE department_id = ?', [$id]));
        $access = $n($db->value('SELECT COUNT(*) FROM permissions WHERE department_id = ?', [$id]));
        $history = $n($db->value('SELECT COUNT(*) FROM transfers WHERE department_from = ? OR department_to = ?', [$row['abbr'], $row['abbr']]));

        $blockers = [];
        if ($inDept > 0) {
            $blockers[] = $plural($inDept, 'asset') . ' ' . ($inDept === 1 ? 'is' : 'are') . ' in it (move ' . ($inDept === 1 ? 'it' : 'them') . ' to another department first)';
        }
        if ($bought > 0) {
            $blockers[] = $plural($bought, 'asset') . ' ' . ($bought === 1 ? 'was' : 'were') . ' bought by it (change the purchasing department on ' . ($bought === 1 ? 'that asset' : 'those assets') . ' first)';
        }
        if ($users > 0) {
            $blockers[] = $plural($users, 'user') . ' ' . ($users === 1 ? 'has' : 'have') . ' it as their home department';
        }
        $notes = [];
        if ($access > 0) {
            $notes[] = 'Department access for ' . $plural($access, 'user') . ' will be removed.';
        }
        if ($history > 0) {
            $notes[] = $plural($history, 'transfer history entry') . ' mention' . ($history === 1 ? 's' : '') . ' "' . $row['abbr'] . '"; the history keeps that text.';
        }
        return ['blockers' => $blockers, 'notes' => $notes, 'counts' => ['assets' => $inDept, 'bought' => $bought, 'users' => $users, 'access' => $access, 'history' => $history]];
    }

    private function form(string $kind, ?array $existing, array $values, array $errors): string
    {
        $isNew = $existing === null;
        $use = $isNew ? null : $this->usage($kind, $existing);
        return $this->render('form', [
            'title'    => ($isNew ? 'Add ' : 'Edit ') . self::KINDS[$kind]['one'],
            'kind'     => $kind,
            'meta'     => self::KINDS[$kind],
            'isNew'    => $isNew,
            'row'      => $existing,
            'v'        => $values,
            'errors'   => $errors,
            'use'      => $use,
            'nameMax'  => self::NAME_MAX,
            'abbrMax'  => self::ABBR_MAX,
            'yearsMax' => self::YEARS_MAX,
        ]);
    }
}
