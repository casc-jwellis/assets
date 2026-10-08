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
    private const ID = '[A-Za-z0-9._@-]+';
    private const MIN_PASSWORD = 10;

    public function routes(Router $router): void
    {
        $admin = ['admin' => true];
        $router->get('/users', [$this, 'index'], $admin);
        // Fixed path first: "new" would also match the {uid} pattern below.
        $router->get('/users/new', [$this, 'showNew'], $admin);
        $router->post('/users/new', [$this, 'create'], $admin);
        $router->get('/users/{uid:' . self::ID . '}', [$this, 'showEdit'], $admin);
        $router->post('/users/{uid:' . self::ID . '}', [$this, 'update'], $admin);
    }

    public function nav(): array
    {
        return [['label' => 'Users', 'path' => '/users', 'icon' => 'users', 'order' => 80, 'admin' => true]];
    }

    // ---- list ----

    public function index(Request $req): string
    {
        $db = $this->app->db;
        $search = trim((string) $req->query('q'));
        $page = max(1, (int) $req->query('page', '1'));

        if ($req->query('q') !== null && $search === '') {
            $this->redirect('/users' . ($page > 1 ? '?page=' . $page : ''));
        }

        $where = '1 = 1';
        $params = [];
        if ($search !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
            $where = "(u.user_id LIKE ? ESCAPE '!' OR u.username LIKE ? ESCAPE '!' OR u.firstname LIKE ? ESCAPE '!'
                       OR u.lastname LIKE ? ESCAPE '!' OR u.email LIKE ? ESCAPE '!')";
            $params = array_fill(0, 5, $like);
        }

        $total = (int) $db->value("SELECT COUNT(*) FROM users u WHERE {$where}", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE;
        $this->listQs = $this->listState($search, $page);

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
            'canDisable' => $this->app->auth->supportsDisabling(),
        ]);
    }

    // ---- create / edit forms ----

    public function showNew(Request $req): string
    {
        $this->rememberList($req);
        return $this->form(null, [
            'user_id' => '', 'username' => '', 'firstname' => '', 'lastname' => '', 'email' => '',
            'department_id' => 0, 'timezone' => 'UTC', 'admin' => false, 'disabled' => false,
        ], [], []);
    }

    public function showEdit(Request $req, array $params): string
    {
        $this->rememberList($req);
        $user = $this->find($params['uid']);
        $perms = [];
        foreach ($this->app->db->all('SELECT department_id, permission FROM permissions WHERE user_id = ?', [$user['user_id']]) as $p) {
            $perms[(int) $p['department_id']] = (string) $p['permission'];
        }
        return $this->form($user, [
            'user_id'       => $user['user_id'],
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
        return $this->save($req, $this->find($params['uid']));
    }

    // ---- saving ----

    private function save(Request $req, ?array $existing): string
    {
        $db = $this->app->db;
        $auth = $this->app->auth;
        $isNew = $existing === null;
        $isSelf = !$isNew && $existing['user_id'] === $auth->user()['user_id'];
        $canDisable = $auth->supportsDisabling();

        $f = [
            'user_id'       => $isNew ? trim((string) $req->post('user_id')) : (string) $existing['user_id'],
            'username'      => trim((string) $req->post('username')),
            'firstname'     => trim((string) $req->post('firstname')),
            'lastname'      => trim((string) $req->post('lastname')),
            'email'         => trim((string) $req->post('email')),
            'department_id' => (int) $req->post('department_id'),
            'timezone'      => (string) $req->post('timezone'),
            'admin'         => $req->post('admin') === '1',
            'disabled'      => $canDisable && $req->post('disabled') === '1',
        ];
        if ($isSelf) { // you can't lock yourself out; the form doesn't offer these for your own account
            $f['admin'] = true;
            $f['disabled'] = false;
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
        if ($isNew) {
            if (!preg_match('/^' . self::ID . '$/', $f['user_id']) || strlen($f['user_id']) > 16 || strtolower($f['user_id']) === 'new') {
                $errors[] = 'User ID: 1–16 letters, numbers or . _ @ - (and not "new"). It cannot be changed later.';
            } elseif ($db->one('SELECT 1 FROM users WHERE user_id = ?', [$f['user_id']]) !== null) {
                $errors[] = 'That user ID is already in use.';
            }
        }
        if ($f['username'] === '' || strlen($f['username']) > 64 || preg_match('/[\x00-\x1f\x7f]/', $f['username'])) {
            $errors[] = 'Enter a username (up to 64 characters, no control characters).';
        } elseif ($db->one('SELECT 1 FROM users WHERE username = ? AND user_id <> ?', [$f['username'], $f['user_id']]) !== null) {
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
        if (!$isNew && $existing['admin'] && empty($existing['disabled']) && (!$f['admin'] || $f['disabled'])) {
            $others = (int) $db->value(
                'SELECT COUNT(*) FROM users WHERE admin = 1 AND user_id <> ?' . ($canDisable ? ' AND disabled = 0' : ''),
                [$existing['user_id']]
            );
            if ($others === 0) {
                $errors[] = 'This is the last active administrator; it cannot be demoted or disabled.';
            }
        }

        if ($errors === []) {
            try {
                $this->persist($isNew, $f, $password, $perms, $canDisable);
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
     * @throws \RuntimeException if the password hash could not be stored intact
     */
    private function persist(bool $isNew, array $f, string $password, array $perms, bool $canDisable): void
    {
        $db = $this->app->db;
        $pdo = $db->pdo();
        $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
        $pdo->beginTransaction();
        try {
            if ($isNew) {
                // lastlogin is NOT NULL: the epoch means "never signed in". A hash is always supplied,
                // so the column's weak legacy default password can never apply.
                $db->execute(
                    'INSERT INTO users (user_id, username, password, firstname, lastname, email, department_id, admin, timezone, lastlogin)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$f['user_id'], $f['username'], $hash, $f['firstname'], $f['lastname'], $f['email'],
                     $f['department_id'], (int) $f['admin'], $f['timezone'], '1970-01-01 00:00:00']
                );
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
            if ($canDisable) {
                $db->execute('UPDATE users SET disabled = ? WHERE user_id = ?', [(int) $f['disabled'], $f['user_id']]);
            }
            if ($hash !== null && $db->value('SELECT password FROM users WHERE user_id = ?', [$f['user_id']]) !== $hash) {
                throw new \RuntimeException('The users.password column is too narrow for secure password hashes. Apply migration 001 on the Migrations page first.');
            }

            $db->execute('DELETE FROM permissions WHERE user_id = ?', [$f['user_id']]);
            foreach ($perms as $deptId => $level) {
                $db->execute('INSERT INTO permissions (user_id, department_id, permission) VALUES (?, ?, ?)', [$f['user_id'], $deptId, $level]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    // ---- helpers ----

    /** @return array<string,mixed> the user row (without password); 404 if there is none */
    private function find(string $userId): array
    {
        $user = $this->app->db->one('SELECT * FROM users WHERE user_id = ?', [$userId]);
        if ($user === null) {
            $this->app->error(404, 'User not found');
        }
        unset($user['password']);
        return $user;
    }

    /** @return list<array{department_id:int|string, abbr:string, name:string}> */
    private function departments(): array
    {
        return $this->app->db->all('SELECT department_id, abbr, name FROM departments ORDER BY abbr');
    }

    private function form(?array $existing, array $f, array $perms, array $errors): string
    {
        $self = $existing !== null && $existing['user_id'] === $this->app->auth->user()['user_id'];
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
        ]);
    }
}
