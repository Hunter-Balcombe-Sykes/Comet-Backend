<?php

use Tests\Support\Architecture\AppColumnReadScanner;
use Tests\Support\Architecture\PostgresLaneDdlScanner;

/**
 * G — a tests/Postgres/ stand-in must declare every column the app code that
 * file drives actually reads from it.
 *
 * THE COVERAGE HOLE THIS FILLS. PostgresLaneDdlDriftTest checks stand-in ⊆
 * real schema: it catches a column the lane INVENTED. Its own docblock states
 * it cannot catch the other direction, and the other direction is what has
 * taken this lane red twice — slice 5a (7 tests), and da958493e, which added
 * rs.last_seen_run to ProjectionWriter's select and left 55 test classes
 * failing for ~15 consecutive runs while postgres-tests, a REQUIRED check,
 * gave no signal at all. In both cases the schema was right and the code was
 * right; only the hand-written stand-in was behind, and a green SQLite run
 * said nothing about it.
 *
 * SCOPE, and why it is drawn here. Only tables a lane file ALREADY PROVISIONS
 * are checked. This guard never demands a new table, because minimal
 * stand-ins are deliberate — `CREATE TABLE core.users (id uuid PRIMARY KEY)`
 * exists purely as an FK target, and forcing full-fat tables everywhere would
 * slow the lane and hide bugs. Eloquent access emits no literal column
 * strings, so a model-driven read never demands a column either. The
 * remaining failure mode — a writer touching a table this lane never
 * provisions at all — stays uncatchable by any static scan, exactly as
 * PostgresLaneDdlDriftTest says.
 *
 * THE FIX FOR A FINDING IS ALWAYS ADDITIVE: add the column to the stand-in,
 * preferably via ALTER TABLE … ADD COLUMN IF NOT EXISTS so it heals whichever
 * file loses the first-creator-wins race. Never delete an assertion, and never
 * thin a table to silence this. If a test only passed because a column was
 * missing, that is a finding to report, not to paper over.
 */
it('declares every column the app code a PG-lane file drives reads from a table it provisions', function () {
    $appRefs = AppColumnReadScanner::scanTree(base_path('app'));
    $byFile = PostgresLaneDdlScanner::laneDdlByFile(base_path('tests/Postgres'));

    // (file, schema.table) pairs whose stand-in is deliberately not faithful.
    $exempt = [
        // DELIBERATELY POISONED: a CHECK that lets every SELECT succeed and
        // rejects every INSERT, so applyIntent() fails mid-transaction and the
        // rollback can be observed. Completing this table defeats the test.
        // NOTE: that file's own comment claims it is "ALLOWLISTED in
        // no-local-canonical-ddl-baseline.json" — it is not, and never needed
        // to be. That baseline holds no tests/Postgres/ entries at all;
        // NoLocalCanonicalTableDdlTest excludes the lane BY PATH. The comment
        // is stale, verified 2026-09-01. This guard has no path exclusion, so
        // the exemption has to be explicit here.
        'SourceReconcilerAtomicityTest.php|site.platform_connections',
        // SourceReconcilerConnectionRacePgTest.php is deliberately NOT exempt,
        // even though it shares the same table with its poisoned sibling
        // above. Its own comment at line 108 reads: "Faithful (NOT poisoned —
        // contrast SourceReconcilerAtomicityTest): every column
        // site.platform_connections really has, so the next column the
        // writer picks up does not cost another red CI cycle." Exempting it
        // would blind this guard on the one file its author built faithful
        // for exactly this guard's purpose — verified 2026-09-01.
    ];

    // Confirmed-real drift, triaged by hand (both sides opened, not batch-trusted) and
    // recorded here as a WORK QUEUE for a follow-up task to drain — see task-3-report.md
    // for the full pre-triage finding list and the reasoning behind each entry below.
    $knownDrift = [
        // The da958493e regression (see docblock): ProjectionWriter's identity-resolution
        // select reads rs.last_seen_run, but these stand-ins predate that column.
        'ProjectionIdentityKeyAtomicityTest.php|ingest.record_state.last_seen_run',
        'ProjectionWriterBatchingTest.php|ingest.record_state.last_seen_run',
        'ProjectionWriterConnectionSourceRaceTest.php|ingest.record_state.last_seen_run',
        'ProjectionWriterScopedResolveTest.php|ingest.record_state.last_seen_run',
        // healMirrorEligible() reads content.media_assets.mirror_eligible; latent until now
        // because nothing had cross-referenced app reads against these stand-ins before.
        'ProjectionWriterIdentityRaceTest.php|content.media_assets.mirror_eligible',
        'ProjectionWriterManualCoordRaceTest.php|content.media_assets.mirror_eligible',
        'ProjectionWriterMergeAnchorTest.php|content.media_assets.mirror_eligible',
        'ProjectionWriterScopedResolveTest.php|content.media_assets.mirror_eligible',
    ];

    // Known limitation, not drift: attribution is per file-level `use App\...;` import, i.e.
    // per CLASS, not per METHOD. ShopContentWriter's cataloguesFor() is what
    // ShopCatalogueCreatedAtTimezoneTest's stand-in is deliberately minimal for; removed_at
    // and updated_at are written by a different method on the same class,
    // retireStaleItems(), which this test never calls. Thinning the app-side scanner to
    // method granularity is out of scope for this guard (see AppColumnReadScanner) —
    // recorded here rather than sent back to Task 1, and NOT fixed by thinning the table.
    $knownLimitation = [
        'ShopCatalogueCreatedAtTimezoneTest.php|content.items.removed_at',
        'ShopCatalogueCreatedAtTimezoneTest.php|content.items.updated_at',
    ];

    $findings = [];
    foreach (glob(base_path('tests/Postgres').'/*.php') ?: [] as $path) {
        $file = basename($path);
        $standIns = $byFile[$file] ?? [];
        if ($standIns === []) {
            continue;
        }

        $source = (string) file_get_contents($path);
        preg_match_all('/^use (App\\\\[\w\\\\]+);/m', $source, $imports);

        foreach ($imports[1] as $fqcn) {
            foreach ($appRefs[$fqcn] ?? [] as $table => $columns) {
                if (! isset($standIns[$table]) || PostgresLaneDdlScanner::isScratch($table)) {
                    continue;
                }
                if (in_array($file.'|'.$table, $exempt, true)) {
                    continue;
                }

                foreach ($columns as $column) {
                    if (! in_array($column, $standIns[$table], true)) {
                        $findings[] = sprintf(
                            '%s — %s.%s, read by %s',
                            $file, $table, $column, class_basename($fqcn)
                        );
                    }
                }
            }
        }
    }

    $findings = array_values(array_unique($findings));
    sort($findings);

    $queueKeys = array_merge($knownDrift, $knownLimitation);
    $remaining = array_values(array_filter($findings, function (string $finding) use ($queueKeys) {
        // $findings entries read "file — table.column, read by Class"; queue entries are
        // "file|table.column" — rebuild that key from the finding to match against the queue.
        [$file, $rest] = explode(' — ', $finding, 2);
        [$tableColumn] = explode(',', $rest, 2);

        return ! in_array($file.'|'.$tableColumn, $queueKeys, true);
    }));

    expect($remaining)->toBe([], "PG-lane stand-ins are missing columns the code under test reads.\n".
        "Each one is an SQLSTATE 42703 that fires BEFORE the assertion it is hiding, and because\n".
        "this lane shares tables, one missing column cascades into dozens of unrelated failures:\n  ".
        implode("\n  ", $remaining).
        "\n\nFix by ADDING the column to the stand-in — ideally\n".
        "  ALTER TABLE <table> ADD COLUMN IF NOT EXISTS <column> <type>\n".
        'so it heals whichever file loses the first-creator-wins race. Never thin a table to silence this.');

    fwrite(STDERR, "\n[pg-read-coverage] ".count($knownDrift).' known-drift finding(s) queued for Task 4, '.
        count($knownLimitation)." known-limitation finding(s) (per-class, not per-method, attribution —\n".
        "not drift) recorded and excluded — see the docblocks above for both lists.\n");
});

// A scanner that silently matched nothing would make the gate above vacuous and
// permanently green. These pin that both sides actually parsed, and that the
// cross-reference actually evaluated something.
it('cross-references a meaningful number of columns rather than passing on an empty scan', function () {
    $appRefs = AppColumnReadScanner::scanTree(base_path('app'));
    $byFile = PostgresLaneDdlScanner::laneDdlByFile(base_path('tests/Postgres'));

    $appTables = [];
    foreach ($appRefs as $tables) {
        foreach ($tables as $table => $columns) {
            $appTables[$table] = true;
        }
    }

    expect(count($byFile))->toBeGreaterThanOrEqual(50)
        ->and(count($appTables))->toBeGreaterThanOrEqual(40)
        ->and($appRefs['App\Ingest\Projection\ProjectionWriter']['ingest.record_state'] ?? [])
        ->toContain('last_seen_run', 'key', 'stream_id');
});

it('does not mistake a dotted literal for a column reference', function () {
    $refs = AppColumnReadScanner::scanSource("<?php Log::info('analytics.ingest.dropped'); \$h = 'api.deezer.com';");

    expect($refs)->toBe([]);
});
