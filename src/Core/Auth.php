<?php

declare(strict_types=1);

namespace App\Core;

final class Auth
{
    /** Verified against when the username is unknown, so response time doesn't reveal valid usernames. */
    private const DUMMY_HASH = '$2y$10$Vrjs0nLJzb4XKGGCGfXemeF0rtT4UsHSLdACslO/ErltPwcyVojUW';

    private ?array $user = null;
    private bool $loaded = false;
    private ?bool $canDisable = null;

    public function __construct(
        private Database $db,
        private Session $session,
        private Config $config,
        private Csrf $csrf,
    ) {
    }

    /** @return array<string,mixed>|null the logged-in user (never includes the password hash) */
    public function user(): ?array
    {
        if ($this->loaded) {
            return $this->user;
        }
        $this->loaded = true;

        $uid = $this->session->get('auth.uid');
        if (!is_string($uid) || $uid === '') {
            return null;
        }

        $idle = (int) $this->config->get('session.idle_timeout', 1800);
        $seen = (int) $this->session->get('auth.seen', 0);
        if ($idle > 0 && time() - $seen > $idle) {
            $this->logout();
            $this->session->start();
            $this->session->flash('info', 'You were signed out due to inactivity.');
            return null;
        }

        // Reloaded each request so deleted/changed/disabled accounts take effect immediately.
        // SELECT * (not a column list) so this keeps working before migration 004 adds `disabled`.
        $row = $this->db->one('SELECT * FROM users WHERE user_id = ?', [$uid]);
        if ($row === null || !empty($row['disabled'])) {
            $disabled = $row !== null;
            $this->logout();
            $this->session->start();
            if ($disabled) {
                $this->session->flash('danger', 'Your account has been disabled.');
            }
            return null;
        }
        unset($row['password']); // never keep the hash around
        $row['admin'] = (bool) $row['admin'];
        $row['disabled'] = false;
        $this->session->set('auth.seen', time());
        return $this->user = $row;
    }

    /** Whether migration 004 (users.disabled) has been applied. */
    public function supportsDisabling(): bool
    {
        if ($this->canDisable === null) {
            try {
                $this->db->all('SELECT disabled FROM users LIMIT 1');
                $this->canDisable = true;
            } catch (\PDOException) {
                $this->canDisable = false;
            }
        }
        return $this->canDisable;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function isAdmin(): bool
    {
        return (bool) ($this->user()['admin'] ?? false);
    }

    public function attempt(string $username, string $password): bool
    {
        $row = $username === '' ? null : $this->db->one('SELECT * FROM users WHERE username = ?', [$username]);

        $stored = (string) ($row['password'] ?? self::DUMMY_HASH);
        // Disabled accounts fail exactly like a wrong password (no hint to someone guessing).
        $ok = $this->checkHash($stored, $password) && $row !== null && empty($row['disabled']);

        if (!$ok) {
            usleep(random_int(200_000, 400_000)); // slow down online guessing
            return false;
        }

        // Upgrade legacy / outdated hashes now that we have the plaintext.
        if ($this->isLegacyHash($stored) || password_needs_rehash($stored, PASSWORD_DEFAULT)) {
            $this->setPassword((string) $row['user_id'], $password);
        }

        $this->session->regenerate(); // prevent session fixation
        $this->csrf->rotate();
        $this->session->set('auth.uid', (string) $row['user_id']);
        $this->session->set('auth.seen', time());
        $this->loaded = false;
        $this->user = null;

        $this->db->execute('UPDATE users SET lastlogin = ? WHERE user_id = ?', [date('Y-m-d H:i:s'), $row['user_id']]);
        return true;
    }

    public function logout(): void
    {
        $this->session->destroy();
        $this->user = null;
        $this->loaded = true;
    }

    /** Check a password for an existing user (used by "change password"). */
    public function verifyPassword(string $userId, string $password): bool
    {
        $stored = $this->db->value('SELECT password FROM users WHERE user_id = ?', [$userId]);
        return is_string($stored) && $this->checkHash($stored, $password);
    }

    /**
     * Store a password_hash() value. Returns false (and leaves the old hash
     * untouched) if the column is too narrow to hold it, i.e. migration
     * 001_widen_password_column.sql has not been applied.
     */
    public function setPassword(string $userId, string $password): bool
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->db->execute('UPDATE users SET password = ? WHERE user_id = ?', [$hash, $userId]);
            // A narrow column could silently truncate in non-strict SQL modes; read back to be sure.
            $stored = $this->db->value('SELECT password FROM users WHERE user_id = ?', [$userId]);
            if ($stored !== $hash) {
                $pdo->rollBack();
                error_log('users.password is too narrow for password_hash(); apply migrations/001_widen_password_column.sql');
                return false;
            }
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('setPassword failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * SQL fragment limiting rows to departments the user may read.
     * Admins are unrestricted. $column must be a trusted identifier, never user input.
     *
     * @return array{0:string, 1:list<mixed>} [sql, params]
     */
    public function departmentScope(string $column): array
    {
        $user = $this->user();
        if ($user === null) {
            return ['1 = 0', []];
        }
        if ($user['admin']) {
            return ['1 = 1', []];
        }
        $ids = array_map('intval', array_column(
            $this->db->all('SELECT department_id FROM permissions WHERE user_id = ?', [$user['user_id']]),
            'department_id'
        ));
        if ($ids === []) {
            return ['1 = 0', []];
        }
        return [$column . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids];
    }

    /** @var list<int>|null|false false = not loaded yet */
    private array|null|false $writable = false;

    /**
     * Departments the user may change assets in ('rw' permission).
     * Returns null for administrators, meaning every department.
     *
     * @return list<int>|null
     */
    public function writableDepartmentIds(): ?array
    {
        if ($this->writable === false) {
            $user = $this->user();
            if ($user === null) {
                $this->writable = [];
            } elseif ($user['admin']) {
                $this->writable = null;
            } else {
                $this->writable = array_map('intval', array_column($this->db->all(
                    "SELECT department_id FROM permissions WHERE user_id = ? AND permission = 'rw'",
                    [$user['user_id']]
                ), 'department_id'));
            }
        }
        return $this->writable;
    }

    public function canWrite(int $departmentId): bool
    {
        $ids = $this->writableDepartmentIds();
        return $ids === null || in_array($departmentId, $ids, true);
    }

    public function canWriteAny(): bool
    {
        $ids = $this->writableDepartmentIds();
        return $ids === null || $ids !== [];
    }

    private function isLegacyHash(string $hash): bool
    {
        return (bool) preg_match('/^(?:[0-9a-f]{32}|[0-9a-f]{40})$/i', $hash);
    }

    private function checkHash(string $stored, string $password): bool
    {
        if ($this->isLegacyHash($stored)) {
            // Pre-existing MD5 (32) / SHA-1 (40) digests. Accepted only to migrate them on login.
            $calc = strlen($stored) === 32 ? md5($password) : sha1($password);
            return hash_equals(strtolower($stored), $calc);
        }
        return password_verify($password, $stored);
    }
}
