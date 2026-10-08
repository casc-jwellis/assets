<?php

declare(strict_types=1);

namespace App\Modules\Import;

use App\Core\Database;
use App\Core\Migrator;

/**
 * Turns the rows of a legacy dump into rows that fit the current schema (fixing what it safely can
 * and reporting everything it changed), then replaces the data tables with them in one transaction.
 *
 * plan() is pure (no database access) so it can be run first as a dry run; execute() writes.
 */
final class LegacyImporter
{
    /** Insert order (also the report order). Deleting happens in reverse. */
    public const ORDER = ['departments', 'buildings', 'asset_types', 'users', 'permissions', 'assets', 'transfers'];

    /** Data migrations that read the freshly loaded rows; their SQL is run directly after the import. */
    public const POST_FIXES = ['007_backfill_retired_assets.sql', '008_return_retired_assets_to_departments.sql'];

    /** The md5 that the old users.password column defaulted to. Anyone still on it has a guessable password. */
    private const DEFAULT_PASSWORD_HASH = '713cc24cb856455d3a9f9138d8f322c4';

    private const EPOCH = '1970-01-01 00:00:00';

    /** Username of the disabled "Missing user" placeholder (see migration 009 and UsersModule::PLACEHOLDER). */
    private const PLACEHOLDER = 'missing-user';

    /**
     * Column types: id (required positive int), int (>= 0), bool, sN / sNn (text up to N chars, n = nullable),
     * money, dt (datetime), dtn (nullable datetime), perm (r / rw).
     */
    private const SPEC = [
        'departments' => ['department_id' => 'id', 'abbr' => 's6', 'name' => 's64'],
        'buildings'   => ['building_id' => 'id', 'abbr' => 's4', 'name' => 's64'],
        'asset_types' => ['type_id' => 'id', 'name' => 's64', 'depreciation_years' => 'int'],
        'users'       => ['user_id' => 's16', 'username' => 's64', 'password' => 's255', 'firstname' => 's32', 'lastname' => 's32',
                          'email' => 's128', 'department_id' => 'int', 'admin' => 'bool', 'timezone' => 's32', 'lastlogin' => 'dt'],
        'permissions' => ['user_id' => 's16', 'department_id' => 'id', 'permission' => 'perm'],
        'assets'      => ['asset_id' => 'id', 'asset_number' => 's32', 'serial_number' => 's32', 'type_id' => 'id', 'po_number' => 's32',
                          'cost' => 'money', 'purchaser_id' => 'int', 'purchase_date' => 'dt', 'department_id' => 'id', 'building_id' => 'id',
                          'room' => 's8', 'description' => 's64', 'verified_date' => 'dtn', 'notes' => 's10240', 'user_id' => 's16',
                          'created_date' => 'dt'],
        'transfers'   => ['transfer_id' => 'id', 'asset_id' => 'id', 'user_id' => 's16', 'department_from' => 's6', 'department_to' => 's6',
                          'location_from' => 's16', 'location_to' => 's16', 'reason' => 's16n', 'transfer_date' => 'dt'],
    ];

    /** @var array<string, array{label:string, count:int, examples:list<string>}> */
    private array $fixes = [];
    /** @var array<string, array{label:string, count:int, examples:list<string>}> */
    private array $warnings = [];

    public function __construct(private Database $db)
    {
    }

    // ------------------------------------------------------------------ plan

    /**
     * @param array<string, list<array<string, ?string>>> $dump  rows from DumpParser::parse()['rows']
     * @param array<string, mixed> $admin  the signed-in administrator's users row; it is kept as it is
     * @return array{rows: array<string, list<array<string,mixed>>>, report: array<string,mixed>}
     */
    public function plan(array $dump, array $admin): array
    {
        $this->fixes = $this->warnings = [];
        $in = [];     // per table: rows in the dump
        $rows = [];   // per table: rows after normalization
        foreach (self::ORDER as $table) {
            $in[$table] = count($dump[$table] ?? []);
            $rows[$table] = [];
            foreach ($dump[$table] ?? [] as $raw) {
                $row = $this->normalize($table, $raw);
                if ($row !== null) {
                    $rows[$table][] = $row;
                }
            }
        }

        $rows['departments'] = $this->unique($rows['departments'], ['department_id'], 'departments');
        $rows['buildings'] = $this->unique($rows['buildings'], ['building_id'], 'buildings');
        $rows['asset_types'] = $this->unique($rows['asset_types'], ['type_id'], 'asset types');

        // The dump's user IDs are text. Each user gets a new number, and every reference is translated.
        $ids = $this->fixUsers($rows, $admin);
        $rows['permissions'] = $this->fixPermissions($rows, $ids);
        $this->fixAssets($rows, $ids);
        $this->fixTransfers($rows, $ids);

        $tables = [];
        foreach (self::ORDER as $table) {
            // the "Missing user" placeholder is added to the users, but it is not a row from the dump
            $tables[$table] = ['dump' => $in[$table], 'import' => count($rows[$table]) - ($table === 'users' ? 1 : 0)];
        }
        return ['rows' => $rows, 'report' => ['tables' => $tables, 'fixes' => $this->fixes, 'warnings' => $this->warnings]];
    }

    /**
     * Give every dumped user a new integer ID and add the "Missing user" placeholder.
     *
     * @return array{map: array<string,int>, digits: array<string,int|false>, missing: int}
     *         map: old text ID => new ID; digits: the same keyed by the number without leading zeros
     *         (a fallback for IDs that lost their zeros, as in the old transfers table); missing: the placeholder's ID
     */
    private function fixUsers(array &$rows, array $admin): array
    {
        $rows['users'] = $this->unique($rows['users'], ['user_id'], 'users');
        $deptIds = array_flip(array_column($rows['departments'], 'department_id'));
        $adminId = (int) $admin['user_id'];
        $adminName = strtolower((string) $admin['username']);

        $kept = [];
        $names = [];
        $map = [];
        $next = 1;
        $newId = static function () use (&$next, $adminId): int {
            while ($next === $adminId) { // the signed-in admin keeps their own number
                $next++;
            }
            return $next++;
        };
        $adminSeen = false;

        foreach ($rows['users'] as $u) {
            $legacy = $u['user_id'];
            // The signed-in admin's account is never replaced, or the import could lock you out.
            // Anyone in the dump with the same username is taken to be you: references go to your account.
            if (strtolower($u['username']) === $adminName) {
                $map[$legacy] = $adminId;
                if ($adminSeen) {
                    $this->warn('admin_name_clash', 'Several users in the dump share your username; all were treated as your account', $legacy);
                } else {
                    $this->fix('admin_kept', 'Your own account was in the dump; your current account and password were kept', $u['username']);
                }
                $adminSeen = true;
                continue;
            }
            if (strtolower($u['username']) === self::PLACEHOLDER) {
                $this->warn('placeholder_name', 'Users skipped because "missing-user" is reserved for the placeholder', $legacy);
                continue;
            }
            if ($u['username'] === '') {
                $u['username'] = $legacy;
                $this->fix('empty_username', 'Users with no username now use their old ID as the username', $legacy);
            }
            if ($u['department_id'] !== 0 && !isset($deptIds[$u['department_id']])) {
                $this->fix('user_dept', 'Users whose home department no longer exists were set to "None"', $u['username']);
                $u['department_id'] = 0;
            }
            if (!in_array($u['timezone'], \DateTimeZone::listIdentifiers(), true)) {
                $u['timezone'] = 'UTC';
                $this->fix('timezone', 'Users with a missing or unknown time zone were set to UTC', $u['username']);
            }
            if (strtolower($u['password']) === self::DEFAULT_PASSWORD_HASH) {
                $this->warn('default_password', 'Users still have the old default password and should change it', $u['username']);
            } elseif ($u['password'] === '') {
                $this->warn('no_password', 'Users have no password and cannot sign in until an administrator sets one', $u['username']);
            }
            $key = strtolower($u['username']);
            if (isset($names[$key])) {
                $this->warn('dup_username', 'Usernames shared by more than one user (sign-in picks one of them; rename the others)', $u['username']);
            }
            $names[$key] = true;

            $u['user_id'] = $newId();
            $u['disabled'] = 0;
            $map[$legacy] = $u['user_id'];
            $kept[] = $u;
        }

        // The stand-in for people who no longer exist (same row migration 009 creates).
        $missing = $newId();
        $kept[] = [
            'user_id' => $missing, 'username' => self::PLACEHOLDER, 'password' => '', 'firstname' => '', 'lastname' => 'Missing user',
            'email' => '', 'department_id' => 0, 'admin' => 0, 'timezone' => 'UTC', 'lastlogin' => self::EPOCH, 'disabled' => 1,
        ];
        $rows['users'] = $kept;

        $digits = [];
        foreach ($map as $legacy => $id) {
            $legacy = (string) $legacy;
            if ($legacy !== '' && ctype_digit($legacy)) {
                $k = ltrim($legacy, '0') ?: '0';
                $digits[$k] = isset($digits[$k]) ? false : $id; // two IDs that differ only in leading zeros are ambiguous
            }
        }
        return ['map' => $map, 'digits' => $digits, 'missing' => $missing];
    }

    /** The new ID for an old text ID, or null when there is no such user. */
    private function lookupUser(?string $legacy, array $ids): ?int
    {
        $legacy = (string) $legacy;
        if (isset($ids['map'][$legacy])) {
            return $ids['map'][$legacy];
        }
        if ($legacy !== '' && ctype_digit($legacy)) {
            $found = $ids['digits'][ltrim($legacy, '0') ?: '0'] ?? false;
            return $found === false ? null : $found;
        }
        return null;
    }

    /** Like lookupUser(), but anything unknown or blank is attached to the "Missing user" placeholder. */
    private function userRef(?string $legacy, array $ids, string $ctx): int
    {
        $id = $this->lookupUser($legacy, $ids);
        if ($id !== null) {
            return $id;
        }
        $this->fix('missing_user', 'References to people who no longer exist were attached to the "Missing user" placeholder', $ctx . ' (' . ((string) $legacy === '' ? 'blank' : $legacy) . ')');
        return $ids['missing'];
    }

    /** @return list<array<string,mixed>> */
    private function fixPermissions(array $rows, array $ids): array
    {
        $depts = array_flip(array_column($rows['departments'], 'department_id'));
        $out = [];
        $seen = [];
        foreach ($rows['permissions'] as $p) {
            $uid = $this->lookupUser($p['user_id'], $ids);
            if ($uid === null || !isset($depts[$p['department_id']])) {
                $this->fix('perm_orphan', 'Permission rows dropped because their user or department does not exist', $p['user_id'] . ' / dept ' . $p['department_id']);
                continue;
            }
            $p['user_id'] = $uid;
            $key = $uid . "\0" . $p['department_id'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $p;
        }
        return $out;
    }

    private function fixAssets(array &$rows, array $ids): void
    {
        $rows['assets'] = $this->unique($rows['assets'], ['asset_id'], 'assets');
        $types = array_flip(array_column($rows['asset_types'], 'type_id'));
        $buildings = array_flip(array_column($rows['buildings'], 'building_id'));
        $depts = array_flip(array_column($rows['departments'], 'department_id'));
        $newTypes = $newBuildings = $newDepts = [];
        $numbers = [];

        foreach ($rows['assets'] as &$a) {
            $label = $a['asset_number'] !== '' ? $a['asset_number'] : '#' . $a['asset_id'];
            $a['user_id'] = $this->userRef($a['user_id'], $ids, 'asset ' . $label);

            // Asset numbers must be present and unique.
            if (trim($a['asset_number']) === '') {
                $a['asset_number'] = 'ASSET-' . $a['asset_id'];
                $this->fix('asset_number_blank', 'Assets with no asset number were numbered ASSET-<id>', $a['asset_number']);
            }
            $key = strtolower(rtrim($a['asset_number']));
            if (isset($numbers[$key])) {
                $old = $a['asset_number'];
                $a['asset_number'] = mb_substr($old, 0, 26) . '~dup' . $a['asset_id'];
                $this->fix('asset_number_dup', 'Assets with a repeated asset number were renamed "<number>~dup<id>"', $old . ' -> ' . $a['asset_number']);
                $key = strtolower($a['asset_number']);
            }
            $numbers[$key] = true;

            // References to rows that don't exist get a clearly named placeholder, so nothing is lost.
            if (!isset($types[$a['type_id']])) {
                $types[$a['type_id']] = true;
                $newTypes[] = ['type_id' => $a['type_id'], 'name' => 'Unknown type #' . $a['type_id'], 'depreciation_years' => 0];
                $this->fix('placeholder_type', 'Missing asset types were added as "Unknown type #<id>" (rename them)', 'type #' . $a['type_id']);
            }
            if (!isset($buildings[$a['building_id']])) {
                $buildings[$a['building_id']] = true;
                $newBuildings[] = ['building_id' => $a['building_id'], 'abbr' => 'UNK', 'name' => 'Unknown building #' . $a['building_id']];
                $this->fix('placeholder_building', 'Missing buildings were added as "Unknown building #<id>" (rename them)', 'building #' . $a['building_id']);
            }
            if (!isset($depts[$a['department_id']])) {
                $depts[$a['department_id']] = true;
                $newDepts[] = ['department_id' => $a['department_id'], 'abbr' => 'UNK' . (count($newDepts) + 1), 'name' => 'Unknown department #' . $a['department_id']];
                $this->fix('placeholder_dept', 'Missing departments were added as "Unknown department #<id>" (rename them)', 'department #' . $a['department_id']);
            }
            if ($a['purchaser_id'] === 0 || !isset($depts[$a['purchaser_id']])) {
                $a['purchaser_id'] = $a['department_id'];
                $this->fix('purchaser', 'Assets with a missing purchasing department now use their own department', $label);
            }

            // Dates: a zero or invalid date cannot be stored.
            $created = $a['created_date'];
            $purchase = $a['purchase_date'];
            if ($purchase === null) {
                $a['purchase_date'] = $created ?? self::EPOCH;
                $this->fix('purchase_date', 'Assets with a missing purchase date use their added date (or 1970-01-01)', $label);
            }
            if ($created === null) {
                $a['created_date'] = $purchase ?? self::EPOCH;
                $this->fix('created_date', 'Assets with a missing added date use their purchase date (or 1970-01-01)', $label);
            }
        }
        unset($a);

        $rows['departments'] = array_merge($rows['departments'], $newDepts);
        $rows['buildings'] = array_merge($rows['buildings'], $newBuildings);
        $rows['asset_types'] = array_merge($rows['asset_types'], $newTypes);
    }

    private function fixTransfers(array &$rows, array $ids): void
    {
        $rows['transfers'] = $this->unique($rows['transfers'], ['transfer_id'], 'transfers');
        $assets = array_flip(array_column($rows['assets'], 'asset_id'));
        $out = [];
        foreach ($rows['transfers'] as $t) {
            if (!isset($assets[$t['asset_id']])) {
                $this->fix('transfer_orphan', 'Transfer rows dropped because their asset does not exist', 'transfer #' . $t['transfer_id'] . ' (asset #' . $t['asset_id'] . ')');
                continue;
            }
            $t['user_id'] = $this->userRef($t['user_id'], $ids, 'transfer #' . $t['transfer_id']);
            if ($t['transfer_date'] === null) {
                $t['transfer_date'] = self::EPOCH;
                $this->fix('transfer_date', 'Transfers with a missing date were dated 1970-01-01', 'transfer #' . $t['transfer_id']);
            }
            $out[] = $t;
        }
        $rows['transfers'] = $out;
    }

    // ------------------------------------------------------------ normalizing

    /** @return array<string,mixed>|null the cleaned row, or null if it cannot be kept */
    private function normalize(string $table, array $raw): ?array
    {
        $row = [];
        foreach (self::SPEC[$table] as $col => $type) {
            $v = $raw[$col] ?? null;
            $ctx = $table . '.' . $col;
            switch (true) {
                case $type === 'id':
                    if ($v === null || !preg_match('/^\d+$/', $v) || (int) $v < 1) {
                        $this->fix('bad_key', 'Rows dropped because their ID is missing or not a positive number', $ctx . ' = ' . var_export($v, true));
                        return null;
                    }
                    $row[$col] = (int) $v;
                    break;
                case $type === 'int':
                    $row[$col] = $v !== null && is_numeric($v) ? max(0, (int) $v) : 0;
                    break;
                case $type === 'bool':
                    $row[$col] = $v !== null && (int) $v !== 0 ? 1 : 0;
                    break;
                case $type === 'money':
                    $n = $v !== null && is_numeric($v) ? (float) $v : -1.0;
                    if ($n < 0 || $n > 99999999.99) {
                        $this->fix('cost', 'Costs that were missing, negative or too large were set to 0.00', $ctx . ' = ' . var_export($v, true));
                        $n = 0.0;
                    }
                    $row[$col] = number_format($n, 2, '.', '');
                    break;
                case $type === 'dt' || $type === 'dtn':
                    $d = $this->datetime($v);
                    if ($d === null && $v !== null && $type === 'dtn') {
                        $this->fix('bad_date', 'Zero or invalid dates were cleared', $ctx . ' = ' . $v);
                    }
                    // Required ('dt') dates can't be NULL: the owning table's fix-up below chooses a replacement.
                    $row[$col] = $d;
                    break;
                case $type === 'perm':
                    $p = strtolower(trim((string) $v));
                    if (!in_array($p, ['r', 'rw'], true)) {
                        $this->fix('perm_value', 'Permission rows dropped because the permission was not "r" or "rw"', (string) $v);
                        return null;
                    }
                    $row[$col] = $p;
                    break;
                default: // text
                    $nullable = str_ends_with($type, 'n');
                    $max = (int) substr($type, 1);
                    $row[$col] = $this->text($v, $max, $ctx, $nullable);
            }
        }

        // users.lastlogin is NOT NULL: 1970-01-01 means "never signed in" in this app.
        if ($table === 'users' && $row['lastlogin'] === null) {
            $row['lastlogin'] = self::EPOCH;
        }
        if ($table === 'users' && $row['user_id'] === '') {
            $this->fix('bad_key', 'Rows dropped because their ID is missing or not a positive number', 'users.user_id is empty');
            return null;
        }
        return $row;
    }

    private function text(?string $v, int $max, string $ctx, bool $nullable): ?string
    {
        if ($v === null) {
            return $nullable ? null : '';
        }
        if (preg_match('/[\x{10000}-\x{10FFFF}]/u', $v)) {
            $v = (string) preg_replace('/[\x{10000}-\x{10FFFF}]/u', '?', $v);
            $this->fix('wide_chars', 'Emoji and other characters the database cannot store were replaced with "?"', $ctx);
        }
        if (mb_strlen($v, 'UTF-8') > $max) {
            $v = mb_substr($v, 0, $max, 'UTF-8');
            $this->fix('truncated', 'Text longer than its column allows was shortened', $ctx);
        }
        return $v;
    }

    /** "2024-05-01 10:00:00" or null when missing, zero or not a real date. */
    private function datetime(?string $v): ?string
    {
        if ($v === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}):(\d{2}))?/', $v, $m)) {
            return null;
        }
        if ((int) $m[1] < 1000 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $m[1], $m[2], $m[3], $m[4] ?? 0, $m[5] ?? 0, $m[6] ?? 0);
    }

    /**
     * Keep the first row for each key; report the rest as dropped.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string> $keys
     * @return list<array<string,mixed>>
     */
    private function unique(array $rows, array $keys, string $what): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $k = implode("\0", array_map(static fn(string $c) => (string) $r[$c], $keys));
            if (isset($seen[$k])) {
                $this->fix('duplicate_' . $what, 'Duplicate ' . $what . ' rows dropped (first one kept)', str_replace("\0", '/', $k));
                continue;
            }
            $seen[$k] = true;
            $out[] = $r;
        }
        return $out;
    }

    private function fix(string $key, string $label, string $example = ''): void
    {
        $this->note($this->fixes, $key, $label, $example);
    }

    private function warn(string $key, string $label, string $example = ''): void
    {
        $this->note($this->warnings, $key, $label, $example);
    }

    private function note(array &$bucket, string $key, string $label, string $example): void
    {
        $bucket[$key] ??= ['label' => $label, 'count' => 0, 'examples' => []];
        $bucket[$key]['count']++;
        if ($example !== '' && count($bucket[$key]['examples']) < 5) {
            $bucket[$key]['examples'][] = $example;
        }
    }

    // ---------------------------------------------------------------- execute

    /**
     * Replace the data tables with the planned rows, then run the retirement data fixes. Everything is
     * one transaction: on any failure nothing changes.
     *
     * @param array{rows: array<string, list<array<string,mixed>>>} $plan
     * @return array<string,mixed> facts about the result
     */
    public function execute(array $plan, int $keepUserId, string $migrationsDir): array
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            foreach (array_reverse(self::ORDER) as $table) {
                if ($table === 'users') {
                    $this->db->execute('DELETE FROM users WHERE user_id <> ?', [$keepUserId]);
                } else {
                    $this->db->execute("DELETE FROM `{$table}`");
                }
            }
            foreach (self::ORDER as $table) {
                $this->insertRows($table, $plan['rows'][$table]);
            }
            foreach (self::POST_FIXES as $file) {
                $sql = file_get_contents($migrationsDir . '/' . $file);
                if ($sql === false) {
                    throw new \RuntimeException("Could not read migrations/{$file}.");
                }
                foreach (Migrator::splitStatements($sql) as $statement) {
                    $pdo->exec($statement);
                }
            }

            $counts = [];
            foreach (self::ORDER as $table) {
                $counts[$table] = (int) $this->db->value("SELECT COUNT(*) FROM `{$table}`");
            }
            $result = [
                'counts'  => $counts,
                'retired' => (int) $this->db->value('SELECT COUNT(*) FROM assets WHERE retired = 1'),
                'stuck'   => (int) $this->db->value(
                    "SELECT COUNT(*) FROM assets a JOIN departments d ON d.department_id = a.department_id WHERE a.retired = 1 AND d.abbr = 'RETIRE'"
                ),
            ];
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function insertRows(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $cols = array_keys($rows[0]); // our own column names from SPEC, never from the file
        $colSql = implode(',', array_map(static fn(string $c): string => "`{$c}`", $cols));
        $one = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        foreach (array_chunk($rows, max(1, intdiv(2000, count($cols)))) as $chunk) {
            $params = [];
            foreach ($chunk as $r) {
                foreach ($cols as $c) {
                    $params[] = $r[$c];
                }
            }
            $this->db->execute("INSERT INTO `{$table}` ({$colSql}) VALUES " . implode(',', array_fill(0, count($chunk), $one)), $params);
        }
    }
}
