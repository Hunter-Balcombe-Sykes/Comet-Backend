<?php

namespace Tests\Support\SchemaDrift;

/**
 * Diffs Postgres constraints (snapshot) against the SQLite test schema.
 * Only findings for tables/columns the test schema ACTUALLY DEFINES are
 * emitted — an absent table means no test exercises it, which is a
 * test-coverage question, not schema drift.
 */
class DriftComparator
{
    /** @return string[] sorted finding keys */
    public function compare(Snapshot $snapshot, SqliteIntrospector $sqlite): array
    {
        $findings = [];

        foreach ($snapshot->columns as $col) {
            if (! $col['not_null'] || ! $sqlite->tableExists($col['schema'], $col['table'])) {
                continue;
            }
            $notNull = $sqlite->columnNotNull($col['schema'], $col['table'], $col['column']);
            if ($notNull === false) { // column exists but nullable — the drift that 500s in prod
                $findings[] = "not_null_missing:{$col['schema']}.{$col['table']}.{$col['column']}";
            }
        }

        foreach ($snapshot->checks as $check) {
            if (! $sqlite->tableExists($check['schema'], $check['table'])) {
                continue;
            }
            $ddl = $sqlite->tableDdl($check['schema'], $check['table']) ?? '';
            $columns = $sqlite->columns($check['schema'], $check['table']);
            if (! $this->ddlCoversCheck($ddl, $check['definition'], $columns)) {
                $findings[] = "check_missing:{$check['schema']}.{$check['table']}:{$check['name']}";
            }
        }

        sort($findings);

        return $findings;
    }

    /**
     * Heuristic: the SQLite DDL "covers" a Postgres CHECK if it contains a
     * CHECK clause mentioning a real COLUMN of the table that the Postgres
     * definition also references. We compare presence, not expression
     * equivalence — translating Postgres syntax (ANY/ARRAY, ~, ::casts) is
     * out of scope.
     *
     * @param  string[]  $columns  real column names on the SQLite table (from
     *                             SqliteIntrospector::columns()) — restricts
     *                             matching to actual columns, not VALUE
     *                             LITERALS the naive identifier scan below
     *                             would otherwise also pick up.
     */
    private function ddlCoversCheck(string $ddl, string $pgDefinition, array $columns): bool
    {
        if (stripos($ddl, 'CHECK') === false) {
            return false;
        }

        preg_match_all('/[a-z_][a-z0-9_]*/i', $pgDefinition, $m);
        $identifiers = array_diff(array_unique($m[0]), ['CHECK', 'ANY', 'ARRAY', 'IS', 'NOT', 'NULL', 'AND', 'OR', 'IN', 'text', 'OTHERS', 'true', 'false']);

        // Restrict to identifiers that are ACTUAL columns of this table. The
        // regex above cannot tell a column name apart from a quoted value
        // literal in the Postgres definition (e.g. 'hide' in
        // `on_empty IN ('hide', ...)`), so two CHECKs on different columns
        // that happen to share a literal value (on_empty / stale_display
        // both allow 'hide') could otherwise cross-credit each other.
        $columnLookup = array_flip(array_map('strtolower', $columns));
        $constrainedColumns = array_values(array_filter($identifiers, fn ($id) => isset($columnLookup[strtolower($id)])));
        // Fallback for the (currently theoretical) case of a CHECK that
        // references no real column of its own table — keep the old,
        // looser behavior rather than silently never matching.
        if ($constrainedColumns === []) {
            $constrainedColumns = $identifiers;
        }

        // SQLite DDL has no internal semicolons, so matching must be bounded to
        // a single CHECK(...) clause — not "everything after the first CHECK" —
        // or an identifier that only appears as a later, unrelated column name
        // gets wrongly credited as covered. Handles one level of nested parens
        // (e.g. CHECK (status IN ('a','b'))).
        preg_match_all('/CHECK\s*\([^()]*(?:\([^()]*\)[^()]*)*\)/is', $ddl, $clauses);

        foreach ($clauses[0] as $clause) {
            foreach ($constrainedColumns as $ident) {
                if (preg_match('/\b'.preg_quote($ident, '/').'\b/i', $clause)) {
                    return true;
                }
            }
        }

        return false;
    }
}
