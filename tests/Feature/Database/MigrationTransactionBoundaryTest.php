<?php

// Regression lock for audit MIG-1/MIG-2/MIG-3/MIG-4 (Gate A bundle B2):
// several already-applied migration files ran a DROP/ADD CONSTRAINT +
// VALIDATE CONSTRAINT dance (or a backfill + DROP COLUMN) inside ONE
// implicit transaction, so the ACCESS EXCLUSIVE taken by the catalog write
// was held through the whole validation/backfill scan -- defeating the
// NOT VALID optimisation the files' own comments claimed to use.
//
// Deliberately NO Postgres guard, matching ConstraintVocabularyLockstepTest:
// this test reads the .sql files as plain text and regexes their contents,
// so it genuinely runs on SQLite in every CI pass rather than skipping.
// A test that only runs against Postgres verifies nothing in this repo's CI.

/**
 * Read a migration file's contents by basename, from supabase/migrations/ or the
 * archive. The 2026-07-26 baseline collapse moved every incremental into
 * supabase/migrations-archive/; these boundary assertions still guard those files
 * (now frozen history) against edits that would break a replay.
 */
function migrationBoundaryReadSql(string $basename): string
{
    foreach (['supabase/migrations/', 'supabase/migrations-archive/'] as $dir) {
        $path = base_path($dir.$basename);
        if (file_exists($path)) {
            return (string) file_get_contents($path);
        }
    }

    expect(false)->toBeTrue("Expected migration file [$basename] in supabase/migrations/ or supabase/migrations-archive/.");

    return '';
}

/**
 * Strip single-line SQL comments so prose describing old/new patterns (e.g.
 * a rollback block, or a comment quoting the predicate being replaced)
 * can't false-positive against the structural regexes below. Mirrors
 * scripts/guard-no-unsafe-migrations.php's own comment-stripping step.
 */
function migrationBoundaryStripComments(string $sql): string
{
    return preg_replace('/--[^\n]*/', '', $sql);
}

/**
 * Whether a `COMMIT;` appears anywhere before the first `VALIDATE CONSTRAINT`
 * statement -- i.e. the catalog-write window was closed before validation
 * started, rather than both running in one transaction. Case-insensitive so
 * it holds for the lowercase-styled 20260710170000 file too.
 */
function migrationBoundaryCommitPrecedesValidate(string $sql): bool
{
    $sql = migrationBoundaryStripComments($sql);

    if (! preg_match('/VALIDATE\s+CONSTRAINT/i', $sql, $m, PREG_OFFSET_CAPTURE)) {
        return false;
    }

    $before = substr($sql, 0, $m[0][1]);

    return preg_match('/COMMIT\s*;/i', $before) === 1;
}

/**
 * Net open-transaction depth immediately before byte offset $pos: count of
 * `BEGIN;` minus count of `COMMIT;` seen so far. Postgres migrations in this
 * repo never nest BEGIN/COMMIT, so 0 means "not currently inside an explicit
 * transaction block" at that point in the file -- used to assert a bare
 * statement (no wrapping BEGIN/COMMIT) rather than matching exact adjacency,
 * which would be brittle to comment/whitespace placement.
 */
function migrationBoundaryOpenDepthBefore(string $sql, int $pos): int
{
    $before = substr($sql, 0, $pos);
    $begins = preg_match_all('/\bBEGIN\s*;/i', $before);
    $commits = preg_match_all('/\bCOMMIT\s*;/i', $before);

    return $begins - $commits;
}

// ─── MIG-3: DROP/ADD CONSTRAINT NOT VALID must COMMIT before VALIDATE ───────

$mig3Files = [
    '20260708140000_add_atlas_multipage_skeleton.sql',
    '20260708160000_remove_thread_sheet_skeletons.sql',
    '20260709041138_add_one_skeleton_id.sql',
    '20260710170000_skeleton_id_one_only.sql',
    '20260711000000_staff_account_type.sql',
];

foreach ($mig3Files as $file) {
    it("commits the constraint swap before VALIDATE CONSTRAINT runs in {$file}", function () use ($file) {
        $sql = migrationBoundaryReadSql($file);

        expect(migrationBoundaryCommitPrecedesValidate($sql))->toBeTrue(
            "Expected a COMMIT; before VALIDATE CONSTRAINT in [$file] -- ".
            'the ACCESS EXCLUSIVE lock from the constraint swap must be released before validation scans the table.'
        );
    });
}

// ─── MIG-4: promote_site_settings_columns split into separate windows ──────

it('runs the settings backfill UPDATE as a bare statement outside any BEGIN/COMMIT window', function () {
    $sql = migrationBoundaryStripComments(migrationBoundaryReadSql('20260701190000_promote_site_settings_columns.sql'));

    expect(preg_match('/UPDATE\s+site\.sites\s+SET/i', $sql, $m, PREG_OFFSET_CAPTURE))->toBe(1);

    $depth = migrationBoundaryOpenDepthBefore($sql, $m[0][1]);

    expect($depth)->toBe(0, 'Expected the backfill UPDATE to sit outside any open BEGIN/COMMIT window (depth 0), '.
        "got depth {$depth} -- it should not inherit the ACCESS EXCLUSIVE lock from the ADD COLUMN window.");
});

it('guards the settings backfill with a key-presence predicate, not the tautological IS NOT NULL', function () {
    // Comments are NOT stripped here -- this checks the actual executable
    // WHERE clause of the live UPDATE statement, not just prose about it.
    $sql = migrationBoundaryReadSql('20260701190000_promote_site_settings_columns.sql');
    $update = substr($sql, (int) strpos($sql, 'UPDATE site.sites SET'));

    expect($update)->toContain('settings ?|')
        ->and($update)->not->toMatch('/WHERE\s+settings\s+IS\s+NOT\s+NULL/i');
});

// Window 1/3 retry-safety: each window below commits independently, so a
// failure in a later window (lock/statement timeout) leaves earlier windows'
// COMMITs standing -- the next `db push` replays the WHOLE FILE from Window 1.
// Every window's DDL must therefore be individually idempotent, not just the
// file as a whole transactionally safe.

it('adds every column in Window 1 of 20260701190000 with IF NOT EXISTS, so a replay does not fail on "column already exists"', function () {
    $sql = migrationBoundaryStripComments(migrationBoundaryReadSql('20260701190000_promote_site_settings_columns.sql'));

    $addColumnTotal = preg_match_all('/\bADD\s+COLUMN\b/i', $sql);
    $addColumnGuarded = preg_match_all('/\bADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\b/i', $sql);

    expect($addColumnTotal)->toBe(10, "Expected 10 ADD COLUMN clauses in Window 1, found {$addColumnTotal}.")
        ->and($addColumnGuarded)->toBe($addColumnTotal,
            "Expected every ADD COLUMN clause to carry IF NOT EXISTS ({$addColumnGuarded}/{$addColumnTotal} guarded) -- ".
            'an unguarded ADD COLUMN fails with "column already exists" when a later window\'s timeout forces a replay from Window 1.');
});

it('drops the stale booking_mode CHECK before re-adding it in Window 3 of 20260701190000', function () {
    $sql = migrationBoundaryStripComments(migrationBoundaryReadSql('20260701190000_promote_site_settings_columns.sql'));

    expect(preg_match('/DROP\s+CONSTRAINT\s+IF\s+EXISTS\s+sites_booking_mode_check\b/i', $sql, $dropMatch, PREG_OFFSET_CAPTURE))->toBe(1);
    expect(preg_match('/ADD\s+CONSTRAINT\s+sites_booking_mode_check\b/i', $sql, $addMatch, PREG_OFFSET_CAPTURE))->toBe(1);

    expect($dropMatch[0][1])->toBeLessThan($addMatch[0][1],
        'Expected DROP CONSTRAINT IF EXISTS sites_booking_mode_check before ADD CONSTRAINT sites_booking_mode_check -- '.
        "a replay after a Window 4 timeout must clear a prior attempt's leftover constraint before re-adding it NOT VALID.");
});

// ─── MIG-1/MIG-2: single-window atomicity (CREATE TABLE + backfill + DROP COLUMN) ─

$mig1Mig2Files = [
    '20260701140100_menu_item_platforms_table.sql',
    '20260701140000_menu_platform_links.sql',
];

foreach ($mig1Mig2Files as $file) {
    it("wraps the whole create-backfill-drop sequence in exactly one transaction in {$file}", function () use ($file) {
        $sql = migrationBoundaryStripComments(migrationBoundaryReadSql($file));

        $begins = preg_match_all('/\bBEGIN\s*;/i', $sql);
        $commits = preg_match_all('/\bCOMMIT\s*;/i', $sql);

        expect($begins)->toBe(1, "Expected exactly one BEGIN; in [$file], found {$begins}.")
            ->and($commits)->toBe(1, "Expected exactly one COMMIT; in [$file], found {$commits}.");
    });
}

// ─── Lock/statement timeout guards present on every touched file ───────────

$timeoutGuardedFiles = [
    ...$mig3Files,
    ...$mig1Mig2Files,
    '20260701190000_promote_site_settings_columns.sql',
    '20260707030000_rename_skeleton_ids.sql',
    '20260707120000_rename_skeleton_ids_bento_class.sql',
];

foreach ($timeoutGuardedFiles as $file) {
    it("sets a lock_timeout guard in {$file}", function () use ($file) {
        $sql = migrationBoundaryStripComments(migrationBoundaryReadSql($file));

        expect($sql)->toMatch('/SET\s+LOCAL\s+lock_timeout/i');
    });
}

// ─── MIG-3's non-negotiable: DROP CONSTRAINT must not be split from the remap UPDATE ─

$mig3UpdateBeforeDropFiles = [
    '20260707030000_rename_skeleton_ids.sql',
    '20260707120000_rename_skeleton_ids_bento_class.sql',
];

foreach ($mig3UpdateBeforeDropFiles as $file) {
    it("keeps the value-remap UPDATE inside the same window as DROP CONSTRAINT in {$file}", function () use ($file) {
        $sql = migrationBoundaryStripComments(migrationBoundaryReadSql($file));

        expect(preg_match('/DROP\s+CONSTRAINT/i', $sql, $dropMatch, PREG_OFFSET_CAPTURE))->toBe(1);
        expect(preg_match('/\bUPDATE\s+site\.sites\b/i', $sql, $updateMatch, PREG_OFFSET_CAPTURE))->toBe(1);

        // DROP CONSTRAINT must come first -- the UPDATE below writes values
        // the OLD CHECK forbids -- and both must sit inside the same open
        // transaction (no COMMIT between them).
        expect($dropMatch[0][1])->toBeLessThan($updateMatch[0][1]);

        $between = substr($sql, $dropMatch[0][1], $updateMatch[0][1] - $dropMatch[0][1]);
        expect($between)->not->toMatch('/COMMIT\s*;/i');
    });
}

// ─── MIG-1/MIG-2 (Gate A): DROP INDEX must use CONCURRENTLY, or not run at all ─
//
// site.site_media and site.enquiries are both live-traffic tables. A bare
// DROP INDEX takes ACCESS EXCLUSIVE for the duration of the catalog write,
// blocking every writer until it completes -- the same class of risk
// CONCURRENTLY already protects the CREATE INDEX side against in these files.

$dropIndexMustBeConcurrentFiles = [
    '20260527000000_fix_sort_order_unique_constraints.sql',
    '20260527160001_enquiry_inbox_indexes.sql',
];

foreach ($dropIndexMustBeConcurrentFiles as $file) {
    it("only drops indexes CONCURRENTLY in {$file}", function () use ($file) {
        $sql = migrationBoundaryStripComments(migrationBoundaryReadSql($file));

        // Every DROP INDEX must be immediately followed by CONCURRENTLY --
        // a bare match here (not consumed by the CONCURRENTLY-qualified
        // pattern) means an unsafe drop slipped back in.
        $bareDrops = preg_match_all('/\bDROP\s+INDEX\s+(?!CONCURRENTLY\b)/i', $sql);

        expect($bareDrops)->toBe(0,
            "Expected every DROP INDEX in [$file] to be DROP INDEX CONCURRENTLY, ".
            "found {$bareDrops} bare DROP INDEX statement(s) -- a bare drop takes ".
            'ACCESS EXCLUSIVE and blocks writes for its duration.'
        );
    });
}

it('never drops an index in 20260527160000_enquiry_inbox.sql -- that responsibility moved to the sibling CONCURRENTLY file', function () {
    $sql = migrationBoundaryStripComments(migrationBoundaryReadSql('20260527160000_enquiry_inbox.sql'));

    expect($sql)->not->toMatch('/\bDROP\s+INDEX\b/i',
        'This file runs inside BEGIN/COMMIT, so DROP INDEX CONCURRENTLY is not even legal here -- '.
        'the drop of enquiries_user_created_idx belongs in 20260527160001_enquiry_inbox_indexes.sql.'
    );
});

it('drops enquiries_user_created_idx AFTER its replacement index is built in 20260527160001_enquiry_inbox_indexes.sql', function () {
    $sql = migrationBoundaryStripComments(migrationBoundaryReadSql('20260527160001_enquiry_inbox_indexes.sql'));

    expect(preg_match_all('/\bCREATE\s+INDEX\s+CONCURRENTLY\b/i', $sql, $createMatches, PREG_OFFSET_CAPTURE))->toBe(3);
    expect(preg_match('/\bDROP\s+INDEX\s+CONCURRENTLY\b/i', $sql, $dropMatch, PREG_OFFSET_CAPTURE))->toBe(1);

    $lastCreateOffset = end($createMatches[0])[1];

    // Deliberately AFTER the last CREATE, not before: dropping first would
    // open a window with neither the old nor the new index in place.
    expect($dropMatch[0][1])->toBeGreaterThan($lastCreateOffset,
        'Expected DROP INDEX CONCURRENTLY for enquiries_user_created_idx to come after all three '.
        'CREATE INDEX CONCURRENTLY statements -- dropping first would leave a window with no index '.
        'coverage on this access path.'
    );
});

// ─── DISC-1: guard Check 7 — DROP INDEX (non-CONCURRENTLY) on a hot table ──────
//
// Unlike the file-text assertions above, these run the guard SCRIPT itself against
// synthetic fixtures to prove Check 7 actually fires (Checks 1-6 have no behavioral
// test). The guard reads a RELATIVE path (supabase/migrations), so pointing its
// working directory at a temp dir scans the fixtures instead of the repo -- no
// change to the guard script required. No DB, so it runs on SQLite in every CI pass.

function guardRunAgainstFixture(string $sqlBasename, string $sql): array
{
    $tmp = sys_get_temp_dir().'/migguard_'.uniqid();
    mkdir($tmp.'/supabase/migrations', 0777, true);
    file_put_contents($tmp.'/supabase/migrations/'.$sqlBasename, $sql);

    $guard = base_path('scripts/guard-no-unsafe-migrations.php');
    $output = [];
    $exit = 0;
    exec('cd '.escapeshellarg($tmp).' && php '.escapeshellarg($guard).' 2>&1', $output, $exit);

    @unlink($tmp.'/supabase/migrations/'.$sqlBasename);
    @rmdir($tmp.'/supabase/migrations');
    @rmdir($tmp.'/supabase');
    @rmdir($tmp);

    return ['exit' => $exit, 'output' => implode("\n", $output)];
}

it('Check 7 flags a bare DROP INDEX on a hot table in a post-cutoff migration', function () {
    $r = guardRunAgainstFixture(
        '20260722120000_disc1_bad.sql',
        "DROP INDEX IF EXISTS site.idx_blocks_live_check_enabled;\n"
    );

    // toContain() takes a variadic needle list, not a (needle, message) pair --
    // a second string argument is matched as an ADDITIONAL required needle, not
    // rendered as a failure message. The failure-source assertion is carried by
    // the needle text itself ("DROP INDEX without CONCURRENTLY on hot table" is
    // Check 7's unique error string).
    expect($r['exit'])->toBe(1, "Expected the guard to FAIL on a bare DROP INDEX on hot site.blocks; output:\n{$r['output']}")
        ->and($r['output'])->toContain('DROP INDEX without CONCURRENTLY on hot table');
});

it('Check 7 allows DROP INDEX CONCURRENTLY on a hot table', function () {
    $r = guardRunAgainstFixture(
        '20260722120000_disc1_ok.sql',
        "DROP INDEX CONCURRENTLY IF EXISTS site.idx_blocks_live_check_enabled;\n"
    );

    expect($r['exit'])->toBe(0, "DROP INDEX CONCURRENTLY is the safe pattern and must pass; output:\n{$r['output']}");
});

it('Check 7 grandfathers a pre-cutoff bare DROP INDEX on a hot table', function () {
    $r = guardRunAgainstFixture(
        '20260701180000_disc1_old.sql',
        "BEGIN;\nDROP INDEX IF EXISTS site.idx_blocks_live_check_enabled;\nCOMMIT;\n"
    );

    expect($r['exit'])->toBe(0, "Files timestamped on/before DROP_INDEX_GUARD_CUTOFF are grandfathered; output:\n{$r['output']}");
});

it('Check 7 ignores a bare DROP INDEX on a non-hot table', function () {
    $r = guardRunAgainstFixture(
        '20260722120000_disc1_cold.sql',
        "DROP INDEX IF EXISTS site.enquiries_user_created_idx;\n"
    );

    expect($r['exit'])->toBe(0, "Only HOT_TABLES drops are flagged; a cold-table drop is out of scope; output:\n{$r['output']}");
});

// ─── B19: guard Check 8 — VALIDATE CONSTRAINT bundled with its ADD ... NOT VALID ──
// Reuses guardRunAgainstFixture() defined above. site.platform_connections is used
// deliberately (not a HOT_TABLES table) so only Check 8 can fire, never Check 5.

it('Check 8 flags VALIDATE in the same transaction as ADD ... NOT VALID (post-cutoff)', function () {
    $r = guardRunAgainstFixture(
        '20260722130000_b19_bad.sql',
        "BEGIN;\n".
        "ALTER TABLE site.platform_connections\n".
        "    ADD CONSTRAINT pc_demo_check CHECK (apify_status IS NULL OR apify_status IN ('ok')) NOT VALID;\n".
        "ALTER TABLE site.platform_connections VALIDATE CONSTRAINT pc_demo_check;\n".
        "COMMIT;\n"
    );

    expect($r['exit'])->toBe(1, "Expected the guard to FAIL when VALIDATE shares the txn with ADD ... NOT VALID; output:\n{$r['output']}")
        ->and($r['output'])->toContain('VALIDATE CONSTRAINT runs in the same transaction');
});

it('Check 8 allows VALIDATE in a separate transaction from ADD ... NOT VALID', function () {
    $r = guardRunAgainstFixture(
        '20260722130000_b19_ok.sql',
        "BEGIN;\n".
        "ALTER TABLE site.platform_connections\n".
        "    ADD CONSTRAINT pc_demo_check CHECK (apify_status IS NULL OR apify_status IN ('ok')) NOT VALID;\n".
        "COMMIT;\n".
        "BEGIN;\n".
        "ALTER TABLE site.platform_connections VALIDATE CONSTRAINT pc_demo_check;\n".
        "COMMIT;\n"
    );

    expect($r['exit'])->toBe(0, "The two-transaction split is the safe pattern and must pass; output:\n{$r['output']}");
});

it('Check 8 allows a VALIDATE-only file (the two-file split, ADD lives elsewhere)', function () {
    $r = guardRunAgainstFixture(
        '20260722130000_b19_validate_only.sql',
        "BEGIN;\n".
        "ALTER TABLE site.platform_connections VALIDATE CONSTRAINT pc_demo_check;\n".
        "COMMIT;\n"
    );

    expect($r['exit'])->toBe(0, "A VALIDATE with no ADD ... NOT VALID in the same file is the correct split; output:\n{$r['output']}");
});

it('Check 8 grandfathers a pre-cutoff bundled VALIDATE', function () {
    $r = guardRunAgainstFixture(
        '20260701000000_b19_old.sql',
        "BEGIN;\n".
        "ALTER TABLE site.platform_connections\n".
        "    ADD CONSTRAINT pc_demo_check CHECK (apify_status IS NULL OR apify_status IN ('ok')) NOT VALID;\n".
        "ALTER TABLE site.platform_connections VALIDATE CONSTRAINT pc_demo_check;\n".
        "COMMIT;\n"
    );

    expect($r['exit'])->toBe(0, "Files on/before VALIDATE_TXN_GUARD_CUTOFF are grandfathered; output:\n{$r['output']}");
});

// ─── G3 (audit Unit H): disable-file marker needs COMMENT-PROSE justification ──
// Reuses guardRunAgainstFixture() defined above. G3 anchors the justification
// search to the contiguous comment-only prefix after the marker (`--` lines /
// blank lines), stopping at the first real SQL line -- not a fixed character
// window over raw SQL. A fixed window let an incidental keyword hit inside a
// CHECK constraint's string literal (e.g. `'no reason given'`) satisfy the
// check; this is the adversarial repro that motivated the fix.

it('G3 rejects a disable-file marker whose only keyword hit sits inside SQL, not comment prose', function () {
    $r = guardRunAgainstFixture(
        '20260722140000_g3_adversarial.sql',
        "-- guard:no-unsafe-migrations:disable-file\n".
        "ALTER TABLE routing.source_intents\n".
        "    ADD CONSTRAINT chk_reason_present CHECK (block_reason IS NOT NULL OR state = 'no reason given');\n".
        "ALTER TABLE site.platform_connections ALTER COLUMN surface_key SET NOT NULL;\n".
        "CREATE INDEX idx_adversarial ON site.platform_connections (surface_key);\n"
    );

    expect($r['exit'])->toBe(1, "Expected the guard to FAIL -- the only 'reason' hit sits inside a string literal in the SQL body, not comment prose; output:\n{$r['output']}")
        ->and($r['output'])->toContain('guard:no-unsafe-migrations:disable-file with no written justification');
});

it('G3 accepts a disable-file marker followed by genuine comment prose stating a rationale', function () {
    $r = guardRunAgainstFixture(
        '20260722140000_g3_genuine.sql',
        "-- guard:no-unsafe-migrations:disable-file\n".
        "-- Rationale: this table is empty at migration time (created earlier in\n".
        "-- this same deploy window), so the safe-lock conventions don't apply.\n".
        "ALTER TABLE site.platform_connections ALTER COLUMN surface_key SET NOT NULL;\n".
        "CREATE INDEX idx_genuine ON site.platform_connections (surface_key);\n"
    );

    expect($r['exit'])->toBe(0, "Expected the guard to PASS -- the marker is followed by genuine comment prose stating a rationale; output:\n{$r['output']}");
});

// ─── LC-ROLLBACK: every migration states a reverse path (CONVENTIONS.md §10) ──
//
// Anchored to line start so PROSE mentioning reverting cannot satisfy it. Three
// files contain "revert"/"rollback" as prose only -- 20260727140000 ("reverting
// is deleting the row"), 20260729130000 ("DINT-16's revert bug") and the baseline
// (a COMMENT ON COLUMN string literal) -- and the loose grep the audit used
// counted all three as compliant. Requiring the keyword at the HEAD of a comment
// line is what tells a note apart from a mention.

function migrationBoundaryHasRollbackNote(string $sql): bool
{
    return preg_match('/^[ \t]*--[ \t]*(ROLLBACK|TO\s+REVERT|REVERT)[ \t]*:/im', $sql) === 1;
}

it('every migration in supabase/migrations/ states a reverse path', function () {
    $missing = [];

    foreach (glob(base_path('supabase/migrations/*.sql')) ?: [] as $file) {
        if (! migrationBoundaryHasRollbackNote((string) file_get_contents($file))) {
            $missing[] = basename($file);
        }
    }

    expect($missing)->toBe([], "These migrations carry no reverse path (CONVENTIONS.md §10):\n  - "
        .implode("\n  - ", $missing)
        ."\n\nAdd a header line: `-- ROLLBACK: <exact inverse statement>;`"
        ."\nIf there is no reverse -- a DELETE, a backfill with no recorded pre-image, a"
        ."\nDROP -- write `-- ROLLBACK: NONE.` and one line of why. Supabase is on the Free"
        ."\nplan: no PITR, no managed backups. A note that claims revertibility where none"
        ."\nexists is worse than no note.");
});

// ─── #MIG-1: the unified-actions data cleanup moved out of the migration ───
//
// 20260823100000_unified_actions.sql used to bundle two full-table DML
// statements (action_events / site.sites.settings) alongside its DDL. Both
// migrations in this pair are ALREADY APPLIED on dev (the Supabase CLI keys
// its ledger on version, not content, so editing an applied file is a no-op
// there) — the fix moved the re-runnable half to
// `php artisan partna:scrub-unified-actions-legacy` so prod (which has not
// applied this file's data yet) can still get it without re-running the
// whole migration. (b) below is the one that matters: it pins that the
// content_type = 'page' DELETE — which CANNOT move, because the ADD
// CONSTRAINT ... NOT VALID right after it starts enforcing on new writes
// immediately, and ComputeContentPopularityScores would crash on a surviving
// 'page' row — stays inline, ahead of the VALIDATE CONSTRAINT that runs in
// the companion file.

it('MIG-1: 20260823100000_unified_actions.sql no longer contains the action_events/site.sites.settings DML', function () {
    $sql = migrationBoundaryStripComments(migrationBoundaryReadSql('20260823100000_unified_actions.sql'));

    expect($sql)->not->toMatch('/DELETE\s+FROM\s+analytics\.action_events/i',
        'Expected the action_events legacy-vocabulary DELETE to have moved to '
        .'php artisan partna:scrub-unified-actions-legacy (ScrubUnifiedActionsLegacyCommand).')
        ->and($sql)->not->toMatch('/UPDATE\s+site\.sites\s+SET/i',
            'Expected the site.sites.settings legacy-key UPDATE to have moved to '
            .'php artisan partna:scrub-unified-actions-legacy (ScrubUnifiedActionsLegacyCommand).');
});

it('MIG-1: the content_popularity_scores page DELETE stays inline, before ADD CONSTRAINT, and 20260823100001 still VALIDATEs', function () {
    $sql = migrationBoundaryStripComments(migrationBoundaryReadSql('20260823100000_unified_actions.sql'));

    expect(preg_match(
        "/DELETE\s+FROM\s+analytics\.content_popularity_scores\s+WHERE\s+content_type\s*=\s*'page'/i",
        $sql,
        $deleteMatch,
        PREG_OFFSET_CAPTURE,
    ))->toBe(1, 'Expected the content_type = \'page\' DELETE to still be present and inline in 20260823100000 — '
        .'it must never be extracted into a re-runnable command (see the file\'s own header).');

    expect(preg_match('/ADD\s+CONSTRAINT\s+content_popularity_scores_content_type_check/i', $sql, $addMatch, PREG_OFFSET_CAPTURE))
        ->toBe(1);

    expect($deleteMatch[0][1])->toBeLessThan($addMatch[0][1],
        'The page DELETE must run before ADD CONSTRAINT ... NOT VALID -- once the constraint is added it '
        .'enforces on every new write, and a surviving page row would fail it.');

    $validateSql = migrationBoundaryReadSql('20260823100001_unified_actions_validate.sql');
    expect($validateSql)->toMatch('/VALIDATE\s+CONSTRAINT\s+content_popularity_scores_content_type_check/i',
        'Expected 20260823100001 to still VALIDATE the constraint added by 20260823100000.');
});

it('MIG-1: 20260823120000_item_scores_keyed_by_id.sql keeps its DELETE (comment-only edit, not a behaviour change)', function () {
    $sql = migrationBoundaryStripComments(migrationBoundaryReadSql('20260823120000_item_scores_keyed_by_id.sql'));

    expect($sql)->toMatch(
        "/DELETE\s+FROM\s+analytics\.content_popularity_scores\s+WHERE\s+content_type\s+IN\s*\(\s*'shop_product'\s*,\s*'link_item'\s*\)/i",
        'This migration is a one-shot bounded by "the scoring job had not yet run" -- it must not be '.
        're-expressed as a re-runnable command (non-item-id keys are still being written today).'
    );
});

it('the reverse-path matcher rejects prose that merely mentions reverting', function () {
    expect(migrationBoundaryHasRollbackNote("-- editing one field freezes it; reverting is deleting the row\n"))->toBeFalse()
        ->and(migrationBoundaryHasRollbackNote("-- DINT-16's revert bug can leave two rows behind\n"))->toBeFalse()
        ->and(migrationBoundaryHasRollbackNote("COMMENT ON COLUMN t.c IS 'revert via /resync';\n"))->toBeFalse()
        ->and(migrationBoundaryHasRollbackNote("-- ROLLBACK: DROP TABLE IF EXISTS x;\n"))->toBeTrue()
        ->and(migrationBoundaryHasRollbackNote("-- To revert: DROP TABLE IF EXISTS x;\n"))->toBeTrue();
});
