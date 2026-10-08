<?php

declare(strict_types=1);

namespace App\Modules\Import;

/**
 * Reads the INSERT rows out of a mysqldump / phpMyAdmin SQL file WITHOUT executing it.
 *
 * Only `INSERT INTO <known table> ... VALUES (...)` statements are looked at; everything else
 * (CREATE, ALTER, DROP, SET, comments, rows for other tables such as the retired `tassets`) is
 * skipped, so an uploaded file can never run arbitrary SQL. Values are decoded into PHP strings
 * and nulls and handed on to be written with prepared statements.
 */
final class DumpParser
{
    /**
     * Tables the importer understands, with their column order in the original schema. That order
     * is used for dumps whose INSERTs have no column list.
     */
    public const COLUMNS = [
        'departments'  => ['department_id', 'abbr', 'name'],
        'buildings'    => ['building_id', 'abbr', 'name'],
        'asset_types'  => ['type_id', 'name', 'depreciation_years'],
        'users'        => ['user_id', 'username', 'password', 'firstname', 'lastname', 'email', 'department_id', 'admin', 'timezone', 'lastlogin'],
        'permissions'  => ['user_id', 'department_id', 'permission'],
        'assets'       => ['asset_id', 'asset_number', 'serial_number', 'type_id', 'po_number', 'cost', 'purchaser_id', 'purchase_date',
                           'department_id', 'building_id', 'room', 'description', 'verified_date', 'notes', 'user_id', 'created_date'],
        'transfers'    => ['transfer_id', 'asset_id', 'user_id', 'department_from', 'department_to', 'location_from', 'location_to', 'reason', 'transfer_date'],
    ];

    private const ESCAPES = ['0' => "\0", 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1a"];

    /**
     * @return array{rows: array<string, list<array<string, ?string>>>, skipped: array<string, int>}
     *         rows: decoded rows per known table (column => string|null);
     *         skipped: number of INSERT statements ignored, per table name
     * @throws \RuntimeException with a line number when the file cannot be understood
     */
    public static function parse(string $sql): array
    {
        $len = strlen($sql);
        $pos = 0;
        $rows = [];
        $skipped = [];

        while (true) {
            $pos = self::skipTrivia($sql, $pos, $len);
            if ($pos >= $len) {
                break;
            }
            if (preg_match('/INSERT\s+(?:IGNORE\s+)?INTO\s+`?([A-Za-z0-9_$]+)`?\s*(\([^)]*\))?\s*VALUES\s*/Ai', $sql, $m, 0, $pos)) {
                $table = strtolower($m[1]);
                $after = $pos + strlen($m[0]);
                if (isset(self::COLUMNS[$table])) {
                    $cols = isset($m[2]) && $m[2] !== ''
                        ? array_map(static fn(string $c): string => strtolower(trim($c, " `\t\r\n")), explode(',', substr($m[2], 1, -1)))
                        : self::COLUMNS[$table];
                    $pos = self::readTuples($sql, $after, $len, $cols, $table, $rows);
                } else {
                    $skipped[$table] = ($skipped[$table] ?? 0) + 1;
                    $pos = self::skipStatement($sql, $after, $len);
                }
            } else {
                $pos = self::skipStatement($sql, $pos, $len);
            }
        }
        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /** Parse "(v, v, ...), (v, ...);" starting at $pos; append assoc rows; return the position after the statement. */
    private static function readTuples(string $sql, int $pos, int $len, array $cols, string $table, array &$rows): int
    {
        $n = count($cols);
        while (true) {
            $pos = self::skipSpace($sql, $pos, $len);
            if ($pos >= $len || $sql[$pos] !== '(') {
                throw self::error($sql, $pos, "expected \"(\" in the rows of table {$table}");
            }
            $pos++;
            $values = [];
            while (true) {
                $pos = self::skipSpace($sql, $pos, $len);
                [$value, $pos] = self::readValue($sql, $pos, $len);
                $values[] = $value;
                $pos = self::skipSpace($sql, $pos, $len);
                $c = $sql[$pos] ?? '';
                $pos++;
                if ($c === ',') {
                    continue;
                }
                if ($c === ')') {
                    break;
                }
                throw self::error($sql, $pos - 1, "expected \",\" or \")\" in a row of table {$table}");
            }
            if (count($values) !== $n) {
                throw self::error($sql, $pos, "a row of table {$table} has " . count($values) . " values but " . $n . ' columns were expected');
            }
            $rows[$table][] = array_combine($cols, $values);

            $pos = self::skipSpace($sql, $pos, $len);
            $c = $sql[$pos] ?? ';';
            if ($c === ',') {
                $pos++;
                continue;
            }
            return $c === ';' ? $pos + 1 : $pos;
        }
    }

    /** @return array{0: ?string, 1: int} decoded value and the position after it */
    private static function readValue(string $sql, int $pos, int $len): array
    {
        $c = $sql[$pos] ?? '';
        if ($c === "'") {
            if (!preg_match("/'((?:[^'\\\\]++|\\\\.|'')*+)'/As", $sql, $m, 0, $pos)) {
                throw self::error($sql, $pos, 'a text value is not closed');
            }
            $raw = $m[1];
            if (strpbrk($raw, "\\'") !== false) {
                $raw = preg_replace_callback(
                    '/\\\\(.)|\'\'/s',
                    static function (array $x): string {
                        if ($x[0] === "''") {
                            return "'";
                        }
                        $ch = $x[1];
                        if ($ch === '%' || $ch === '_') {
                            return '\\' . $ch; // MySQL keeps the backslash for \% and \_
                        }
                        return self::ESCAPES[$ch] ?? $ch;
                    },
                    $raw
                ) ?? $raw;
            }
            return [$raw, $pos + strlen($m[0])];
        }
        if (preg_match('/NULL\b/Ai', $sql, $m, 0, $pos)) {
            return [null, $pos + 4];
        }
        if (preg_match('/-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/A', $sql, $m, 0, $pos)) {
            return [$m[0], $pos + strlen($m[0])];
        }
        throw self::error($sql, $pos, 'unsupported value "' . substr($sql, $pos, 20) . '" (only text, numbers and NULL are supported)');
    }

    private static function skipSpace(string $sql, int $pos, int $len): int
    {
        return $pos + strspn($sql, " \t\r\n", $pos);
    }

    /** Skip whitespace, stray semicolons and comments between statements. */
    private static function skipTrivia(string $sql, int $pos, int $len): int
    {
        while ($pos < $len) {
            $pos = self::skipSpace($sql, $pos, $len);
            $c = $sql[$pos] ?? '';
            if ($c === ';') {
                $pos++;
            } elseif ($c === '#' || ($c === '-' && ($sql[$pos + 1] ?? '') === '-')) {
                $nl = strpos($sql, "\n", $pos);
                $pos = $nl === false ? $len : $nl + 1;
            } elseif ($c === '/' && ($sql[$pos + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $pos + 2);
                $pos = $end === false ? $len : $end + 2;
            } else {
                break;
            }
        }
        return $pos;
    }

    /** Skip to just after the next ";" that is not inside quotes or a comment. */
    private static function skipStatement(string $sql, int $pos, int $len): int
    {
        while ($pos < $len) {
            $pos += strcspn($sql, ";'\"`#-/", $pos);
            if ($pos >= $len) {
                break;
            }
            $c = $sql[$pos];
            if ($c === ';') {
                return $pos + 1;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                if (!preg_match('/' . $c . '(?:[^' . $c . '\\\\]++|\\\\.|' . $c . $c . ')*+' . $c . '/As', $sql, $m, 0, $pos)) {
                    return $len; // unterminated quote: nothing more to read
                }
                $pos += strlen($m[0]);
            } elseif ($c === '#' || ($c === '-' && ($sql[$pos + 1] ?? '') === '-')) {
                $nl = strpos($sql, "\n", $pos);
                $pos = $nl === false ? $len : $nl + 1;
            } elseif ($c === '/' && ($sql[$pos + 1] ?? '') === '*') {
                $end = strpos($sql, '*/', $pos + 2);
                $pos = $end === false ? $len : $end + 2;
            } else {
                $pos++; // a lone "-" or "/"
            }
        }
        return $len;
    }

    private static function error(string $sql, int $pos, string $what): \RuntimeException
    {
        return new \RuntimeException('Line ' . (substr_count($sql, "\n", 0, min($pos, strlen($sql))) + 1) . ': ' . $what . '.');
    }
}
