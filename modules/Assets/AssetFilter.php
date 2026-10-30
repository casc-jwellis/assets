<?php

declare(strict_types=1);

namespace App\Modules\Assets;

/**
 * The advanced filters on the asset list ("Filter" button): pick-from-a-list fields, "contains" text fields, a cost
 * range, date ranges, verification and who added / last changed an asset.
 *
 * Everything arrives in the address as ?f[...]=...; fromQuery() keeps only values it can validate, and the SQL below is
 * built from fixed column names with every value passed as a parameter, so nothing typed can reach a query. The same
 * validated values are what go back into links (query()), the active-filter chips and the CSV export.
 */
final class AssetFilter
{
    /** Pick from a list: key => [column, label, needs]. "needs" is '' or a schema feature: 'editing' or 'retirement'. */
    public const PICKS = [
        'type'      => ['a.type_id', 'Type', ''],
        'dept'      => ['a.department_id', 'Department', ''],
        'purchaser' => ['a.purchaser_id', 'Purchasing dept', ''],
        'bldg'      => ['a.building_id', 'Building', ''],
        'disp'      => ['a.disposal_method', 'Disposal method', 'retirement'],
    ];

    /** "Contains" text. */
    public const TEXTS = [
        'asset'  => ['a.asset_number', 'Asset #', ''],
        'serial' => ['a.serial_number', 'Serial', ''],
        'desc'   => ['a.description', 'Description', ''],
        'po'     => ['a.po_number', 'PO number', ''],
        'room'   => ['a.room', 'Room', ''],
        'notes'  => ['a.notes', 'Notes', ''],
        'rnotes' => ['a.retired_notes', 'Retirement notes', 'retirement'],
    ];

    /** From / to dates, stored as <key>_from and <key>_to. */
    public const DATES = [
        'purchased' => ['a.purchase_date', 'Purchased', ''],
        'verified'  => ['a.verified_date', 'Verified', ''],
        'created'   => ['a.created_date', 'Added', ''],
        'changed'   => ['a.updated_date', 'Last changed', 'editing'],
        'retired'   => ['a.retired_date', 'Retired', 'retirement'],
    ];

    /** One person. */
    public const PEOPLE = [
        'by'  => ['a.user_id', 'Added by', ''],
        'chg' => ['a.updated_by', 'Last changed by', 'editing'],
    ];

    /** Verification: '' (any), never verified, verified at some time, or not verified since a date. */
    public const VERIFICATION = [
        ''      => 'Any',
        'never' => 'Never verified',
        'ever'  => 'Has been verified',
        'stale' => 'Not verified since…',
    ];

    private const MAX_PICKED = 200;
    private const MAX_TEXT = 100;

    /** @var array<string, string|int|list<int|string>> only the filters that are in use */
    private array $values = [];

    private function __construct(private bool $editing, private bool $retirement)
    {
    }

    /**
     * @param array<mixed> $raw the ?f[...] part of the address, exactly as received
     * @param bool $editing   migration 005 (updated_date / updated_by) is applied
     * @param bool $retirement migration 006 (retired_* / disposal_method) is applied
     */
    public static function fromQuery(array $raw, bool $editing, bool $retirement): self
    {
        $f = new self($editing, $retirement);

        foreach (self::PICKS as $key => [, , $needs]) {
            if (!$f->allows($needs) || !isset($raw[$key]) || !is_array($raw[$key])) {
                continue;
            }
            $kept = [];
            foreach (array_slice($raw[$key], 0, self::MAX_PICKED) as $v) {
                if (!is_string($v)) {
                    continue;
                }
                if ($key === 'disp') {
                    if (in_array($v, AssetsModule::DISPOSAL_METHODS, true)) {
                        $kept[$v] = $v;
                    }
                } elseif (ctype_digit($v) && strlen($v) <= 10 && (int) $v > 0) {
                    $kept[(int) $v] = (int) $v;
                }
            }
            if ($kept !== []) {
                $f->values[$key] = array_values($kept);
            }
        }

        foreach (self::TEXTS as $key => [, , $needs]) {
            $s = $f->allows($needs) && isset($raw[$key]) && is_string($raw[$key]) ? trim(mb_substr($raw[$key], 0, self::MAX_TEXT)) : '';
            if ($s !== '') {
                $f->values[$key] = $s;
            }
        }

        foreach (['cost_min', 'cost_max'] as $key) {
            $n = isset($raw[$key]) && is_string($raw[$key]) ? str_replace(['$', ',', ' '], '', $raw[$key]) : '';
            if (preg_match('/^\d{1,8}(\.\d{1,2})?$/', $n) === 1) { // the cost column is decimal(10,2), never negative
                $f->values[$key] = $n;
            }
        }

        foreach (self::DATES as $key => [, , $needs]) {
            if (!$f->allows($needs)) {
                continue;
            }
            foreach (['_from', '_to'] as $end) {
                $d = self::date($raw[$key . $end] ?? null);
                if ($d !== '') {
                    $f->values[$key . $end] = $d;
                }
            }
        }

        foreach (self::PEOPLE as $key => [, , $needs]) {
            $v = $f->allows($needs) && isset($raw[$key]) && is_string($raw[$key]) ? $raw[$key] : '';
            if (ctype_digit($v) && strlen($v) <= 10 && (int) $v > 0) {
                $f->values[$key] = (int) $v;
            }
        }

        $ver = isset($raw['ver']) && is_string($raw['ver']) ? $raw['ver'] : '';
        if ($ver === 'never' || $ver === 'ever') {
            $f->values['ver'] = $ver;
        } elseif ($ver === 'stale') {
            $since = self::date($raw['ver_before'] ?? null);
            if ($since !== '') {
                $f->values['ver'] = 'stale';
                $f->values['ver_before'] = $since;
            }
        }

        return $f;
    }

    /** A valid YYYY-MM-DD, or ''. */
    private static function date(mixed $v): string
    {
        if (!is_string($v)) {
            return '';
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($v));
        return $d !== false && $d->format('Y-m-d') === trim($v) ? $d->format('Y-m-d') : '';
    }

    private function allows(string $needs): bool
    {
        return $needs === '' || ($needs === 'editing' && $this->editing) || ($needs === 'retirement' && $this->retirement);
    }

    /** Is this filter offered at all (the schema it needs exists)? */
    public function has(string $key): bool
    {
        $spec = self::PICKS[$key] ?? self::TEXTS[$key] ?? self::DATES[$key] ?? self::PEOPLE[$key] ?? null;
        return $spec !== null && $this->allows($spec[2]);
    }

    /** The value of one filter as the form shows it: a list for pick fields, '' when it is not in use. */
    public function value(string $key): string|int|array
    {
        return $this->values[$key] ?? (isset(self::PICKS[$key]) ? [] : '');
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }

    /** How many filters are in use (a date range or a cost range counts once). */
    public function count(): int
    {
        return count($this->chips([]));
    }

    /** The ?f[...] part for links, or nothing when no filter is in use. @return array<string, array<string, mixed>> */
    public function query(): array
    {
        return $this->values === [] ? [] : ['f' => $this->values];
    }

    /** @param list<string> $keys @return array<string, array<string, mixed>> the same, without these filters */
    private function queryWithout(array $keys): array
    {
        $rest = array_diff_key($this->values, array_flip($keys));
        return $rest === [] ? [] : ['f' => $rest];
    }

    /**
     * One removable chip per filter in use.
     *
     * @param array<string, array<int|string, string>> $names short labels for picked ids, by filter key (type, dept, ...)
     * @return list<array{label:string, query:array<string, array<string, mixed>>}> "query" is the filters left when it is removed
     */
    public function chips(array $names): array
    {
        $chips = [];
        $add = function (string $label, array $keys) use (&$chips): void {
            $chips[] = ['label' => $label, 'query' => $this->queryWithout($keys)];
        };

        foreach (self::PICKS as $key => [, $label]) {
            if (isset($this->values[$key])) {
                $shown = array_map(static fn($id): string => (string) ($names[$key][$id] ?? ($key === 'disp' ? $id : '#' . $id)), $this->values[$key]);
                $add($label . ': ' . implode(', ', $shown), [$key]);
            }
        }
        foreach (self::TEXTS as $key => [, $label]) {
            if (isset($this->values[$key])) {
                $add($label . ' contains “' . $this->values[$key] . '”', [$key]);
            }
        }
        $min = $this->values['cost_min'] ?? null;
        $max = $this->values['cost_max'] ?? null;
        if ($min !== null || $max !== null) {
            $add('Cost: ' . ($min !== null && $max !== null ? "{$min} to {$max}" : ($min !== null ? "{$min} or more" : "{$max} or less")), ['cost_min', 'cost_max']);
        }
        foreach (self::DATES as $key => [, $label]) {
            $from = $this->values[$key . '_from'] ?? null;
            $to = $this->values[$key . '_to'] ?? null;
            if ($from !== null || $to !== null) {
                $add($label . ': ' . ($from !== null && $to !== null ? "{$from} to {$to}" : ($from !== null ? "from {$from}" : "until {$to}")), [$key . '_from', $key . '_to']);
            }
        }
        if (isset($this->values['ver'])) {
            $add($this->values['ver'] === 'stale' ? 'Not verified since ' . $this->values['ver_before'] : self::VERIFICATION[$this->values['ver']], ['ver', 'ver_before']);
        }
        foreach (self::PEOPLE as $key => [, $label]) {
            if (isset($this->values[$key])) {
                $add($label . ': ' . ($names[$key][$this->values[$key]] ?? ('#' . $this->values[$key])), [$key]);
            }
        }
        return $chips;
    }

    /**
     * The filters as SQL to append to a WHERE clause, over the assets table aliased "a".
     *
     * @return array{0:string, 1:list<mixed>} [" AND ..." or "", parameters]
     */
    public function where(): array
    {
        if ($this->values === []) {
            return ['', []];
        }
        $sql = [];
        $params = [];

        foreach (self::PICKS as $key => [$column]) {
            if (isset($this->values[$key])) {
                $sql[] = $column . ' IN (' . implode(',', array_fill(0, count($this->values[$key]), '?')) . ')';
                array_push($params, ...$this->values[$key]);
            }
        }

        $texts = array_intersect_key($this->values, self::TEXTS);
        if ($texts !== [] && has_wide_chars(...array_map('strval', $texts))) {
            return [' AND 1 = 0', []]; // no stored text holds emoji, and the database cannot compare it (see has_wide_chars)
        }
        foreach (self::TEXTS as $key => [$column]) {
            if (isset($this->values[$key])) {
                $sql[] = $column . " LIKE ? ESCAPE '!'";
                $params[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], (string) $this->values[$key]) . '%';
            }
        }

        if (isset($this->values['cost_min'])) {
            $sql[] = 'a.cost >= ?';
            $params[] = $this->values['cost_min'];
        }
        if (isset($this->values['cost_max'])) {
            $sql[] = 'a.cost <= ?';
            $params[] = $this->values['cost_max'];
        }

        foreach (self::DATES as $key => [$column]) {
            if (isset($this->values[$key . '_from'])) {
                $sql[] = $column . ' >= ?';
                $params[] = $this->values[$key . '_from'] . ' 00:00:00';
            }
            if (isset($this->values[$key . '_to'])) { // up to and including that whole day
                $sql[] = $column . ' < ?';
                $params[] = (new \DateTimeImmutable((string) $this->values[$key . '_to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
            }
        }

        $ver = $this->values['ver'] ?? '';
        if ($ver === 'never') {
            $sql[] = 'a.verified_date IS NULL';
        } elseif ($ver === 'ever') {
            $sql[] = 'a.verified_date IS NOT NULL';
        } elseif ($ver === 'stale') {
            $sql[] = '(a.verified_date IS NULL OR a.verified_date < ?)';
            $params[] = $this->values['ver_before'] . ' 00:00:00';
        }

        foreach (self::PEOPLE as $key => [$column]) {
            if (isset($this->values[$key])) {
                $sql[] = $column . ' = ?';
                $params[] = $this->values[$key];
            }
        }

        return [$sql === [] ? '' : ' AND ' . implode(' AND ', $sql), $params];
    }
}
