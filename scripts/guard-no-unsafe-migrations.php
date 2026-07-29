<?php

/**
 * Guard: no unsafe migration patterns (Master Pattern 20).
 *
 * Fails on nine patterns that cause lock-induced downtime (or a failed from-zero apply):
 *   1. CREATE INDEX without CONCURRENTLY
 *   2. ADD CONSTRAINT ... FOREIGN KEY without NOT VALID
 *   3. ADD CONSTRAINT ... CHECK without NOT VALID
 *   4. ALTER COLUMN ... SET NOT NULL (must use the four-step NOT VALID pattern)
 *   5. DDL/DML on a hot table (HOT_TABLES) without a BEGIN + SET LOCAL lock_timeout
 *   6. more than one statement in a file that contains a CONCURRENTLY statement (pipeline/25001)
 *   7. DROP INDEX without CONCURRENTLY on a HOT_TABLES table
 *   8. VALIDATE CONSTRAINT in the same transaction as its ADD CONSTRAINT ... NOT VALID
 *   9. ADD COLUMN ... GENERATED ... STORED on a pre-existing table without a bounded transaction
 *
 * Migrations with timestamps <= GRANDFATHERED_CUTOFF are skipped — they ran safely on
 * empty tables before this convention was established (2026-05-14, timestamp 20260514100000).
 * All new migrations after that date are subject to Checks 1-4.
 *
 * Check 5 has its own, later boundary (TIMEOUT_GUARD_CUTOFF) since it shipped well
 * after GRANDFATHERED_CUTOFF — see the constant's comment for why.
 *
 * Every table/index identifier in this repo's migrations is double-quoted
 * ("site"."platform_connections"). Checks 1, 5 and 7 match identifiers with
 * [\w.]+ / a preg_quote'd literal, neither of which can cross a `"` — before
 * the quote-normalisation pass below, those three checks were structurally
 * blind to every file in the tree (audit Unit H / #MIG-3: a file with four
 * plain CREATE INDEX statements and three plain DROP INDEX statements produced
 * zero matches on either check).
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
// its own boundary: 11 pre-existing files pair a CONCURRENTLY statement with other
// statements (9 bundle several CONCURRENTLY; 2 pair one CONCURRENTLY with other DDL)
// and were applied to dev incrementally via non-pipelined paths. Splitting
// them retroactively would open migration-history gaps on the live dev DB, so they are
// grandfathered here; only NEW files must keep one CONCURRENTLY per file. At this cutoff
// the check flags 0 files on a clean tree.
const CONCURRENTLY_GUARD_CUTOFF = '20260721000000';

// Check 7 (DROP INDEX non-CONCURRENTLY on a hot table) shipped 2026-07-22. Like
// Checks 5 and 6 it needs its own boundary: one pre-existing file
// (20260701180000_strip_block_settings_keys_and_views.sql) drops a hot-table index
// inside a transaction and is already applied to dev, so it is grandfathered here.
// (The two core.users drops in 20260718200000_pre_account_sites.sql are already
// covered by that file's -- guard:no-unsafe-migrations:disable-file marker.) At this
// cutoff the check flags 0 files on a clean tree; only NEW files are subject to it.
const DROP_INDEX_GUARD_CUTOFF = '20260722000000';

// Check 8 (VALIDATE CONSTRAINT bundled in the same transaction as its ADD ... NOT
// VALID) shipped 2026-07-22. Its own boundary grandfathers the pre-existing bundled
// files (e.g. 20260701220000, 20260624010000) — harmless because the prod cutover
// re-applies against an empty, traffic-free DB. At this cutoff the check flags 0
// files on a clean tree; only NEW files must defer VALIDATE to a separate transaction.
const VALIDATE_TXN_GUARD_CUTOFF = '20260722000000';

// Check 9 (ADD COLUMN ... GENERATED ... STORED) shipped 2026-07-29. Its own
// boundary starts just after the collapsed baseline: the baseline contains no
// GENERATED ALWAYS AS at all (verified), and the only file in the tree using
// the pattern is 20260727110004, which satisfies the BEGIN + lock_timeout
// requirement below. At this cutoff the check flags 0 files on a clean tree.
const GENERATED_STORED_GUARD_CUTOFF = '20260726000000';

// Tables served by live traffic. The first three are named in
// docs/migration-guidelines.md §Lock and statement timeouts; core.users is added
// because it is read on every authenticated request.
const HOT_TABLES = ['site.design_kits', 'site.sites', 'site.blocks', 'core.users'];

const MIGRATIONS_DIR = 'supabase/migrations';

/**
 * Extract the contiguous run of comment-only (and blank) lines starting at a
 * disable-file marker, stopping at the first line that is neither blank, a
 * `--` line comment, nor inside an unterminated `/* ... *\/` block comment.
 *
 * Used by the G3 disable-file justification check so a keyword match inside
 * real SQL (a CHECK constraint, a string literal) can never satisfy it — only
 * prose in the marker's own comment header counts. A fixed character window
 * over raw SQL (the prior implementation) let `'no reason given'` inside a
 * CHECK constraint's string literal satisfy the check; anchoring to the
 * comment prefix closes that hole regardless of how long the header prose runs.
 */
function commentPrefixAfter(string $raw, int $offset): string
{
    $lines = explode("\n", substr($raw, $offset));
    $prefix = [];
    $inBlockComment = false;

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($inBlockComment) {
            $prefix[] = $line;
            if (str_contains($line, '*/')) {
                $inBlockComment = false;
            }

            continue;
        }

        if ($trimmed === '' || str_starts_with($trimmed, '--')) {
            $prefix[] = $line;

            continue;
        }

        if (str_starts_with($trimmed, '/*')) {
            $prefix[] = $line;
            if (! str_contains($line, '*/')) {
                $inBlockComment = true;
            }

            continue;
        }

        break;
    }

    return implode("\n", $prefix);
}

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
    //
    // G3 (audit Unit H): the marker must be followed by prose explaining WHY,
    // anchored to the CONTIGUOUS comment-only prefix that follows it (`--`
    // comment lines / blank lines / `/* ... */` block comments), stopping at
    // the first real SQL line — not a fixed character window over raw SQL,
    // which let an incidental keyword hit inside a CHECK constraint or string
    // literal (e.g. `'no reason given'`) satisfy the check (audit Unit H
    // adversarial repro). A blanket opt-out with no stated reason is
    // indistinguishable from an opt-out nobody thought about. "reason\w*"
    // (not "reason\b") also catches "reasoning".
    if (preg_match('/--\s*guard:no-unsafe-migrations:disable-file\b/im', $raw, $dm, PREG_OFFSET_CAPTURE)) {
        $prefix = commentPrefixAfter($raw, $dm[0][1]);
        if (! preg_match('/\b(justification|because|why|reason\w*|rationale)\b/i', $prefix)) {
            $errors[] = "$basename: guard:no-unsafe-migrations:disable-file with no written justification.\n"
                .'  Follow the marker with a dated justification comment explaining what deviates and why.';
        }

        continue;
    }

    // Strip single-line SQL comments so patterns inside comments don't false-positive.
    $content = preg_replace('/--[^\n]*/', '', $raw);

    // G1 (audit Unit H / #MIG-3): normalise quoted identifiers before the
    // identifier-matching checks. Every migration in this repo writes
    // "site"."platform_connections", but Checks 1, 5 and 7 match table/index
    // names with [\w.]+ or a preg_quote'd literal, neither of which can cross a
    // double quote. Before this normalisation those three checks were dead
    // against every file in the tree: a file with four plain CREATE INDEX
    // statements and three plain DROP INDEX statements produced zero matches on
    // either check. This strips the quotes (not the identifiers), so
    // "site"."platform_connections" becomes site.platform_connections for
    // matching purposes only — $raw (unquoted-preserving) is still used for the
    // disable-file probe above.
    $content = preg_replace('/"([A-Za-z_][A-Za-z0-9_$]*)"/', '$1', $content);

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
            // G1: slice from $content (quote-normalised), not $raw — $match[0] was matched
            // against $content, so its text (and any offset within it) no longer exists
            // verbatim in $raw once identifiers are quoted.
            $idxBody = substr($content, strpos($content, $match[0]));
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

    // ── Check 7: DROP INDEX (non-CONCURRENTLY) on a HOT_TABLES table ───────────
    // A bare DROP INDEX takes ACCESS EXCLUSIVE on the index's table for the catalog
    // write, blocking every writer until it completes -- on a hot table it queues
    // behind, and stalls, live traffic. DROP INDEX names the index, not the table,
    // so the target table is inferred from the index name: flag a non-CONCURRENTLY
    // drop whose (schema-stripped) index name contains a hot table's bare name as an
    // underscore/anchor-delimited token. Heuristic in both directions: an index whose
    // name omits the table name slips through (false negative), and one whose name
    // collides with a hot table's token (e.g. user_sites_summary_idx matching `sites`)
    // is flagged spuriously (false positive). It fails toward the safe pattern; use
    // CONCURRENTLY, or the -- guard:no-unsafe-migrations:disable-file marker, to opt out.
    if ($m[1] > DROP_INDEX_GUARD_CUTOFF) {
        if (preg_match_all(
            '/\bDROP\s+INDEX\b(\s+CONCURRENTLY\b)?(?:\s+IF\s+EXISTS\b)?\s+([\w.]+)/i',
            $content,
            $dropMatches,
            PREG_SET_ORDER,
        )) {
            foreach ($dropMatches as $match) {
                $hasConcurrently = ($match[1] ?? '') !== '';
                if ($hasConcurrently) {
                    continue;
                }

                // Strip an optional schema qualifier: site.idx_foo -> idx_foo.
                $idxName = $match[2];
                if (($dot = strrpos($idxName, '.')) !== false) {
                    $idxName = substr($idxName, $dot + 1);
                }

                foreach (HOT_TABLES as $hotTable) {
                    // Bare table name (strip schema): site.blocks -> blocks.
                    $bare = $hotTable;
                    if (($dotB = strrpos($bare, '.')) !== false) {
                        $bare = substr($bare, $dotB + 1);
                    }

                    // \b is useless in snake_case (_blocks_ has no boundary), so match
                    // the bare name delimited by underscores or the string ends.
                    if (preg_match('/(^|_)'.preg_quote($bare, '/').'(_|$)/i', $idxName)) {
                        $errors[] = "$basename: DROP INDEX without CONCURRENTLY on hot table `$hotTable` (index `{$match[2]}`).\n"
                            ."  Use: DROP INDEX CONCURRENTLY IF EXISTS ... (in its OWN file, outside any transaction)\n"
                            ."  A bare DROP INDEX takes ACCESS EXCLUSIVE and blocks writes for its duration.\n"
                            .'  See: supabase/migrations/CONVENTIONS.md §1';
                        break 2; // one error per file is enough
                    }
                }
            }
        }
    }

    // ── Check 8: VALIDATE CONSTRAINT in the same txn as its ADD ... NOT VALID ──
    // NOT VALID takes a brief metadata lock and releases it; VALIDATE then re-scans
    // under the lighter SHARE UPDATE EXCLUSIVE -- but ONLY if it runs in a separate
    // transaction. Bundled in one BEGIN/COMMIT, the txn holds the ADD's heavier lock
    // through the whole VALIDATE scan, defeating the split (audit migrations-recent/
    // MIG-5, migrations-early/MIG-6). Check 3 only verifies NOT VALID is present, not
    // that VALIDATE is deferred. A file that legitimately contains both (the 4-step
    // SET NOT NULL pattern, or two BEGIN/COMMIT blocks in one file) puts a COMMIT
    // between the ADD ... NOT VALID and the VALIDATE -- which is exactly what we check.
    if ($m[1] > VALIDATE_TXN_GUARD_CUTOFF) {
        if (preg_match_all('/\bVALIDATE\s+CONSTRAINT\b/i', $content, $vMatches, PREG_OFFSET_CAPTURE)) {
            // Byte offset of every `ADD CONSTRAINT ... NOT VALID` (NOT VALID must fall
            // within the same statement -- [^;] can't cross a statement terminator).
            $addPositions = [];
            if (preg_match_all('/\bADD\s+CONSTRAINT\b[^;]*?\bNOT\s+VALID\b/i', $content, $addMatches, PREG_OFFSET_CAPTURE)) {
                foreach ($addMatches[0] as $am) {
                    $addPositions[] = $am[1];
                }
            }

            foreach ($vMatches[0] as $vm) {
                $validatePos = $vm[1];

                // The nearest ADD ... NOT VALID that appears before this VALIDATE.
                $addBefore = -1;
                foreach ($addPositions as $ap) {
                    if ($ap < $validatePos) {
                        $addBefore = $ap;
                    }
                }

                // A VALIDATE with no preceding ADD ... NOT VALID in this file is the
                // correct two-file split (the ADD lives in an earlier migration).
                if ($addBefore === -1) {
                    continue;
                }

                $between = substr($content, $addBefore, $validatePos - $addBefore);
                if (! preg_match('/\bCOMMIT\s*;/i', $between)) {
                    $errors[] = "$basename: VALIDATE CONSTRAINT runs in the same transaction as its ADD CONSTRAINT ... NOT VALID.\n"
                        ."  Put VALIDATE CONSTRAINT in a SEPARATE transaction (COMMIT the ADD ... NOT VALID first),\n"
                        ."  so the validation scan runs under SHARE UPDATE EXCLUSIVE, not the ADD's heavier lock.\n"
                        .'  See: supabase/migrations/CONVENTIONS.md §2';
                    break; // one error per file is enough
                }
            }
        }
    }

    // ── Check 9: ADD COLUMN ... GENERATED ... STORED without a bounded transaction ─
    // A STORED generated column must be materialised into every existing tuple, so
    // ADD COLUMN rewrites the whole heap while holding ACCESS EXCLUSIVE. There is no
    // online variant. Two things make it survivable: put it in its OWN file so the
    // lock window covers nothing else, and BOUND it so a blocked lock aborts fast.
    // Exempt when the table is created in the same file (nothing to rewrite).
    if ($m[1] > GENERATED_STORED_GUARD_CUTOFF) {
        if (preg_match_all(
            '/\bADD\s+COLUMN\b(?:\s+IF\s+NOT\s+EXISTS)?[^;]*?\bGENERATED\b[^;]*?\bSTORED\b/is',
            $content,
            $genMatches,
            PREG_OFFSET_CAPTURE,
        )) {
            foreach ($genMatches[0] as $gm) {
                // Which table? Walk back to the nearest ALTER TABLE before this ADD COLUMN.
                $before = substr($content, 0, $gm[1]);
                $table = null;
                if (preg_match_all('/\bALTER\s+TABLE\s+(?:ONLY\s+)?([\w.]+)/i', $before, $atMatches)) {
                    $table = end($atMatches[1]);
                }

                if ($table !== null && preg_match(
                    '/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?'.preg_quote($table, '/').'\b/i',
                    $content,
                ) === 1) {
                    continue; // table created in this file — empty, no rewrite cost
                }

                $hasTimeout = preg_match('/\bSET\s+LOCAL\s+lock_timeout\b/i', $content) === 1;
                $hasTxn = preg_match('/^\s*BEGIN\s*;/im', $content) === 1;
                if ($hasTimeout && $hasTxn) {
                    continue;
                }

                $errors[] = "$basename: ADD COLUMN ... GENERATED ... STORED on pre-existing table `".($table ?? '?')."`.\n"
                    ."  A STORED generated column rewrites the entire heap under ACCESS EXCLUSIVE.\n"
                    ."  Put it in its OWN migration file and bound the lock:\n"
                    ."    BEGIN;\n"
                    ."    SET LOCAL lock_timeout      = '2s';\n"
                    ."    SET LOCAL statement_timeout = '60s';\n"
                    ."    ALTER TABLE ... ADD COLUMN ... GENERATED ALWAYS AS (...) STORED;\n"
                    ."    COMMIT;\n"
                    .'  See: supabase/migrations/CONVENTIONS.md §8';
                break;
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
