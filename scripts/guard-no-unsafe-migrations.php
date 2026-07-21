<?php

/**
 * Guard: no unsafe migration patterns (Master Pattern 20).
 *
 * Fails on five patterns that cause lock-induced downtime on populated tables:
 *   1. CREATE INDEX without CONCURRENTLY
 *   2. ADD CONSTRAINT ... FOREIGN KEY without NOT VALID
 *   3. ADD CONSTRAINT ... CHECK without NOT VALID
 *   4. ALTER COLUMN ... SET NOT NULL (must use the four-step NOT VALID pattern)
 *   5. DDL/DML on a hot table (HOT_TABLES) without a BEGIN + SET LOCAL lock_timeout
 *
 * Migrations with timestamps <= GRANDFATHERED_CUTOFF are skipped — they ran safely on
 * empty tables before this convention was established (2026-05-14, timestamp 20260514100000).
 * All new migrations after that date are subject to Checks 1-4.
 *
 * Check 5 has its own, later boundary (TIMEOUT_GUARD_CUTOFF) since it shipped well
 * after GRANDFATHERED_CUTOFF — see the constant's comment for why.
 *
 * See supabase/migrations/CONVENTIONS.md for the safe alternatives.
 */
const GRANDFATHERED_CUTOFF = '20260514100000';

// Check 5 (lock/statement timeouts) shipped 2026-07-20, long after the
// GRANDFATHERED_CUTOFF above -- it needs its own boundary. At this cutoff the
// check flags 0 files on a clean tree; with the original cutoff it would flag 66
// pre-convention files. Raising this is not the way to silence a failure: add the
// timeouts, or add the per-file disable marker with a written justification.
const TIMEOUT_GUARD_CUTOFF = '20260711999999';

// Check 6 (one CONCURRENTLY per file) shipped 2026-07-21, when the fresh-DB
// CONCURRENTLY-in-pipeline issue was resolved (path C: psql-loop applier). It needs
// its own boundary: 9 pre-existing files legitimately bundle multiple CONCURRENTLY
// statements and were applied to dev incrementally via non-pipelined paths. Splitting
// them retroactively would open migration-history gaps on the live dev DB, so they are
// grandfathered here; only NEW files must keep one CONCURRENTLY per file. At this cutoff
// the check flags 0 files on a clean tree.
const CONCURRENTLY_GUARD_CUTOFF = '20260721000000';

// Tables served by live traffic. The first three are named in
// docs/migration-guidelines.md §Lock and statement timeouts; core.users is added
// because it is read on every authenticated request.
const HOT_TABLES = ['site.design_kits', 'site.sites', 'site.blocks', 'core.users'];

const MIGRATIONS_DIR = 'supabase/migrations';

$errors = [];

if (! is_dir(MIGRATIONS_DIR)) {
    echo "Migration safety lint: no supabase/migrations directory found, skipping.\n";
    exit(0);
}

foreach (glob(MIGRATIONS_DIR.'/*.sql') as $file) {
    $basename = basename($file);

    // Extract the 14-digit timestamp prefix.
    if (! preg_match('/^(\d{14})/', $basename, $m)) {
        continue;
    }

    // Skip grandfathered migrations created before the convention was established.
    if ($m[1] <= GRANDFATHERED_CUTOFF) {
        continue;
    }

    $raw = file_get_contents($file);

    // Allow a per-file opt-out for migrations that intentionally deviate from the
    // safe-lock conventions (e.g., transactional CREATE INDEX that can't use
    // CONCURRENTLY, or FKs on empty columns added in the same migration).
    // Usage: add  -- guard:no-unsafe-migrations:disable-file  anywhere in the file.
    if (preg_match('/--\s*guard:no-unsafe-migrations:disable-file\b/i', $raw)) {
        continue;
    }

    // Strip single-line SQL comments so patterns inside comments don't false-positive.
    $content = preg_replace('/--[^\n]*/', '', $raw);

    // ── Check 1: CREATE INDEX without CONCURRENTLY ────────────────────────────
    // Matches CREATE INDEX and CREATE UNIQUE INDEX but not CREATE INDEX CONCURRENTLY.
    // Indexes on tables created in the same migration are exempt: the table is
    // empty at index time, so there's no lock contention. (And CONCURRENTLY
    // can't run inside the transaction wrapping a CREATE TABLE anyway.)
    if (preg_match_all(
        '/\bCREATE\s+(?:UNIQUE\s+)?INDEX\b(\s+CONCURRENTLY\b)?(?:\s+IF\s+NOT\s+EXISTS)?\s+\S+\s+ON\s+(?:ONLY\s+)?([\w.]+)/i',
        $content,
        $idxMatches,
        PREG_SET_ORDER,
    )) {
        foreach ($idxMatches as $match) {
            $hasConcurrently = ($match[1] ?? '') !== '';
            if ($hasConcurrently) {
                continue;
            }

            $table = $match[2];
            $createdInSameFile = preg_match(
                '/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?'.preg_quote($table, '/').'\b/i',
                $content,
            ) === 1;

            if ($createdInSameFile) {
                continue;
            }

            // Also exempt indexes on a column that was ADD COLUMN-ed in this same migration.
            // New columns are always empty at index time — no lock contention possible.
            // Extract the indexed column from the index definition (first word after the table).
            $idxBody = substr($raw, strpos($raw, $match[0]));
            if (preg_match('/ON\s+[\w.]+\s*\(([^)]+)\)/i', $idxBody, $colMatch)) {
                $indexedCol = trim(explode(',', $colMatch[1])[0]);
                $indexedCol = preg_replace('/\s+.*/', '', $indexedCol); // strip ASC/DESC
                $columnAddedInSameFile = preg_match(
                    '/\bADD\s+COLUMN\s+(?:IF\s+NOT\s+EXISTS\s+)?'.preg_quote($indexedCol, '/').'\b/i',
                    $content,
                ) === 1;
                if ($columnAddedInSameFile) {
                    continue;
                }
            }

            $errors[] = "$basename: CREATE INDEX without CONCURRENTLY detected on `$table`.\n"
                ."  Use: CREATE INDEX CONCURRENTLY IF NOT EXISTS ... (outside any transaction block)\n"
                .'  See: supabase/migrations/CONVENTIONS.md §1';
            break;
        }
    }

    // ── Check 2: ADD CONSTRAINT FOREIGN KEY without NOT VALID ─────────────────
    // Match each FK clause individually, stopping at the next ADD CONSTRAINT or semicolon.
    // This prevents a later NOT VALID on a second FK from masking an earlier unsafe one.
    if (preg_match_all(
        '/ADD\s+CONSTRAINT\s+\S+\s+FOREIGN\s+KEY.*?(?=,\s*ADD\s+CONSTRAINT\b|;|\z)/is',
        $content,
        $fkMatches
    )) {
        foreach ($fkMatches[0] as $stmt) {
            if (! preg_match('/\bNOT\s+VALID\b/i', $stmt)) {
                $errors[] = "$basename: ADD CONSTRAINT FOREIGN KEY without NOT VALID detected.\n"
                    ."  Use: ADD CONSTRAINT <name> FOREIGN KEY (...) REFERENCES ... NOT VALID\n"
                    ."  Then: VALIDATE CONSTRAINT <name> in a separate transaction.\n"
                    .'  See: supabase/migrations/CONVENTIONS.md §4';
                break; // one error per file is enough
            }
        }
    }

    // ── Check 3: ADD CONSTRAINT CHECK without NOT VALID ───────────────────────
    // Check constraints on populated tables need NOT VALID to avoid ACCESS EXCLUSIVE.
    if (preg_match_all(
        '/ADD\s+CONSTRAINT\s+\S+\s+CHECK\s*\(.*?(?=,\s*ADD\s+CONSTRAINT\b|;|\z)/is',
        $content,
        $checkMatches
    )) {
        foreach ($checkMatches[0] as $stmt) {
            if (! preg_match('/\bNOT\s+VALID\b/i', $stmt)) {
                $errors[] = "$basename: ADD CONSTRAINT CHECK without NOT VALID detected.\n"
                    ."  Use: ADD CONSTRAINT <name> CHECK (...) NOT VALID\n"
                    ."  Then: VALIDATE CONSTRAINT <name> in a separate transaction.\n"
                    .'  See: supabase/migrations/CONVENTIONS.md §2';
                break;
            }
        }
    }

    // ── Check 4: ALTER COLUMN SET NOT NULL ────────────────────────────────────
    // Direct SET NOT NULL takes ACCESS EXCLUSIVE and scans every row under the lock.
    // Use the four-step NOT VALID + VALIDATE CONSTRAINT + SET NOT NULL pattern instead.
    //
    // Exemption: if the same file also contains VALIDATE CONSTRAINT, the
    // SET NOT NULL is Step 4 of the documented four-step pattern. Postgres
    // skips the row scan in that case because the validated CHECK already
    // guarantees no NULLs.
    if (preg_match('/\bALTER\s+COLUMN\s+\S+\s+SET\s+NOT\s+NULL\b/i', $content)) {
        $hasValidate = preg_match('/\bVALIDATE\s+CONSTRAINT\b/i', $content) === 1;
        if (! $hasValidate) {
            $errors[] = "$basename: ALTER COLUMN SET NOT NULL detected.\n"
                ."  Use the four-step pattern: ADD CONSTRAINT ... NOT VALID → backfill → VALIDATE → SET NOT NULL.\n"
                .'  See: supabase/migrations/CONVENTIONS.md §3';
        }
    }

    // ── Check 5: hot-table DDL/DML without a lock/statement timeout ───────────
    // A migration that can't get its lock should abort in 2s with a clear error,
    // not queue behind live traffic and stall the whole deploy. SET LOCAL is
    // transaction-scoped, so the file also needs explicit BEGIN/COMMIT -- without
    // it the SET LOCAL is a silent no-op.
    if ($m[1] > TIMEOUT_GUARD_CUTOFF) {
        foreach (HOT_TABLES as $hotTable) {
            $touches = preg_match(
                '/\b(?:ALTER\s+TABLE|UPDATE)\s+(?:ONLY\s+)?'.preg_quote($hotTable, '/').'\b/i',
                $content,
            ) === 1;

            if (! $touches) {
                continue;
            }

            $hasTimeout = preg_match('/\bSET\s+LOCAL\s+lock_timeout\b/i', $content) === 1;
            $hasTxn = preg_match('/^\s*BEGIN\s*;/im', $content) === 1;

            if ($hasTimeout && $hasTxn) {
                break;
            }

            $errors[] = "$basename: DDL/DML on live-traffic table `$hotTable` without a lock timeout.\n"
                ."  Wrap the statements and set the timeouts inside the transaction:\n"
                ."    BEGIN;\n"
                ."    SET LOCAL lock_timeout      = '2s';\n"
                ."    SET LOCAL statement_timeout = '10s';\n"
                ."    ...\n"
                ."    COMMIT;\n"
                .'  See: docs/migration-guidelines.md §Lock and statement timeouts';
            break;
        }
    }

    // ── Check 6: a CONCURRENTLY statement must be ALONE in its file (pipeline/25001) ─
    // `supabase db reset` / `db push` send a file's statements to Postgres as one libpq
    // pipeline (implicit transaction) whenever the file has more than one statement — of
    // ANY kind. CREATE/DROP/REINDEX ... CONCURRENTLY cannot run in a pipeline/transaction
    // (SQLSTATE 25001), so a from-zero apply aborts on any file that pairs a CONCURRENTLY
    // statement with anything else (another CONCURRENTLY, other DDL/DML, or BEGIN/COMMIT).
    // The safe shape is one file, one CONCURRENTLY statement, nothing else. Comments are
    // already stripped from $content, so CONCURRENTLY mentioned in a comment does not count.
    if ($m[1] > CONCURRENTLY_GUARD_CUTOFF) {
        $concurrentlyCount = preg_match_all('/\bCONCURRENTLY\b/i', $content);
        if ($concurrentlyCount >= 1) {
            // Count statements by ';'. A lone CONCURRENTLY file has exactly one. This is a
            // heuristic: a ';' inside a string literal or a /* block comment */ (the shared
            // stripper only removes -- line comments) could over-count and flag a compliant
            // file. It fails CLOSED — it never lets an unsafe file through — and no current
            // file trips it; use the -- guard:no-unsafe-migrations:disable-file marker if it
            // ever mis-fires on a genuinely lone CONCURRENTLY statement.
            $statementCount = preg_match_all('/;/', $content);
            if ($statementCount > 1) {
                $errors[] = "$basename: a CONCURRENTLY statement is not alone in its file "
                    ."($concurrentlyCount CONCURRENTLY, ~$statementCount statements).\n"
                    ."  The CLI pipelines any multi-statement file into one transaction; CONCURRENTLY\n"
                    ."  cannot run in a pipeline (SQLSTATE 25001), so a from-zero db reset/db push aborts.\n"
                    ."  Put each CREATE/DROP INDEX CONCURRENTLY in its OWN file — one statement, no other\n"
                    ."  DDL/DML, no BEGIN/COMMIT (use sequential timestamp suffixes for a batch).\n"
                    .'  See: supabase/migrations/CONVENTIONS.md §1 and scripts/db/fresh-reset.sh';
            }
        }
    }
}

if (! empty($errors)) {
    fwrite(STDERR, "\nMigration safety lint FAILED — unsafe locking pattern(s) detected:\n\n");
    foreach ($errors as $e) {
        fwrite(STDERR, "  ✗ $e\n\n");
    }
    fwrite(STDERR, "These patterns cause write downtime on populated tables.\n");
    fwrite(STDERR, "See supabase/migrations/CONVENTIONS.md for the safe alternatives.\n\n");
    exit(1);
}

echo "Migration safety lint passed.\n";
