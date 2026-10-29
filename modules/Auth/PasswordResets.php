<?php

declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\App;

/**
 * The one-time links behind "Forgot password" (table password_resets, migration 019).
 *
 * The link carries a random secret; only its SHA-256 hash is stored, so a copy of the database cannot be used to reset
 * anyone's password. A link works once, for an hour, and a new request replaces the old one. Nothing here ever tells the
 * person asking whether an account exists.
 */
final class PasswordResets
{
    public const MIGRATION = '019_password_resets.sql';
    public const LIFETIME_MINUTES = 60;
    /** One link per account at most this often. */
    private const COOLDOWN_SECONDS = 60;
    /** Requests that produced a link, per address and hour. */
    private const PER_IP_HOUR = 10;

    public function __construct(private App $app)
    {
    }

    /** Is the feature usable: its table exists and mail can be sent to a person? */
    public function available(): bool
    {
        if (!$this->app->migrationApplied(self::MIGRATION)) {
            return false;
        }
        $email = $this->app->module('Email');
        return $email !== null && method_exists($email, 'notifier') && $email->notifier()->canMailPeople();
    }

    /**
     * The account a person means by what they typed: a username, else an email address that belongs to exactly one
     * account. Null if there is none, it is disabled, it has no email address, or the address is shared (we would not
     * know who to write to).
     *
     * @return array<string,mixed>|null
     */
    public function find(string $typed): ?array
    {
        $typed = trim($typed);
        if ($typed === '' || mb_strlen($typed) > 128 || has_wide_chars($typed) || preg_match('/[\x00-\x1f\x7f]/', $typed)) {
            return null;
        }
        $db = $this->app->db;
        $active = $this->app->auth->supportsDisabling() ? ' AND disabled = 0' : '';
        $user = $db->one("SELECT user_id, username, firstname, lastname, email FROM users WHERE username = ?{$active}", [$typed]);
        if ($user === null && str_contains($typed, '@')) {
            $rows = $db->all("SELECT user_id, username, firstname, lastname, email FROM users WHERE email = ?{$active} LIMIT 2", [$typed]);
            $user = count($rows) === 1 ? $rows[0] : null;
        }
        if ($user === null || (string) $user['email'] === '' || !filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return $user;
    }

    /** Too many links asked for from this address lately, or one for this account a moment ago? */
    public function throttled(int $userId, string $ip): bool
    {
        $db = $this->app->db;
        if ((int) $db->value('SELECT COUNT(*) FROM password_resets WHERE requested_ip = ? AND created_date > ?', [$ip, $this->ago(3600)]) >= self::PER_IP_HOUR) {
            return true;
        }
        return (int) $db->value('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_date > ?', [$userId, $this->ago(self::COOLDOWN_SECONDS)]) > 0;
    }

    /** A fresh link secret for this account, replacing any earlier one. Returns the secret (it is not stored). */
    public function issue(int $userId, string $ip): string
    {
        $db = $this->app->db;
        $this->purge();
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $now = time();
        $db->execute('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
        $db->execute(
            'INSERT INTO password_resets (user_id, token_hash, created_date, expires_date, requested_ip) VALUES (?, ?, ?, ?, ?)',
            [$userId, hash('sha256', $secret), date('Y-m-d H:i:s', $now), date('Y-m-d H:i:s', $now + self::LIFETIME_MINUTES * 60), substr($ip, 0, 45)]
        );
        return $secret;
    }

    /**
     * The account a link belongs to, if the link is genuine, unused, unexpired and the account is still active.
     *
     * @return array<string,mixed>|null user_id, username
     */
    public function lookup(string $secret): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $secret)) {
            return null;
        }
        $active = $this->app->auth->supportsDisabling() ? ' AND u.disabled = 0' : '';
        return $this->app->db->one(
            "SELECT u.user_id, u.username
               FROM password_resets r JOIN users u ON u.user_id = r.user_id
              WHERE r.token_hash = ? AND r.expires_date > ?{$active}",
            [hash('sha256', $secret), date('Y-m-d H:i:s')]
        );
    }

    /** Every link for this account is spent (after a successful reset). */
    public function clear(int $userId): void
    {
        $this->app->db->execute('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
    }

    /** Expired links are of no use to anyone: drop them. */
    public function purge(): void
    {
        $this->app->db->execute('DELETE FROM password_resets WHERE expires_date < ?', [date('Y-m-d H:i:s')]);
    }

    private function ago(int $seconds): string
    {
        return date('Y-m-d H:i:s', time() - $seconds);
    }
}
