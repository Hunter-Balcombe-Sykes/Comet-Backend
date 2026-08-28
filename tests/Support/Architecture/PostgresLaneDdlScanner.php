<?php

namespace Tests\Support\Architecture;

/**
 * Reads two DDL sources and reports where they disagree:
 *  - supabase/migrations/*.sql — the real schema, always current in the repo;
 *  - tests/Postgres/*.php — the PG lane's hand-written stand-in DDL.
 *
 * Deliberately parses the migrations rather than scripts/launch-check/
 * schema-snapshot.json: that snapshot is refreshed by hand (2026-07-25 when this
 * was written, a month behind dev) and would report every table added since as
 * missing. The migrations move with the branch.
 */
final class PostgresLaneDdlScanner
{
    /**
     * A lane-local fixture table, not a stand-in for anything real. The suffix
     * is the whole convention — there is no hand-kept list to fall out of date,
     * and a new fixture simply has to be named for what it is.
     */
    public const SCRATCH_SUFFIXES = ['_probe', '_scratch', '_test'];

    public static function isScratch(string $table): bool
    {
        foreach (self::SCRATCH_SUFFIXES as $suffix) {
            if (str_ends_with($table, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The real schema after every migration is applied in filename order.
     *
     * @return array<string, list<string>> "schema.table" => column names
     */
    public static function realSchema(string $migrationsDir): array
    {
        $tables = [];

        $files = glob(rtrim($migrationsDir, '/').'/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            $sql = self::stripComments((string) file_get_contents($file));

            foreach (self::parseCreateTables($sql) as $table => $columns) {
                $tables[$table] = array_values(array_unique(array_merge($tables[$table] ?? [], $columns)));
            }

            // ALTER TABLE … ADD/RENAME/DROP COLUMN, applied in order so a later
            // rename or drop wins over the CREATE that introduced the column.
            preg_match_all('/alter\s+table\s+(?:if\s+exists\s+)?(?:only\s+)?([a-z_0-9".]+)([^;]*);/is', $sql, $alters, PREG_SET_ORDER);
            foreach ($alters as $alter) {
                $table = self::normalise($alter[1]);
                $rest = $alter[2];

                preg_match_all('/add\s+column\s+(?:if\s+not\s+exists\s+)?([a-z_0-9"]+)/i', $rest, $added);
                foreach ($added[1] as $column) {
                    $tables[$table][] = self::normalise($column);
                }

                preg_match_all('/rename\s+column\s+([a-z_0-9"]+)\s+to\s+([a-z_0-9"]+)/i', $rest, $renamed, PREG_SET_ORDER);
                foreach ($renamed as $rename) {
                    $tables[$table][] = self::normalise($rename[2]);
                    $tables[$table] = array_diff($tables[$table], [self::normalise($rename[1])]);
                }

                preg_match_all('/drop\s+column\s+(?:if\s+exists\s+)?([a-z_0-9"]+)/i', $rest, $dropped);
                foreach ($dropped[1] as $column) {
                    $tables[$table] = array_diff($tables[$table] ?? [], [self::normalise($column)]);
                }

                $tables[$table] = array_values(array_unique($tables[$table] ?? []));
            }

            preg_match_all('/drop\s+table\s+(?:if\s+exists\s+)?([a-z_0-9".,\s]+?)(?:cascade|restrict|;)/is', $sql, $drops);
            foreach ($drops[1] as $list) {
                foreach (explode(',', $list) as $table) {
                    unset($tables[self::normalise($table)]);
                }
            }
        }

        return $tables;
    }

    /**
     * Every table the PG lane creates, and which file created it.
     *
     * @return array<string, array<string, list<string>>> "schema.table" => column => files
     */
    public static function laneDdl(string $laneDir): array
    {
        $lane = [];

        $files = glob(rtrim($laneDir, '/').'/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $sql = self::stripComments((string) file_get_contents($file));
            foreach (self::parseCreateTables($sql) as $table => $columns) {
                foreach ($columns as $column) {
                    $lane[$table][$column][] = basename($file);
                }
            }
        }

        return $lane;
    }

    /**
     * Lane DDL that names something the real schema does not have.
     *
     * @return array{tables: array<string, list<string>>, columns: list<string>}
     */
    public static function drift(string $migrationsDir, string $laneDir): array
    {
        $real = self::realSchema($migrationsDir);
        $lane = self::laneDdl($laneDir);

        $missingTables = [];
        $missingColumns = [];

        foreach ($lane as $table => $columns) {
            if (self::isScratch($table)) {
                continue;
            }

            if (! isset($real[$table])) {
                $missingTables[$table] = array_values(array_unique(array_merge(...array_values($columns))));

                continue;
            }

            foreach ($columns as $column => $files) {
                if (! in_array($column, $real[$table], true)) {
                    $missingColumns[] = sprintf('%s.%s — declared in %s', $table, $column, implode(', ', array_unique($files)));
                }
            }
        }

        ksort($missingTables);
        sort($missingColumns);

        return ['tables' => $missingTables, 'columns' => $missingColumns];
    }

    /**
     * The baseline quotes every part ("core"."pre_account_builds"), so the
     * quotes have to be removed throughout — trimming only the ends leaves the
     * inner pair and silently mis-keys the whole baseline.
     */
    private static function normalise(string $identifier): string
    {
        return strtolower(trim(str_replace('"', '', $identifier)));
    }

    private static function stripComments(string $sql): string
    {
        $sql = preg_replace('/--[^\n]*/', '', $sql) ?? $sql;

        return preg_replace('#/\*.*?\*/#s', '', $sql) ?? $sql;
    }

    /**
     * CREATE TABLE bodies, read by balancing parentheses rather than by regex —
     * a column list contains nested parens (numeric(10,2), CHECK (…)) that no
     * single pattern survives.
     *
     * @return array<string, list<string>>
     */
    private static function parseCreateTables(string $sql): array
    {
        $tables = [];
        $offset = 0;

        while (preg_match('/create\s+table\s+(?:if\s+not\s+exists\s+)?([a-z_0-9".]+)\s*\(/i', $sql, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $table = self::normalise($m[1][0]);
            $open = $m[0][1] + strlen($m[0][0]) - 1;
            $offset = $m[0][1] + strlen($m[0][0]);

            $close = self::matchingParen($sql, $open);
            if ($close === null) {
                continue;
            }

            $columns = [];
            foreach (self::splitTopLevel(substr($sql, $open + 1, $close - $open - 1)) as $part) {
                $part = trim(preg_replace('/\s+/', ' ', $part) ?? $part);
                if ($part === '') {
                    continue;
                }

                $first = self::normalise(explode(' ', $part)[0]);
                // A table-level constraint, not a column.
                if (in_array(strtoupper($first), ['PRIMARY', 'UNIQUE', 'CHECK', 'FOREIGN', 'CONSTRAINT', 'EXCLUDE', 'LIKE', 'PARTITION'], true)) {
                    continue;
                }

                $columns[] = $first;
            }

            $tables[$table] = array_values(array_unique(array_merge($tables[$table] ?? [], $columns)));
        }

        return $tables;
    }

    private static function matchingParen(string $sql, int $open): ?int
    {
        $depth = 0;
        for ($i = $open, $n = strlen($sql); $i < $n; $i++) {
            if ($sql[$i] === '(') {
                $depth++;
            } elseif ($sql[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function splitTopLevel(string $body): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;

        for ($i = 0, $n = strlen($body); $i < $n; $i++) {
            $char = $body[$i];

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $parts[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        $parts[] = $buffer;

        return $parts;
    }
}
