<?php

/**
 * Guard: no unsafe migration patterns (Master Pattern 20).
 *
 * Fails on four patterns that cause lock-induced downtime on populated tables:
 *   1. CREATE INDEX without CONCURRENTLY
 *   2. ADD CONSTRAINT ... FOREIGN KEY without NOT VALID
 *   3. ADD CONSTRAINT ... CHECK without NOT VALID
 *   4. ALTER COLUMN ... SET NOT NULL (must use the four-step NOT VALID pattern)
 *
 * Migrations with timestamps <= GRANDFATHERED_CUTOFF are skipped — they ran safely on
 * empty tables before this convention was established (2026-05-14, timestamp 20260514100000).
 * All new migrations after that date are subject to this lint.
 *
 * See supabase/migrations/CONVENTIONS.md for the safe alternatives.
 */
const GRANDFATHERED_CUTOFF = '20260514100000';
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
