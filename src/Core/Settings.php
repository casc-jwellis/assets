<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Site-wide settings stored in the `settings` table (migration 015): key => string value.
 *
 * Reading never fails: before the migration exists, or if the database cannot be reached (setup pages, error
 * pages), every setting simply reads as "not set" and callers fall back to their defaults. The table is read
 * once per request.
 */
final class Settings
{
    /** @var array<string,string>|null */
    private ?array $values = null;

    public function __construct(private Database $db)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        return $this->all()[$key] ?? $default;
    }

    /** @return array<string,string> */
    public function all(): array
    {
        if ($this->values === null) {
            try {
                $this->values = array_column($this->db->all('SELECT setting_key, setting_value FROM settings'), 'setting_value', 'setting_key');
            } catch (\Throwable) {
                $this->values = [];
            }
        }
        return $this->values;
    }

    /** Insert or replace. @throws \PDOException if the table does not exist */
    public function set(string $key, string $value): void
    {
        $this->db->execute(
            'INSERT INTO settings (setting_key, setting_value, updated_date) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_date = VALUES(updated_date)',
            [$key, $value, date('Y-m-d H:i:s')]
        );
        $this->values = null;
    }

    public function delete(string ...$keys): void
    {
        foreach ($keys as $key) {
            $this->db->execute('DELETE FROM settings WHERE setting_key = ?', [$key]);
        }
        $this->values = null;
    }
}
