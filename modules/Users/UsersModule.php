<?php

declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Module;
use App\Core\Request;
use App\Core\Router;

/**
 * User management (administrators only): list/search, create, edit, disable,
 * set passwords, and edit per-department permissions.
 */
final class UsersModule extends Module
{
    private const PER_PAGE = 25;
    private const ID = '\d+';
    private const MIN_PASSWORD = 10;

    /** Username of the disabled stand-in for people who no longer exist (created by migration 009). */
    public const PLACEHOLDER = 'missing-user';

    public function routes(Router $router): void
    {
        $admin = ['admin' => true];
        $router->get('/users', [$this, 'index'], $admin);
        // Fixed path first: "new" would also match the {uid} pattern below.
        $router->get('/users/new', [$this, 'showNew'], $admin);
        $router->post('/users/new', [$this, 'create'], $admin);
        $router->get('/users/{uid:' . self::ID . '}', [$this, 'showEdit'], $admin);
        $router->post('/users/{uid:' . self::ID . '}', [$this, 'update'], $admin);
        $router->post('/users/{uid:' . self::ID . '}/disable', [$this, 'disable'], $admin);
        $router->post('/users/{uid:' . self::ID . '}/enable', [$this, 'enable'], $admin);
        $router->get('/users/{uid:' . self::ID . '}/merge', [$this, 'showMerge'], $admin);
        $router->post('/users/{uid:' . self::ID . '}/merge', [$this, 'merge'], $admin);
    }

    public function nav(): array
    {
        return [['label' => 'Users', 'path' => '/users', 'icon' => 'users', 'order' => 80, 'admin' => true]];
    }

    // ---- list ----

    /** Keep the status filter (as well as search and page) when moving between the list and a user. */
    protected function rememberList(Request $req): void
    {
        $this->listQs = $this->listState(
            mb_substr(trim((string) $req->query('q')), 0, 64),
            max(1, (int) $req->query('page', '1')),
            ['status' => $this->statusParam($this->statusFilter($req))]
        );
    }

    /** The ?status= value to put in links: nothing for the default, and nothing at all before migration 004. */
    private function statusParam(string $status): ?string
    {
        return $status === 'active' || !$this->app->auth->supportsDisabling() ? null : $status;
    }

    /** 'active' (default), 'disabled' or 'all'. Always 'all' until migration 004 exists. */
    private function statusFilter(Request $req): string
    {
        if (!$this->app->auth->supportsDisabling()) {
            return 'all';
        }
        $s = (string) $req->query('status');
        return in_array($s, ['disabled', 'all'], true) ? $s : 'active';
    }

    public function index(Request $req): string
    {
        $db = $this->app->db;
        $search = trim((string) $req->query('q'));
        $page = max(1, (int) $req->query('page', '1'));
        $status = $this->statusFilter($req);
        $statusParam = $this->statusParam($status);

        if ($req->query('q') !== null && $search === '') {
            $this->redirect('/users' . $this->listState('', $page, ['status' => $statusParam]));
        }

        $where = '1 = 1';
        $params = [];
        if ($search !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $where = "(u.username LIKE ? ESCAPE '!' OR u.firstname LIKE ? ESCAPE '!'
                       OR u.lastname LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!')";
            $params = array_fill(0, 4, $like);
        }
        if ($status === 'active') {
            $where .= ' AND u.disabled = 0';
        } elseif ($status === 'disabled') {
            $where .= ' AND u.disabled = 1';
        }

        $total = (int) $db->value("SELECT COUNT(*) FROM users u WHERE {$where}", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE;
        $this->listQs = $this->listState($search, $page, ['status' => $statusParam]);

        $cols = 'u.user_id, u.username, u.firstname, u.lastname, u.email, u.admin, u.lastlogin, d.abbr AS dept'
            . ($this->app->auth->supportsDisabling() ? ', u.disabled' : '');
        $rows = $db->all(
            "SELECT {$cols},
                    (SELECT COUNT(*) FROM permissions p WHERE p.user_id = u.user_id) AS dept_count
               FROM users u
               LEFT JOIN departments d ON d.department_id = u.department_id
              WHERE {$where}
              ORDER BY u.lastname, u.firstname, u.username
              LIMIT " . self::PER_PAGE . " OFFSET {$offset}",
            $params
        );

        return $this->render('index', [
            'title'  => 'Users',
            'rows'   => $rows,
            'search' => $search,
            'page'   => $page,
            'pages'  => $pages,
            'total'  => $total,
            'status' => $status,
            'canDisable' => $this->app->auth->supportsDisabling(),
        ]);
    }

    // ---- disable / re-enable ----

    public function disable(Request $req, array $params): never
    {
        $this->setDisabled($req, (int) $params['uid'], true);
    }

    public function enable(Request $req, array $params): never
    {
        $this->setDisabled($req, (int) $params['uid'], false);
    }

    /** Revoke or restore a person's ability to sign in. Reversible, so no confirmation page. */
    private function setDisabled(Request $req, int $userId, bool $disable): never
    {
        $this->rememberList($req);
        $db = $this->app->db;
        $auth = $this->app->auth;
        $back = '/users/' . $userId . $this->listQs;

        if (!$auth->supportsDisabling()) {
            $this->app->session->flash('danger', 'Disabling users needs a database update. Apply the pending migrations first.');
            $this->redirect($back);
        }
        $user = $this->find($userId);
        $this->refusePlaceholder($user);

        if ($disable) {
            if ((int) $user['user_id'] === (int) $auth->user()['user_id']) {
                $this->app->session->flash('danger', 'You cannot disable your own account.');
                $this->redirect($back);
            }
            // Never leave the system without an active administrator.
            if ($user['admin'] && empty($user['disabled'])
                && (int) $db->value('SELECT COUNT(*) FROM users WHERE admin = 1 AND disabled = 0 AND user_id <> ?', [$user['user_id']]) === 0) {
                $this->app->session->flash('danger', 'This is the last active administrator and cannot be disabled.');
                $this->redirect($back);
            }
        }

        $db->execute('UPDATE users SET disabled = ? WHERE user_id = ?', [(int) $disable, $user['user_id']]);
        $name = trim($user['firstname'] . ' ' . $user['lastname']) ?: $user['username'];
        $this->app->session->flash('success', $disable
            ? $name . ' is disabled and can no longer sign in. Their account, permissions and history are unchanged.'
            : $name . ' is enabled and can sign in again.');
        $this->redirect($back);
    }

    // ---- merge a duplicate account into another ----

    /** Step 1: show exactly what merging this user into another would move. Nothing is written. */
    public function showMerge(Request $req, array $params): string
    {
        $this->rememberList($req);
        [$source, $target] = $this->mergePair((int) $params['uid'], (int) $req->query('into'));

        return $this->render('merge', [
            'title'  => 'Merge users',
            'source' => $source,
            'target' => $target,
            'counts' => $this->mergeCounts((int) $source['user_id']),
            'perms'  => $this->mergePermissions((int) $source['user_id'], (int) $target['user_id']),
        ]);
    }

    /**
     * Step 2: move everything the duplicate owns to the account being kept, then delete the duplicate.
     * One transaction: either all of it happens or none of it.
     */
    public function merge(Request $req, array $params): never
    {
        $this->rememberList($req);
        $db = $this->app->db;
        $fromId = (int) $params['uid'];
        $toId = (int) $req->post('into');
        [$source, $target] = $this->mergePair($fromId, $toId);

        if ($req->post('ack') !== '1') {
            $this->app->session->flash('danger', 'Tick the box to confirm you have a backup and want these accounts merged.');
            $this->redirect('/users/' . $fromId . '/merge' . $this->mergeQs($toId));
        }

        $counts = $this->mergeCounts($fromId);
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            foreach (['assets' => ['user_id', 'updated_by', 'retired_by'], 'transfers' => ['user_id']] as $table => $columns) {
                foreach ($columns as $column) { // names are constants above, never from the request
                    $db->execute("UPDATE `{$table}` SET `{$column}` = ? WHERE `{$column}` = ?", [$toId, $fromId]);
                }
            }
            foreach ($this->mergePermissions($fromId, $toId) as $p) {
                if ($p['tgt'] === null) {
                    $db->execute('INSERT INTO permissions (user_id, department_id, permission) VALUES (?, ?, ?)', [$toId, $p['department_id'], $p['result']]);
                } elseif ($p['tgt'] !== $p['result']) {
                    $db->execute('UPDATE permissions SET permission = ? WHERE user_id = ? AND department_id = ?', [$p['result'], $toId, $p['department_id']]);
                }
            }
            $db->execute('DELETE FROM permissions WHERE user_id = ?', [$fromId]);
            $db->execute('DELETE FROM users WHERE user_id = ?', [$fromId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('users merge failed: ' . $e->getMessage());
            $this->app->session->flash('danger', 'The accounts could not be merged because of a database error. Nothing was changed.');
            $this->redirect('/users/' . $fromId . $this->listQs);
        }

        $moved = $counts['added'] + $counts['changed'] + $counts['retired'] + $counts['transfers'];
        $this->app->session->flash('success', 'Merged "' . $source['username'] . '" into "' . $target['username'] . '": '
            . number_format($moved) . ' record' . ($moved === 1 ? '' : 's') . ' moved, and "' . $source['username'] . '" was deleted.');
        $this->redirect('/users/' . $toId . $this->listQs);
    }

    /**
     * Validate a merge request. Returns [duplicate, account to keep]; otherwise explains why not and goes back.
     *
     * @return array{0: array<string,mixed>, 1: array<string,mixed>}
     */
    private function mergePair(int $fromId, int $toId): array
    {
        $source = $this->find($fromId);
        $this->refusePlaceholder($source);
        $back = '/users/' . $fromId . $this->listQs;
        $stop = function (string $message) use ($back): never {
            $this->app->session->flash('danger', $message);
            $this->redirect($back);
        };

        $target = $toId > 0 ? $this->app->db->one('SELECT * FROM users WHERE user_id = ?', [$toId]) : null;
        if ($target === null || $target['username'] === self::PLACEHOLDER) {
            $stop('Choose the account to keep.');
        }
        unset($target['password']);
        if ($fromId === $toId) {
            $stop('Choose a different account to keep.');
        }
        if ($fromId === (int) $this->app->auth->user()['user_id']) {
            $stop('You cannot merge the account you are signed in with, because it would be deleted. Sign in as the account you want to keep, then merge the duplicate into it.');
        }
        // No last-administrator check is needed: the signed-in administrator is never the duplicate (refused above),
        // so at least one active administrator always remains.
        return [$source, $target];
    }

    /** @return array{added:int, changed:int, retired:int, transfers:int} how much is attached to this user */
    private function mergeCounts(int $userId): array
    {
        $db = $this->app->db;
        return [
            'added'     => (int) $db->value('SELECT COUNT(*) FROM assets WHERE user_id = ?', [$userId]),
            'changed'   => (int) $db->value('SELECT COUNT(*) FROM assets WHERE updated_by = ?', [$userId]),
            'retired'   => (int) $db->value('SELECT COUNT(*) FROM assets WHERE retired_by = ?', [$userId]),
            'transfers' => (int) $db->value('SELECT COUNT(*) FROM transfers WHERE user_id = ?', [$userId]),
        ];
    }

    /**
     * Department access after a merge: each department the duplicate had, with the higher of the two levels.
     *
     * @return list<array{department_id:int, abbr:string, name:string, src:string, tgt:?string, result:string}>
     */
    private function mergePermissions(int $fromId, int $toId): array
    {
        $rows = $this->app->db->all(
            'SELECT ps.department_id, ps.permission AS src, pt.permission AS tgt, d.abbr, d.name
               FROM permissions ps
               LEFT JOIN permissions pt ON pt.department_id = ps.department_id AND pt.user_id = ?
               LEFT JOIN departments d ON d.department_id = ps.department_id
              WHERE ps.user_id = ?
              ORDER BY d.abbr',
            [$toId, $fromId]
        );
        $out = [];
        foreach ($rows as $r) {
            $src = (string) $r['src'];
            $tgt = $r['tgt'] === null ? null : (string) $r['tgt'];
            $out[] = [
                'department_id' => (int) $r['department_id'],
                'abbr'          => (string) ($r['abbr'] ?? '?'),
                'name'          => (string) ($r['name'] ?? '(department no longer exists)'),
                'src'           => $src,
                'tgt'           => $tgt,
                'result'        => $src === 'rw' || $tgt === 'rw' ? 'rw' : 'r',
            ];
        }
        return $out;
    }

    /** ?into=…, plus the list state, for links back to the review page. */
    private function mergeQs(int $toId): string
    {
        return '?into=' . $toId . ($this->listQs !== '' ? '&' . ltrim($this->listQs, '?') : '');
    }

    // ---- create / edit forms ----

    public function showNew(Request $req): string
    {
        $this->rememberList($req);
        return $this->form(null, [
            'user_id' => null, 'username' => '', 'firstname' => '', 'lastname' => '', 'email' => '',
            'department_id' => 0, 'timezone' => 'UTC', 'admin' => false, 'disabled' => false,
        ], [], []);
    }

    public function showEdit(Request $req, array $params): string
    {
        $this->rememberList($req);
        $user = $this->find((int) $params['uid']);
        $this->refusePlaceholder($user);
        $perms = [];
        foreach ($this->app->db->all('SELECT department_id, permission FROM permissions WHERE user_id = ?', [$user['user_id']]) as $p) {
            $perms[(int) $p['department_id']] = (string) $p['permission'];
        }
        return $this->form($user, [
            'user_id'       => (int) $user['user_id'],
            'username'      => $user['username'],
            'firstname'     => $user['firstname'],
            'lastname'      => $user['lastname'],
            'email'         => $user['email'],
            'department_id' => (int) $user['department_id'],
            'timezone'      => $user['timezone'],
            'admin'         => (bool) $user['admin'],
            'disabled'      => !empty($user['disabled']),
        ], $perms, []);
    }

    public function create(Request $req): string
    {
        $this->rememberList($req);
        return $this->save($req, null);
    }

    public function update(Request $req, array $params): string
    {
        $this->rememberList($req);
        $user = $this->find((int) $params['uid']);
        $this->refusePlaceholder($user);
        return $this->save($req, $user);
    }

    // ---- saving ----

    private function save(Request $req, ?array $existing): string
    {
        $db = $this->app->db;
        $auth = $this->app->auth;
        $isNew = $existing === null;
        $isSelf = !$isNew && (int) $existing['user_id'] === (int) $auth->user()['user_id'];
        $canDisable = $auth->supportsDisabling();

        $f = [
            'user_id'       => $isNew ? null : (int) $existing['user_id'], // assigned by the database for new users
            'username'      => trim((string) $req->post('username')),
            'firstname'     => trim((string) $req->post('firstname')),
            'lastname'      => trim((string) $req->post('lastname')),
            'email'         => trim((string) $req->post('email')),
            'department_id' => (int) $req->post('department_id'),
            'timezone'      => (string) $req->post('timezone'),
            'admin'         => $req->post('admin') === '1',
            // Disabling and re-enabling have their own buttons; saving the form never changes it.
            'disabled'      => !$isNew && !empty($existing['disabled']),
        ];
        if ($isSelf) { // you can't remove your own admin access; the form doesn't offer it for your own account
            $f['admin'] = true;
        }
        $password = (string) $req->post('password');
        $confirm = (string) $req->post('password2');

        $departments = $this->departments();
        $deptIds = array_map('intval', array_column($departments, 'department_id'));
        $perms = [];
        $posted = $req->postArray('perm');
        foreach ($deptIds as $id) {
            if (in_array($posted[(string) $id] ?? '', ['r', 'rw'], true)) {
                $perms[$id] = $posted[(string) $id];
            }
        }

        // ---- validation ----
        $errors = [];
        if ($f['username'] === '' || strlen($f['username']) > 64 || preg_match('/[\x00-\x1f\x7f]/', $f['username'])) {
            $errors[] = 'Enter a username (up to 64 characters, no control characters).';
        } elseif (strtolower($f['username']) === self::PLACEHOLDER) {
            $errors[] = 'That username is reserved.';
        } elseif ($db->one('SELECT 1 FROM users WHERE username = ? AND user_id <> ?', [$f['username'], (int) $f['user_id']]) !== null) {
            $errors[] = 'That username belongs to another user.';
        }
        if (strlen($f['firstname']) > 32 || strlen($f['lastname']) > 32) {
            $errors[] = 'First and last names can be at most 32 characters.';
        }
        if (strlen($f['email']) > 128 || ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL))) {
            $errors[] = 'Enter a valid email address (or leave it blank).';
        }
        if ($f['department_id'] !== 0 && !in_array($f['department_id'], $deptIds, true)) {
            $errors[] = 'Choose a valid home department.';
        }
        if (!in_array($f['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            $errors[] = 'Choose a valid time zone.';
        }
        if ($isNew || $password !== '') {
            if (strlen($password) < self::MIN_PASSWORD) {
                $errors[] = 'The password must be at least ' . self::MIN_PASSWORD . ' characters.';
            } elseif ($password !== $confirm) {
                $errors[] = 'The password and its confirmation do not match.';
            }
        }
        // Never leave the system without an active administrator.
        if (!$isNew && $existing['admin'] && empty($existing['disabled']) && !$f['admin']) {
            $others = (int) $db->value(
                'SELECT COUNT(*) FROM users WHERE admin = 1 AND user_id <> ?' . ($canDisable ? ' AND disabled = 0' : ''),
                [$existing['user_id']]
            );
            if ($others === 0) {
                $errors[] = 'This is the last active administrator; it cannot be demoted.';
            }
        }

        if ($errors === []) {
            try {
                $this->persist($isNew, $f, $password, $perms);
                $this->app->session->flash('success', 'User "' . $f['username'] . '" ' . ($isNew ? 'created.' : 'saved.'));
                $this->redirect('/users' . $this->listQs);
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
            } catch (\PDOException $e) {
                error_log('users save failed: ' . $e->getMessage());
                $errors[] = 'The user could not be saved because of a database error.';
            }
        }

        http_response_code(422);
        return $this->form($existing, $f, $perms, $errors);
    }

    /**
     * Write the user and their permission rows in one transaction.
     *
     * @return int the user's id (newly assigned for a new user)
     * @throws \RuntimeException if the password hash could not be stored intact
     */
    private function persist(bool $isNew, array $f, string $password, array $perms): int
    {
        $db = $this->app->db;
        $pdo = $db->pdo();
        $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
        $pdo->beginTransaction();
        try {
            if ($isNew) {
                // user_id is auto-incremented. lastlogin is NOT NULL: the epoch means "never signed in".
                // A hash is always supplied, so the column's weak legacy default password can never apply.
                $db->execute(
                    'INSERT INTO users (username, password, firstname, lastname, email, department_id, admin, timezone, lastlogin)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$f['username'], $hash, $f['firstname'], $f['lastname'], $f['email'],
                     $f['department_id'], (int) $f['admin'], $f['timezone'], '1970-01-01 00:00:00']
                );
                $f['user_id'] = (int) $pdo->lastInsertId();
            } else {
                $db->execute(
                    'UPDATE users SET username = ?, firstname = ?, lastname = ?, email = ?, department_id = ?, admin = ?, timezone = ?
                      WHERE user_id = ?',
                    [$f['username'], $f['firstname'], $f['lastname'], $f['email'], $f['department_id'],
                     (int) $f['admin'], $f['timezone'], $f['user_id']]
                );
                if ($hash !== null) {
                    $db->execute('UPDATE users SET password = ? WHERE user_id = ?', [$hash, $f['user_id']]);
                }
            }
            if ($hash !== null && $db->value('SELECT password FROM users WHERE user_id = ?', [$f['user_id']]) !== $hash) {
                throw new \RuntimeException('The users.password column is too narrow for secure password hashes. Apply migration 001 on the Migrations page first.');
            }

            $db->execute('DELETE FROM permissions WHERE user_id = ?', [$f['user_id']]);
            foreach ($perms as $deptId => $level) {
                $db->execute('INSERT INTO permissions (user_id, department_id, permission) VALUES (?, ?, ?)', [$f['user_id'], $deptId, $level]);
            }
            $pdo->commit();
            return (int) $f['user_id'];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ---- helpers ----

    /** @return array<string,mixed> the user row (without password); 404 if there is none */
    private function find(int $userId): array
    {
        $user = $this->app->db->one('SELECT * FROM users WHERE user_id = ?', [$userId]);
        if ($user === null) {
            $this->app->error(404, 'User not found');
        }
        unset($user['password']);
        return $user;
    }

    /** The "Missing user" stand-in is system-managed: it cannot be edited, enabled or used to sign in. */
    private function refusePlaceholder(array $user): void
    {
        if ($user['username'] === self::PLACEHOLDER) {
            $this->app->session->flash('info', '"Missing user" is a placeholder for people who no longer exist, so it cannot be edited.');
            $this->redirect('/users' . $this->listQs);
        }
    }

    /** @return list<array{department_id:int|string, abbr:string, name:string}> */
    private function departments(): array
    {
        return $this->app->db->all('SELECT department_id, abbr, name FROM departments ORDER BY abbr');
    }

    private function form(?array $existing, array $f, array $perms, array $errors): string
    {
        $self = $existing !== null && (int) $existing['user_id'] === (int) $this->app->auth->user()['user_id'];
        return $this->render('form', [
            'title'       => $existing === null ? 'New user' : 'Edit user',
            'isNew'       => $existing === null,
            'isSelf'      => $self,
            'user'        => $existing,
            'f'           => $f,
            'perms'       => $perms,
            'errors'      => $errors,
            'departments' => $this->departments(),
            'timezones'   => \DateTimeZone::listIdentifiers(),
            'canDisable'  => $this->app->auth->supportsDisabling(),
            'minPassword' => self::MIN_PASSWORD,
            'mergeTargets' => $existing === null ? [] : $this->app->db->all(
                'SELECT user_id, username, firstname, lastname' . ($this->app->auth->supportsDisabling() ? ', disabled' : '')
                . ' FROM users WHERE user_id <> ? AND username <> ? ORDER BY username',
                [$existing['user_id'], self::PLACEHOLDER]
            ),
        ]);
    }
}
