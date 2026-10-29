<?php

declare(strict_types=1);

namespace App\Modules\Users;

use App\Core\Auth;
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

    /** Adds users.must_change_password. From then on a new user gets a generated, emailed temporary password. */
    private const TEMP_MIGRATION = '017_users_must_change_password.sql';
    /** Adds users.manager (the Asset Manager role). Until it is applied there is no such role. */
    private const MANAGER_MIGRATION = '018_users_manager.sql';
    /** No 0/O/1/l/I: people retype this from an email. 14 characters of 54 is about 80 bits. */
    private const TEMP_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    private const TEMP_LENGTH = 14;

    /** Username of the disabled stand-in for people who no longer exist (created by migration 009). */
    public const PLACEHOLDER = 'missing-user';

    public function routes(Router $router): void
    {
        // Asset Managers may manage ordinary accounts; merging two accounts moves history and stays with administrators.
        $manager = ['manager' => true];
        $admin = ['admin' => true];
        $router->get('/users', [$this, 'index'], $manager);
        // Fixed path first: "new" would also match the {uid} pattern below.
        $router->get('/users/new', [$this, 'showNew'], $manager);
        $router->post('/users/new', [$this, 'create'], $manager);
        $router->get('/users/{uid:' . self::ID . '}', [$this, 'showEdit'], $manager);
        $router->post('/users/{uid:' . self::ID . '}', [$this, 'update'], $manager);
        $router->post('/users/{uid:' . self::ID . '}/disable', [$this, 'disable'], $manager);
        $router->post('/users/{uid:' . self::ID . '}/enable', [$this, 'enable'], $manager);
        $router->get('/users/{uid:' . self::ID . '}/merge', [$this, 'showMerge'], $admin);
        $router->post('/users/{uid:' . self::ID . '}/merge', [$this, 'merge'], $admin);
    }

    public function nav(): array
    {
        return [['label' => 'Users', 'path' => '/users', 'icon' => 'users', 'order' => 80, 'manager' => true]];
    }

    // ---- list ----

    /**
     * Sortable columns: key => ORDER BY columns (the chosen direction applies to each). Only these keys are
     * ever used in SQL; whatever is in the request is matched against this list, never inserted.
     */
    private const SORTS = [
        'name'     => ['u.lastname', 'u.firstname', 'u.username'],
        'username' => ['u.username'],
        'id'       => ['u.user_id'],
        'email'    => ['u.email'],
        'dept'     => ['d.abbr'],
        // Administrators first (or last), then by how many departments someone has; an admin's count is not shown, so it is ignored.
        'role'     => ['u.admin', 'CASE WHEN u.admin = 1 THEN 0 ELSE (SELECT COUNT(*) FROM permissions px WHERE px.user_id = u.user_id) END'],
        'login'    => ['u.lastlogin'],
        'status'   => ['u.disabled'],
    ];
    private const DEFAULT_SORT = 'name';

    /** @return array{0:string, 1:string} the sort column key and 'asc'|'desc' */
    private function sortState(Request $req): array
    {
        $key = (string) $req->query('sort');
        if (!isset(self::SORTS[$key]) || ($key === 'status' && !$this->app->auth->supportsDisabling())) {
            $key = self::DEFAULT_SORT;
        }
        return [$key, $req->query('dir') === 'desc' ? 'desc' : 'asc'];
    }

    /** The ?sort= and ?dir= values to put in links: nothing for the default order. */
    private function sortParams(string $key, string $dir): array
    {
        return ['sort' => $key === self::DEFAULT_SORT ? null : $key, 'dir' => $dir === 'desc' ? 'desc' : null];
    }

    /** Keep the status filter and the sort (as well as search and page) when moving between the list and a user. */
    protected function rememberList(Request $req): void
    {
        [$sort, $dir] = $this->sortState($req);
        $this->listQs = $this->listState(
            mb_substr(trim((string) $req->query('q')), 0, 64),
            max(1, (int) $req->query('page', '1')),
            ['status' => $this->statusParam($this->statusFilter($req))] + $this->sortParams($sort, $dir)
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
        [$sort, $dir] = $this->sortState($req);
        $sortParams = $this->sortParams($sort, $dir);

        if ($req->query('q') !== null && $search === '') {
            $this->redirect('/users' . $this->listState('', $page, ['status' => $statusParam] + $sortParams));
        }

        $where = '1 = 1';
        $params = [];
        if ($search !== '' && has_wide_chars($search)) {
            $where = '1 = 0'; // no username or name holds emoji, so nothing can match
        } elseif ($search !== '') {
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

        if (!$this->app->auth->isAdmin()) { // an Asset Manager sees ordinary accounts only: no administrators, managers or the placeholder
            $where .= ' AND u.admin = 0 AND u.manager = 0 AND u.username <> ?';
            $params[] = self::PLACEHOLDER;
        }

        $total = (int) $db->value("SELECT COUNT(*) FROM users u WHERE {$where}", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $offset = ($page - 1) * self::PER_PAGE;
        $this->listQs = $this->listState($search, $page, ['status' => $statusParam] + $sortParams);

        // The chosen column in the chosen direction, then name order A to Z so rows with equal values keep a
        // stable order (and do not shuffle between pages).
        $direction = strtoupper($dir);
        $sortColumns = self::SORTS[$sort];
        if ($sort === 'role' && $this->managerReady()) {
            array_splice($sortColumns, 1, 0, 'u.manager'); // administrators, then managers, then by number of departments
        }
        $order = implode(', ', [
            ...array_map(static fn(string $c): string => "{$c} {$direction}", $sortColumns),
            'u.lastname ASC', 'u.firstname ASC', 'u.username ASC', 'u.user_id ASC',
        ]);

        $cols = 'u.user_id, u.username, u.firstname, u.lastname, u.email, u.admin, u.lastlogin, d.abbr AS dept'
            . ($this->managerReady() ? ', u.manager' : '')
            . ($this->app->auth->supportsDisabling() ? ', u.disabled' : '');
        $rows = $db->all(
            "SELECT {$cols},
                    (SELECT COUNT(*) FROM permissions p WHERE p.user_id = u.user_id) AS dept_count
               FROM users u
               LEFT JOIN departments d ON d.department_id = u.department_id
              WHERE {$where}
              ORDER BY {$order}
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
            'sort'   => $sort,
            'dir'    => $dir,
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
            $owned = ['assets' => ['user_id', 'updated_by', 'retired_by'], 'transfers' => ['user_id']];
            if ($this->hasAttachments()) {
                $owned['asset_attachments'] = ['uploaded_by'];
            }
            foreach ($owned as $table => $columns) {
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

        $moved = $counts['added'] + $counts['changed'] + $counts['retired'] + $counts['transfers'] + $counts['attachments'];
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

    /** Files attached to assets record who added them (table from migration 014). */
    private function hasAttachments(): bool
    {
        return $this->app->migrationApplied('014_asset_attachments.sql');
    }

    /** @return array{added:int, changed:int, retired:int, transfers:int, attachments:int} how much is attached to this user */
    private function mergeCounts(int $userId): array
    {
        $db = $this->app->db;
        return [
            'added'       => (int) $db->value('SELECT COUNT(*) FROM assets WHERE user_id = ?', [$userId]),
            'changed'     => (int) $db->value('SELECT COUNT(*) FROM assets WHERE updated_by = ?', [$userId]),
            'retired'     => (int) $db->value('SELECT COUNT(*) FROM assets WHERE retired_by = ?', [$userId]),
            'transfers'   => (int) $db->value('SELECT COUNT(*) FROM transfers WHERE user_id = ?', [$userId]),
            'attachments' => $this->hasAttachments() ? (int) $db->value('SELECT COUNT(*) FROM asset_attachments WHERE uploaded_by = ?', [$userId]) : 0,
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
            'department_id' => 0, 'timezone' => 'UTC', 'admin' => false, 'manager' => false, 'disabled' => false,
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
            'manager'       => !$user['admin'] && !empty($user['manager']),
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
            'admin'         => false,
            'manager'       => false,
            // Disabling and re-enabling have their own buttons; saving the form never changes it.
            'disabled'      => !$isNew && !empty($existing['disabled']),
        ];
        // The role is the administrator's to give. An Asset Manager only ever handles ordinary accounts (find() has
        // already refused any other), so whatever is posted, the account stays ordinary.
        $actorAdmin = $auth->isAdmin();
        if ($actorAdmin) {
            $role = (string) $req->post('role');
            $f['admin'] = $role === 'admin';
            $f['manager'] = $role === 'manager' && $this->managerReady();
        }
        if ($isSelf) { // you can't remove your own admin access; the form doesn't offer it for your own account
            $f['admin'] = true;
            $f['manager'] = false;
        }
        $password = (string) $req->post('password');
        $confirm = (string) $req->post('password2');
        // A new user's password is generated and emailed (once migration 017 exists), so none is typed in.
        $temp = $isNew && $this->tempPasswords();
        $mail = $temp ? $this->notifier() : null;
        $canMail = $mail !== null && $mail->canMailPeople();

        $departments = $this->departments();
        $deptIds = array_map('intval', array_column($departments, 'department_id'));
        $perms = [];
        $posted = $req->postArray('perm');
        $lockedRw = $actorAdmin ? [] : $this->readWriteDepartments($isNew ? 0 : (int) $existing['user_id']);
        $tooMuch = false;
        foreach ($deptIds as $id) {
            if (in_array($id, $lockedRw, true)) { // given by an administrator: an Asset Manager cannot change it
                $perms[$id] = 'rw';
            } elseif (in_array($posted[(string) $id] ?? '', ['r', 'rw'], true)) {
                $perms[$id] = $posted[(string) $id];
                if (!$actorAdmin && $perms[$id] === 'rw') {
                    $tooMuch = true;
                    $perms[$id] = 'r';
                }
            }
        }

        // ---- validation ----
        $errors = [];
        if ($f['username'] === '' || mb_strlen($f['username']) > 64 || preg_match('/[\x00-\x1f\x7f]/', $f['username'])) {
            $errors[] = 'Enter a username (up to 64 characters, no control characters).';
        } elseif (has_wide_chars($f['username'])) { // checked first: the database cannot even compare such a value
            $errors[] = 'Emoji and other special symbols cannot be stored in a username. Please remove them.';
        } elseif (strtolower($f['username']) === self::PLACEHOLDER) {
            $errors[] = 'That username is reserved.';
        } elseif ($db->one('SELECT 1 FROM users WHERE username = ? AND user_id <> ?', [$f['username'], (int) $f['user_id']]) !== null) {
            $errors[] = 'That username belongs to another user.';
        }
        if (mb_strlen($f['firstname']) > 32 || mb_strlen($f['lastname']) > 32) {
            $errors[] = 'First and last names can be at most 32 characters.';
        } elseif (has_wide_chars($f['firstname'], $f['lastname'])) {
            $errors[] = 'Emoji and other special symbols cannot be stored in a name. Please remove them.';
        }
        if (mb_strlen($f['email']) > 128 || ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL))) {
            $errors[] = 'Enter a valid email address' . ($canMail ? '.' : ' (or leave it blank).');
        } elseif ($canMail && $f['email'] === '') {
            $errors[] = 'Enter the email address to send the temporary password to.';
        }
        if ($f['department_id'] !== 0 && !in_array($f['department_id'], $deptIds, true)) {
            $errors[] = 'Choose a valid home department.';
        }
        if (!in_array($f['timezone'], \DateTimeZone::listIdentifiers(), true)) {
            $errors[] = 'Choose a valid time zone.';
        }
        if (!$temp && ($isNew || $password !== '')) {
            if (strlen($password) < self::MIN_PASSWORD) {
                $errors[] = 'The password must be at least ' . self::MIN_PASSWORD . ' characters.';
            } elseif (strlen($password) > Auth::MAX_PASSWORD) {
                $errors[] = 'The password can be at most ' . Auth::MAX_PASSWORD . ' characters (letters outside A to Z count as more than one).';
            } elseif ($password !== $confirm) {
                $errors[] = 'The password and its confirmation do not match.';
            }
        }
        if ($tooMuch) {
            $errors[] = 'You can give Read access only; Read & write is set by an administrator.';
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
                if ($temp) {
                    $password = $this->generatePassword();
                }
                $this->persist($isNew, $f, $password, $perms, $temp);
                if ($temp) {
                    return $this->welcome($f, $password, $canMail ? $mail : null);
                }
                $this->app->session->flash('success', 'User "' . $f['username'] . '" ' . ($isNew ? 'created.' : 'saved.'));
                $this->redirect('/users' . $this->listQs);
            } catch (\PDOException $e) { // first: PDOException is a RuntimeException, and its text is not for the screen
                error_log('users save failed: ' . $e->getMessage());
                $errors[] = 'The user could not be saved because of a database error.';
            } catch (\RuntimeException $e) {
                $errors[] = $e->getMessage();
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
    private function persist(bool $isNew, array $f, string $password, array $perms, bool $mustChange = false): int
    {
        $db = $this->app->db;
        $pdo = $db->pdo();
        $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
        $pdo->beginTransaction();
        try {
            if ($isNew) {
                // user_id is auto-incremented. lastlogin is NOT NULL: the epoch means "never signed in".
                // A hash is always supplied, so the column's weak legacy default password can never apply.
                $withManager = $this->managerReady();
                $db->execute(
                    'INSERT INTO users (username, password, firstname, lastname, email, department_id, admin, timezone, lastlogin'
                    . ($mustChange ? ', must_change_password' : '') . ($withManager ? ', manager' : '') . ')
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?' . ($mustChange ? ', 1' : '') . ($withManager ? ', ' . (int) $f['manager'] : '') . ')',
                    [$f['username'], $hash, $f['firstname'], $f['lastname'], $f['email'],
                     $f['department_id'], (int) $f['admin'], $f['timezone'], '1970-01-01 00:00:00']
                );
                $f['user_id'] = (int) $pdo->lastInsertId();
            } else {
                $db->execute(
                    'UPDATE users SET username = ?, firstname = ?, lastname = ?, email = ?, department_id = ?, admin = ?, timezone = ?'
                    . ($this->managerReady() ? ', manager = ' . (int) $f['manager'] : '') . '
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

    // ---- temporary passwords for new users ----

    /** Is migration 017 applied? Until it is, new users still get a password typed in by the administrator. */
    private function tempPasswords(): bool
    {
        return $this->app->migrationApplied(self::TEMP_MIGRATION);
    }

    private function generatePassword(): string
    {
        $out = '';
        $max = strlen(self::TEMP_ALPHABET) - 1;
        for ($i = 0; $i < self::TEMP_LENGTH; $i++) {
            $out .= self::TEMP_ALPHABET[random_int(0, $max)];
        }
        return $out;
    }

    /** The Email module's notifier, or null if that module is not installed. */
    private function notifier(): ?object
    {
        $module = $this->app->module('Email');
        return $module !== null && method_exists($module, 'notifier') ? $module->notifier() : null;
    }

    /**
     * The new user exists. Email them the temporary password; if that cannot be done, show it to the administrator
     * once, on this response only (never stored or put in the session), so the account is not stranded.
     */
    private function welcome(array $f, string $password, ?object $mail): string
    {
        [$sent, $why] = $mail !== null ? $mail->sendTemporaryPassword($f, $password) : [false, ''];
        if ($sent) {
            $this->app->session->flash('success', 'User "' . $f['username'] . '" created. A temporary password was emailed to ' . $f['email'] . '; they will choose their own when they first sign in.');
            $this->redirect('/users' . $this->listQs);
        }
        return $this->render('created', [
            'title'    => 'User created',
            'username' => $f['username'],
            'email'    => $f['email'],
            'password' => $password,
            'reason'   => $mail === null
                ? 'Email is not set up (an administrator can do that on the Email page), so nothing was sent.'
                : 'The email could not be sent' . ($why !== '' ? ': ' . $why : '.'),
        ]);
    }

    // ---- helpers ----

    /** Is migration 018 applied? Until it is, there is no Asset Manager role to give. */
    private function managerReady(): bool
    {
        return $this->app->migrationApplied(self::MANAGER_MIGRATION);
    }

    /** May the signed-in person manage this account? Administrators: any. Asset Managers: ordinary accounts only. */
    private function canManage(array $user): bool
    {
        return $this->app->auth->isAdmin() || (empty($user['admin']) && empty($user['manager']));
    }

    /** @return list<int> the departments in which this user has Read & write (none for a user not yet created) */
    private function readWriteDepartments(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }
        return array_map('intval', array_column(
            $this->app->db->all("SELECT department_id FROM permissions WHERE user_id = ? AND permission = 'rw'", [$userId]),
            'department_id'
        ));
    }

    /** @return array<string,mixed> the user row (without password); 404 if there is none */
    private function find(int $userId): array
    {
        $user = $this->app->db->one('SELECT * FROM users WHERE user_id = ?', [$userId]);
        if ($user === null) {
            $this->app->error(404, 'User not found');
        }
        unset($user['password']);
        if (!$this->canManage($user)) {
            $this->app->error(403, 'Access denied', 'Asset Managers can manage ordinary accounts only, not administrators or other Asset Managers.');
        }
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
            'maxPassword' => Auth::MAX_PASSWORD,
            'actorAdmin'  => $this->app->auth->isAdmin(),
            'managerReady' => $this->managerReady(),
            'lockedRw'    => $this->app->auth->isAdmin() || $existing === null ? [] : $this->readWriteDepartments((int) $existing['user_id']),
            'temp'        => $existing === null && $this->tempPasswords(),
            'mailReady'   => $existing === null && $this->tempPasswords() && ($this->notifier()?->canMailPeople() ?? false),
            'mustChange'  => $existing !== null && !empty($existing['must_change_password']),
            'mergeTargets' => $existing === null || !$this->app->auth->isAdmin() ? [] : $this->app->db->all(
                'SELECT user_id, username, firstname, lastname' . ($this->app->auth->supportsDisabling() ? ', disabled' : '')
                . ' FROM users WHERE user_id <> ? AND username <> ? ORDER BY username',
                [$existing['user_id'], self::PLACEHOLDER]
            ),
        ]);
    }
}
