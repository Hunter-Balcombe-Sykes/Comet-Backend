<?php

namespace Tests\Support\SchemaDrift;

/**
 * Replays supabase/migrations/*.sql in filename order to derive each
 * table's CURRENT real column set. DB-independent by design — see the guard
 * block comment above for why that matters. Ignores commented-out lines
 * (`--` to end of line, `/* ... *\/` blocks) and IF [NOT] EXISTS noise (the
 * regexes make those clauses optional, not load-bearing).
 *
 * Moved out of tests/Feature/Security/DataExportCoverageTest.php (#FFLAG-1 /
 * TESTFX-1 fix) — a class declared inside a Pest test file only exists once
 * PHPUnit has included that file, and include order is not guaranteed under
 * --filter or --parallel, so a different test file could run in a worker
 * where DataExportCoverageTest.php was never loaded and fatal on this class
 * name. PSR-4 autoloading here removes that ordering dependency. PURE MOVE —
 * no parsing logic changed. See the assumption documented on tables() below,
 * which still applies verbatim.
 */
class MigrationColumnReplay
{
    /** @var array<string, array<string, true>>|null */
    private static ?array $cache = null;

    /**
     * @return array<string, array<string, true>> 'schema.table' => set of real column names
     */
    public static function tables(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $files = glob(base_path('supabase/migrations/*.sql'));
        sort($files);

        $tables = [];

        foreach ($files as $file) {
            $sql = self::stripComments((string) file_get_contents($file));
            // KNOWN ASSUMPTION: this split is NOT string-literal-aware — a semicolon
            // inside a DEFAULT '...' or CHECK(...) string literal would fragment one
            // real statement into two. Checked: not present in any of the current 195
            // migrations (no `;` appears inside a quoted string literal in this
            // repo's DDL). If it ever happens, the usual outcome is a LOUD failure:
            // the orphaned first fragment loses its CREATE/ALTER TABLE keyword match
            // (matchParen() can't find the closing paren within a truncated CREATE,
            // so the table is skipped entirely -> T2's "table not found in replay"),
            // or a real column goes missing -> T2's "not a real column" violation.
            // One asymmetric case is NOT loud, so it's worth naming explicitly: if
            // the fragmenting semicolon sits inside an ADD-COLUMN clause's DEFAULT
            // string that PRECEDES a DROP COLUMN clause in the same ALTER statement
            // (e.g. `ADD COLUMN note text DEFAULT 'hi; there', DROP COLUMN old_col`),
            // the orphaned second fragment (`there', DROP COLUMN old_col`) no longer
            // starts with ALTER TABLE, so applyStatement() silently no-ops on it and
            // the DROP never applies — old_col would incorrectly stay "live" in the
            // replay. Not currently reachable (no such migration exists), but a
            // future migration author should keep string literals semicolon-free
            // rather than rely on this replay to catch it.
            $statements = array_filter(array_map('trim', explode(';', $sql)), fn ($s) => $s !== '');

            foreach ($statements as $stmt) {
                self::applyStatement($stmt, $tables);
            }
        }

        return self::$cache = $tables;
    }

    private static function stripComments(string $sql): string
    {
        $sql = preg_replace('#/\*.*?\*/#s', '', $sql) ?? $sql;

        $lines = explode("\n", $sql);
        foreach ($lines as &$line) {
            $pos = strpos($line, '--');
            if ($pos !== false) {
                $line = substr($line, 0, $pos);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, array<string, true>>  $tables
     */
    private static function applyStatement(string $stmt, array &$tables): void
    {
        // CREATE TABLE schema.table (col defs...)
        if (preg_match('/^CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?"?([a-zA-Z_]\w*)"?\s*\.\s*"?([a-zA-Z_]\w*)"?\s*\(/is', $stmt, $m, PREG_OFFSET_CAPTURE)) {
            $schema = $m[2][0];
            $table = $m[3][0];
            $key = "{$schema}.{$table}";

            $openParenPos = strpos($stmt, '(', (int) ($m[0][1] + strlen($m[0][0]) - 1));
            if ($openParenPos === false) {
                return;
            }
            $closeParenPos = self::matchParen($stmt, $openParenPos);
            if ($closeParenPos === -1) {
                return;
            }

            $body = substr($stmt, $openParenPos + 1, $closeParenPos - $openParenPos - 1);
            $cols = [];
            foreach (self::splitTopLevel($body) as $def) {
                $def = trim($def);
                if ($def === '' || preg_match('/^(CONSTRAINT|PRIMARY\s+KEY|UNIQUE|CHECK|FOREIGN\s+KEY|EXCLUDE)\b/i', $def)) {
                    // Table-level constraint, not a column definition.
                    continue;
                }
                if (preg_match('/^"?([a-zA-Z_]\w*)"?\s+/i', $def, $cm)) {
                    $cols[] = $cm[1];
                }
            }
            $tables[$key] = array_fill_keys($cols, true);

            return;
        }

        // ALTER TABLE schema.table <one or more comma-separated clauses>
        if (preg_match('/^ALTER\s+TABLE\s+(?:ONLY\s+)?"?([a-zA-Z_]\w*)"?\s*\.\s*"?([a-zA-Z_]\w*)"?\s+(.*)$/is', $stmt, $m)) {
            $schema = $m[1];
            $table = $m[2];
            $key = "{$schema}.{$table}";

            foreach (self::splitTopLevel($m[3]) as $clause) {
                $clause = trim($clause);

                if (preg_match('/^ADD\s+COLUMN\s+(IF\s+NOT\s+EXISTS\s+)?"?([a-zA-Z_]\w*)"?/i', $clause, $cm)) {
                    $tables[$key][$cm[2]] = true;

                    continue;
                }
                if (preg_match('/^DROP\s+COLUMN\s+(IF\s+EXISTS\s+)?"?([a-zA-Z_]\w*)"?/i', $clause, $cm)) {
                    unset($tables[$key][$cm[2]]);

                    continue;
                }
                if (preg_match('/^RENAME\s+COLUMN\s+"?([a-zA-Z_]\w*)"?\s+TO\s+"?([a-zA-Z_]\w*)"?/i', $clause, $cm)) {
                    unset($tables[$key][$cm[1]]);
                    $tables[$key][$cm[2]] = true;

                    continue;
                }
                if (preg_match('/^RENAME\s+TO\s+"?([a-zA-Z_]\w*)"?/i', $clause, $cm)) {
                    $newKey = "{$schema}.{$cm[1]}";
                    if (isset($tables[$key])) {
                        $tables[$newKey] = $tables[$key];
                        unset($tables[$key]);
                    }
                    $key = $newKey;

                    continue;
                }
                if (preg_match('/^SET\s+SCHEMA\s+"?([a-zA-Z_]\w*)"?/i', $clause, $cm)) {
                    $newKey = "{$cm[1]}.{$table}";
                    if (isset($tables[$key])) {
                        $tables[$newKey] = $tables[$key];
                        unset($tables[$key]);
                    }
                    $key = $newKey;

                    continue;
                }
                // ADD CONSTRAINT, OWNER TO, ENABLE/DISABLE RLS, ALTER COLUMN TYPE, etc. — no column-set effect.
            }

            return;
        }

        // DROP TABLE schema.table
        if (preg_match('/^DROP\s+TABLE\s+(IF\s+EXISTS\s+)?"?([a-zA-Z_]\w*)"?\s*\.\s*"?([a-zA-Z_]\w*)"?/is', $stmt, $m)) {
            unset($tables["{$m[2]}.{$m[3]}"]);
        }
    }

    private static function matchParen(string $str, int $openPos): int
    {
        $depth = 0;
        $len = strlen($str);
        for ($i = $openPos; $i < $len; $i++) {
            if ($str[$i] === '(') {
                $depth++;
            } elseif ($str[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return -1;
    }

    /**
     * Split on top-level commas only (parens-aware) — a column def's own
     * DEFAULT/CHECK expression may contain commas that must not split it.
     *
     * @return array<int, string>
     */
    private static function splitTopLevel(string $str): array
    {
        $parts = [];
        $depth = 0;
        $buf = '';
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $c = $str[$i];
            if ($c === '(') {
                $depth++;
                $buf .= $c;

                continue;
            }
            if ($c === ')') {
                $depth--;
                $buf .= $c;

                continue;
            }
            if ($c === ',' && $depth === 0) {
                $parts[] = $buf;
                $buf = '';

                continue;
            }
            $buf .= $c;
        }
        if (trim($buf) !== '') {
            $parts[] = $buf;
        }

        return $parts;
    }
}
