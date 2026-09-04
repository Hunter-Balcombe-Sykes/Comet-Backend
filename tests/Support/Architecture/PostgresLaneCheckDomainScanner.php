<?php

namespace Tests\Support\Architecture;

/**
 * PostgresLaneDdlScanner asks whether tests/Postgres/*.php declares a column
 * supabase/migrations/ doesn't have. It says outright it stops at column NAMES —
 * it has no jurisdiction over what a CHECK on that column admits. This scanner
 * fills that hole: for every domain-list CHECK a PG-lane stand-in hand-writes
 * (`col IN (...)` / `col = ANY (ARRAY[...])`, inline on the column, as a
 * table-level CONSTRAINT clause, or via a standalone ALTER TABLE ADD CONSTRAINT),
 * is its value set a STRICT SUBSET of what supabase/migrations/ currently admit
 * for that same table.column?
 *
 * Strict subset, not "different", is the failure mode that matters: a stand-in
 * with an EXTRA value, or the same set, still lets every write the real schema
 * allows through. Only a MISSING value produces the false green this exists to
 * catch — the stand-in's own tests stay green because none of them happens to
 * write the value the migration added, right up until one does, and 23514s on a
 * write that is perfectly valid in every real environment. Fixed by hand
 * 2026-09-04 for three such gaps: routing.source_intents.state (missing
 * 'verifying'), .block_reason (missing 'not_found' and 'sibling_branch' — the
 * latter a live landmine, SourceReconciler.php:185 already writes it), and
 * content.item_media.role (missing 'video', across 9 files).
 *
 * "Current" on the migration side means after every migration applies, in
 * filename order, same discipline as SourceIntentDomainTest's
 * sourceIntentCheckDomain(): a later migration DROPs and re-ADDs a constraint to
 * widen it (routing.source_intents.block_reason has three such generations), so
 * reading only the first file that mentions a column stays comparing a domain
 * the database no longer has.
 */
final class PostgresLaneCheckDomainScanner
{
    /**
     * One hand-written domain-list CHECK, successfully resolved to the
     * table.column it belongs to.
     *
     * @return list<array{file: string, table: string, column: string, values: list<string>}>
     */
    public static function laneChecks(string $laneDir): array
    {
        $checks = [];

        $files = glob(rtrim($laneDir, '/').'/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            foreach (self::extractChecks((string) file_get_contents($file)) as $check) {
                if ($check['table'] === null) {
                    continue;
                }

                $checks[] = [
                    'file' => basename($file),
                    'table' => $check['table'],
                    'column' => $check['column'],
                    'values' => $check['values'],
                ];
            }
        }

        return $checks;
    }

    /**
     * Domain-list CHECKs found in tests/Postgres/*.php whose enclosing statement
     * this parser could not identify — neither inside a CREATE TABLE body nor
     * part of a standalone ALTER TABLE ADD CONSTRAINT. Must be empty, or every
     * entry named in the test's own UNPARSEABLE_LANE_CHECKS allowlist with a
     * reason: a silent skip here would make the drift gate above vacuous for
     * exactly the CHECK a future stand-in edit is most likely to mis-shape.
     *
     * @return list<array{file: string, column: string, values: list<string>}>
     */
    public static function laneUnresolved(string $laneDir): array
    {
        $unresolved = [];

        $files = glob(rtrim($laneDir, '/').'/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            foreach (self::extractChecks((string) file_get_contents($file)) as $check) {
                if ($check['table'] === null) {
                    $unresolved[] = [
                        'file' => basename($file),
                        'column' => $check['column'],
                        'values' => $check['values'],
                    ];
                }
            }
        }

        return $unresolved;
    }

    /**
     * The domain each table.column's CHECK admits TODAY, across superseding
     * migrations, keyed "schema.table.column". Only supabase/migrations/ — the
     * archive is retired history, not current truth (same convention as
     * PostgresLaneDdlScanner::realSchema()).
     *
     * @return array<string, list<string>>
     */
    public static function migrationDomains(string $migrationsDir): array
    {
        $domains = [];

        foreach (self::migrationChecksInOrder($migrationsDir) as $check) {
            // Last write wins: a later ADD CONSTRAINT for the same table.column
            // supersedes an earlier one (or the inline CREATE TABLE definition)
            // outright, exactly as Postgres itself only ever has one live
            // constraint under a given name at a time.
            $domains["{$check['table']}.{$check['column']}"] = $check['values'];
        }

        return $domains;
    }

    /**
     * Every domain-list CHECK found in supabase/migrations/, in filename
     * (= apply) order, before the last-wins fold — kept separate from
     * migrationDomains() so the test can report a raw parsed count distinct
     * from the deduplicated table.column count.
     *
     * @return list<array{file: string, table: string, column: string, values: list<string>}>
     */
    public static function migrationChecksInOrder(string $migrationsDir): array
    {
        $checks = [];

        $files = glob(rtrim($migrationsDir, '/').'/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            foreach (self::extractChecks((string) file_get_contents($file)) as $check) {
                if ($check['table'] === null) {
                    // Migration DDL is hand-audited house style (CONVENTIONS.md);
                    // every CHECK here has always resolved in practice. Surfaced
                    // as a count rather than silently dropped — see the test's
                    // "parses both sides" assertion.
                    continue;
                }

                $checks[] = [
                    'file' => basename($file),
                    'table' => $check['table'],
                    'column' => $check['column'],
                    'values' => $check['values'],
                ];
            }
        }

        return $checks;
    }

    /**
     * Every unresolved (table === null) domain-list CHECK found on the
     * migration side. Not called by migrationDomains()/migrationChecksInOrder()
     * (which silently drop them, being hand-audited house style already
     * covered by CONVENTIONS.md review) — exists purely so the test can prove
     * that silence is actually zero, not unchecked.
     *
     * @return list<array{file: string, column: string, values: list<string>}>
     */
    public static function migrationUnresolved(string $migrationsDir): array
    {
        $unresolved = [];

        $files = glob(rtrim($migrationsDir, '/').'/*.sql') ?: [];
        sort($files);

        foreach ($files as $file) {
            foreach (self::extractChecks((string) file_get_contents($file)) as $check) {
                if ($check['table'] === null) {
                    $unresolved[] = [
                        'file' => basename($file),
                        'column' => $check['column'],
                        'values' => $check['values'],
                    ];
                }
            }
        }

        return $unresolved;
    }

    /**
     * The core parse, shared by both DDL sources: every domain-list CHECK in
     * the file, resolved to a table where possible.
     *
     * Two statement shapes carry a table name explicitly enough to resolve from
     * text alone:
     *   1. Inside a CREATE TABLE body — inline on the column
     *      (`role text CHECK (role IN (...))`) or as a table-level constraint
     *      clause (`CONSTRAINT x CHECK (reason IN (...))`, the pg_dump-baseline
     *      idiom and SubdomainAliasCollisionTest's handle_change_log). Resolved
     *      by byte-range containment: the CHECK's paren span falls inside some
     *      CREATE TABLE body's paren span.
     *   2. A standalone `ALTER TABLE <table> ADD CONSTRAINT <name> CHECK (...)`.
     *      Resolved by scanning backward from the CHECK to the most recent `;`
     *      and checking whether "ALTER TABLE" appears in between — reliable
     *      even inside a `DO $$ BEGIN ... END $$;` block (20260903220001's
     *      shape), because the DROP CONSTRAINT statement immediately before the
     *      ADD is itself `;`-terminated inside the block.
     *
     * A CHECK found in neither position — right now that means exactly one
     * idiom, the `$singletons`/`$facet` loop shared by FacetOriginScopeTest.php
     * and its eight PG-lane siblings, where `content.f_occurrence`'s
     * `zone_confidence` CHECK lives inside a column-definition STRING assigned
     * to an array value (e.g. FacetOriginScopeTest.php:225) and is only ever
     * joined to its table name one loop iteration later via
     * `CREATE TABLE content.{$facet} (...)` — comes back with table === null
     * for the caller to report rather than drop.
     *
     * A CHECK with no `IN (...)` / `= ANY (ARRAY[...])` at all (`confidence
     * BETWEEN 0 AND 100`, a bare inequality) has no value list to compare and
     * is not returned — there is nothing here that could go stale in the way
     * this file exists to catch. Same for the two files
     * (SourceIntentDomainTest.php, SectionShapeDomainTest.php) that build their
     * CHECK's IN (...) list via `{$inList($values)}` PHP interpolation rather
     * than hand-typed literals: with no quoted literal directly inside the
     * parens, they never match the pattern below, which is correct — a domain
     * built at runtime FROM the migration itself cannot drift from it.
     *
     * @return list<array{table: ?string, column: string, values: list<string>}>
     */
    private static function extractChecks(string $sql): array
    {
        $sql = self::stripComments($sql);
        $results = [];

        $createBodies = self::findCreateTableBodies($sql);

        $offset = 0;
        while (preg_match('/\bcheck\s*\(/i', $sql, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $checkStart = $m[0][1];
            $open = $checkStart + strlen($m[0][0]) - 1;
            $offset = $checkStart + strlen($m[0][0]);

            // `CREATE POLICY ... USING (...) WITH CHECK (...)` is Postgres RLS,
            // not a domain constraint — baseline_pilot.sql's pg_dump output
            // carries 80+ of these, two of which (core.partna_staff's RLS
            // predicate) happen to embed the exact literal list
            // ('admin','support') its own REAL table CHECK also carries a few
            // hundred bytes earlier, which would otherwise misreport as an
            // unresolved CHECK the parser found and couldn't place. No real
            // domain CHECK in this codebase is ever written as "WITH CHECK".
            if (preg_match('/\bwith\s*$/i', substr($sql, max(0, $checkStart - 10), $checkStart - max(0, $checkStart - 10)))) {
                continue;
            }

            $close = self::matchingParen($sql, $open);
            if ($close === null) {
                continue;
            }

            $content = substr($sql, $open + 1, $close - $open - 1);
            $offset = $close + 1;

            $column = self::extractDomainColumn($content);
            if ($column === null) {
                // No IN (...) / = ANY (ARRAY[...]) with a literal inside — not a
                // domain-list CHECK (a range check, an interpolated stand-in
                // like SourceIntentDomainTest's, etc). Out of scope by
                // definition, not a skip: there is no value list to have gone
                // stale.
                continue;
            }

            $values = array_values(array_unique(self::extractQuotedLiterals($content)));
            if ($values === []) {
                continue;
            }

            $table = self::precedingAlterTable($sql, $checkStart)
                ?? self::containingCreateTable($createBodies, $checkStart, $close);

            $results[] = ['table' => $table, 'column' => $column, 'values' => $values];
        }

        return $results;
    }

    /** @return list<array{0: int, 1: int, 2: string}> [bodyStart, bodyEnd, table] */
    private static function findCreateTableBodies(string $sql): array
    {
        $bodies = [];
        $offset = 0;

        while (preg_match('/create\s+table\s+(?:if\s+not\s+exists\s+)?([a-z_0-9".]+)\s*\(/i', $sql, $m, PREG_OFFSET_CAPTURE, $offset)) {
            $table = self::normaliseIdentifier($m[1][0]);
            $open = $m[0][1] + strlen($m[0][0]) - 1;
            $offset = $m[0][1] + strlen($m[0][0]);

            $close = self::matchingParen($sql, $open);
            if ($close === null) {
                continue;
            }

            $bodies[] = [$open, $close, $table];
            $offset = $close + 1;
        }

        return $bodies;
    }

    /** @param  list<array{0: int, 1: int, 2: string}>  $bodies */
    private static function containingCreateTable(array $bodies, int $checkStart, int $checkEnd): ?string
    {
        foreach ($bodies as [$bodyStart, $bodyEnd, $table]) {
            if ($checkStart > $bodyStart && $checkEnd < $bodyEnd) {
                return $table;
            }
        }

        return null;
    }

    /**
     * True exactly when this CHECK's own SQL statement is a standalone
     * `ALTER TABLE <table> ADD CONSTRAINT ...` — found by taking the text back
     * to the most recent `;` (a real statement boundary even inside a
     * `DO $$ BEGIN ... END $$;` block, since every statement inside one is
     * still `;`-terminated) and looking for "ALTER TABLE" in what remains.
     */
    private static function precedingAlterTable(string $sql, int $checkStart): ?string
    {
        $statementStart = strrpos(substr($sql, 0, $checkStart), ';');
        $statementStart = $statementStart === false ? 0 : $statementStart + 1;

        $prefix = substr($sql, $statementStart, $checkStart - $statementStart);

        if (! preg_match('/alter\s+table\s+(?:only\s+)?([a-z_0-9".]+)/i', $prefix, $m)) {
            return null;
        }

        return self::normaliseIdentifier($m[1]);
    }

    /**
     * The column a CHECK's own IN (...) / = ANY (ARRAY[...]) list belongs to.
     * Matches the LAST occurrence in `... IS NULL OR <col> IN (...)` because a
     * plain (non-anchored) search tries every position left to right and only
     * the second `<col>` is directly followed by IN/ANY.
     */
    private static function extractDomainColumn(string $checkContent): ?string
    {
        if (! preg_match('/"?([a-z_][a-z_0-9]*)"?\s*(?:in\s*\(|=\s*any\s*\(\s*array\s*\[)/i', $checkContent, $m)) {
            return null;
        }

        return strtolower($m[1]);
    }

    /**
     * Every quoted literal inside a CHECK's IN (...) / ARRAY[...] list. Works
     * unchanged whether the source is a single-quoted PHP string, where each
     * value's quotes are themselves backslash-escaped (`\'proposed\'`), or a
     * double-quoted one / raw SQL, where they are not (`'proposed'`) — the
     * optional `\\?` either side of the value absorbs that escaping when it is
     * there and costs nothing when it isn't. A trailing Postgres `::text` /
     * `::"text"` cast (baseline_pilot.sql's pg_dump style) sits entirely after
     * the closing quote and is never captured, so it never breaks the match
     * either. Restricted to `[a-z_0-9]+` rather than "anything but a quote":
     * every domain value in this codebase is a lowercase snake_case token, and
     * the narrower class is what lets `\'` resolve as escaped-quote-then-value
     * instead of the backslash being swallowed into the captured value itself.
     *
     * @return list<string>
     */
    private static function extractQuotedLiterals(string $checkContent): array
    {
        preg_match_all("/\\\\?'([a-z_0-9]+)\\\\?'/i", $checkContent, $m);

        return $m[1];
    }

    private static function normaliseIdentifier(string $identifier): string
    {
        $identifier = str_replace('"', '', $identifier);
        $identifier = preg_replace('/\s*\.\s*/', '.', $identifier) ?? $identifier;

        return strtolower(trim($identifier));
    }

    private static function stripComments(string $sql): string
    {
        $sql = preg_replace('/--[^\n]*/', '', $sql) ?? $sql;

        return preg_replace('#/\*.*?\*/#s', '', $sql) ?? $sql;
    }

    /** Balances parens from an opening `(` at $open to find its matching `)`. */
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
}
