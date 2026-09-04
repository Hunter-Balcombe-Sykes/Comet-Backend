<?php

use Tests\Support\Architecture\PostgresLaneCheckDomainScanner;
use Tests\Support\Architecture\PostgresLaneDdlScanner;

/**
 * PostgresLaneDdlDriftTest asks whether the PG lane's hand-written DDL names a
 * table or column supabase/migrations/ doesn't have. Its own docblock says it
 * "has no jurisdiction" past column NAMES — it never looks at what a CHECK on
 * one of those columns actually admits. This file is that other half: does a
 * hand-written CHECK value list in tests/Postgres/*.php still cover everything
 * the CURRENT migrations admit for that table.column?
 *
 * ONLY A MISSING VALUE IS DRIFT. A stand-in with an extra value, or the exact
 * same set, still lets through everything the real schema allows — so this
 * checks for a STRICT SUBSET (stand-in ⊊ migrations), not mere inequality.
 * Strict-subset drift is a false green with a delay fuse: the file's own tests
 * stay green because none of them happens to write the value the migration
 * added, right up until one does (a new test, or a shared helper reaching a
 * write path this file doesn't cover today) — at which point Postgres 23514s a
 * write that is perfectly valid in dev and prod.
 *
 * FIXED BY HAND 2026-09-04, the drift that prompted this file: three files
 * (SourceIntentUpsertRaceTest, SourceReconcilerAtomicityTest,
 * SourceReconcilerConnectionRacePgTest) had routing.source_intents.state
 * missing 'verifying', and .block_reason missing 'not_found' AND
 * 'sibling_branch' — the latter not hypothetical, SourceReconciler.php:185
 * already writes it, so those three files' own stand-in was a live landmine.
 * Separately, nine files had content.item_media.role missing 'video'
 * (20260819001100_item_media_role_video.sql). All eleven fixes are what makes
 * this file green today; reverting any one of them must turn it red.
 *
 * WHAT IT CANNOT CATCH, stated plainly rather than implied. Both holes are
 * about a CHECK this file never SEES; nothing it does see escapes it.
 *
 *   1. A stand-in that declares the column with NO CHECK at all. There is
 *      nothing to compare, so a lane table whose column is bare `text` where
 *      the real one is constrained looks identical to a lane table that got it
 *      right. That direction belongs to PostgresLaneDdlDriftTest's successor,
 *      not here — this file answers "is the CHECK you wrote still current",
 *      not "should there be one".
 *   2. A CHECK whose value list is assembled through a PHP variable rather
 *      than typed inline where the CHECK appears. A parser working on raw
 *      source text has nothing adjacent to resolve the table from. Right now
 *      that is exactly one recurring idiom, and UNPARSEABLE_LANE_CHECKS below
 *      names every instance instead of letting the scanner drop them silently
 *      — with the second test failing loudly if that list ever stops matching
 *      reality in either direction.
 */
const UNPARSEABLE_LANE_CHECKS = [
    // FacetOriginScopeTest.php and eight PG-lane siblings copy its beforeEach
    // verbatim (their own docblocks say so). All nine build their facet tables
    // through a `$singletons = ['f_occurrence' => "...zone_confidence text
    // CHECK (zone_confidence IS NULL OR zone_confidence IN (...)), ...", ...]`
    // array, then `foreach ($singletons as $facet => $columns) { ... CREATE
    // TABLE content.{$facet} (... {$columns} ...) ... }` (e.g.
    // FacetOriginScopeTest.php:220-244). The CHECK's own statement text (inside
    // the array value) never mentions "content.f_occurrence" — that join only
    // happens one loop iteration later, through the $facet variable — so a
    // parser reading raw source text has nothing textually adjacent to resolve
    // the table from. Confirmed by hand against
    // supabase/migrations/20260731230000_f_occurrence_zone_confidence_offset_only.sql:
    // all nine stand-ins already carry the full current 4-value domain
    // (explicit, inferred, assumed, offset_only) — an acknowledged blind spot,
    // not a deferred fix.
    'FacetOriginScopeTest.php' => 'zone_confidence',
    'MergeFacetFoldTest.php' => 'zone_confidence',
    'ProjectionIdentityKeyAtomicityTest.php' => 'zone_confidence',
    'ProjectionWriterBatchingTest.php' => 'zone_confidence',
    'ProjectionWriterConnectionSourceRaceTest.php' => 'zone_confidence',
    'ProjectionWriterIdentityRaceTest.php' => 'zone_confidence',
    'ProjectionWriterManualCoordRaceTest.php' => 'zone_confidence',
    'ProjectionWriterMergeAnchorTest.php' => 'zone_confidence',
    'ProjectionWriterScopedResolveTest.php' => 'zone_confidence',
];

it('does not let a PG-lane CHECK value list go stale against supabase/migrations', function () {
    $laneChecks = PostgresLaneCheckDomainScanner::laneChecks(base_path('tests/Postgres'));
    $migrationDomains = PostgresLaneCheckDomainScanner::migrationDomains(base_path('supabase/migrations'));

    $drift = [];
    $noCounterpart = [];

    foreach ($laneChecks as $check) {
        $key = "{$check['table']}.{$check['column']}";

        if (! isset($migrationDomains[$key])) {
            // A lane-local fixture table (PostgresLaneDdlScanner::isScratch())
            // was never meant to mirror a real CHECK, so it has nothing to
            // compare against — anything else with no migration counterpart
            // is a genuine gap for a HUMAN to look at (dropped column, typo,
            // scanner miss), not silently dropped here.
            if (! PostgresLaneDdlScanner::isScratch($check['table'])) {
                $noCounterpart[] = sprintf('%s: %s (declared in the stand-in; no migration CHECK found for this table.column)', $check['file'], $key);
            }

            continue;
        }

        $real = $migrationDomains[$key];
        $missing = array_diff($real, $check['values']);
        $extra = array_diff($check['values'], $real);

        // Strict subset only: $missing !== [] is the drift; $extra !== [] on
        // its own is a stand-in ahead of (or beside) the migration, which is
        // fine — see this file's own docblock.
        if ($missing !== [] && $extra === []) {
            $drift[] = sprintf(
                '%s: %s is missing [%s] — stand-in admits [%s], migrations currently admit [%s]',
                $check['file'],
                $key,
                implode(', ', $missing),
                implode(', ', $check['values']),
                implode(', ', $real)
            );
        }
    }

    sort($drift);
    sort($noCounterpart);

    expect($drift)->toBe([], "PG-lane CHECK value list(s) have drifted from supabase/migrations/:\n".implode("\n", $drift).
        "\n\nWiden the stand-in's CHECK to include the missing value(s).");
    expect($noCounterpart)->toBe([], "PG-lane CHECK(s) with no matching migration CHECK found for their table.column:\n".implode("\n", $noCounterpart).
        "\n\nEither the migration side is missing a domain-list CHECK this scanner should have found, or this table.column ".
        'genuinely has none — if the latter and the table is lane-local, name it with one of: '.
        implode(', ', PostgresLaneDdlScanner::SCRATCH_SUFFIXES));
});

// No silent skips: every domain-list CHECK the scanner finds but cannot place
// resolves here, one way or the other — either it is named above with a
// reason, or the test fails and names it itself. Both directions are checked:
// an unresolved CHECK not on the list fails loud, and a listed entry the
// scanner no longer produces (the shape got fixed, or a file was deleted)
// fails loud too, so the allowlist can't quietly go stale.
it('names every CHECK it could not resolve to a table, rather than dropping it', function () {
    $unresolved = PostgresLaneCheckDomainScanner::laneUnresolved(base_path('tests/Postgres'));

    $unexpected = [];
    $seenFiles = [];

    foreach ($unresolved as $entry) {
        $seenFiles[$entry['file']] = true;

        if ((UNPARSEABLE_LANE_CHECKS[$entry['file']] ?? null) !== $entry['column']) {
            $unexpected[] = sprintf('%s: column=%s values=[%s]', $entry['file'], $entry['column'], implode(',', $entry['values']));
        }
    }

    $stale = array_diff(array_keys(UNPARSEABLE_LANE_CHECKS), array_keys($seenFiles));

    sort($unexpected);
    sort($stale);

    expect($unexpected)->toBe([], "Unresolved CHECK(s) not covered by the named allowlist:\n".implode("\n", $unexpected).
        "\n\nEither teach the scanner this shape, or add a named entry to UNPARSEABLE_LANE_CHECKS with a one-line reason.");
    expect($stale)->toBe([], 'UNPARSEABLE_LANE_CHECKS entries the scanner no longer reports as unresolved (stale — remove them): '.implode(', ', $stale));
});

// A parser that silently matched almost nothing would make the gate above
// vacuous and permanently green, the same non-vacuousness concern
// PostgresLaneDdlDriftTest pins for column-name drift. These pin that both
// sides parsed a real volume of CHECKs, not a handful, and spot-check the
// exact three columns this file exists because of.
it('parses a real volume of CHECKs on both sides, not a handful', function () {
    $laneChecks = PostgresLaneCheckDomainScanner::laneChecks(base_path('tests/Postgres'));
    $migrationChecks = PostgresLaneCheckDomainScanner::migrationChecksInOrder(base_path('supabase/migrations'));
    $migrationDomains = PostgresLaneCheckDomainScanner::migrationDomains(base_path('supabase/migrations'));

    expect(count($laneChecks))->toBeGreaterThan(50)
        ->and(count($migrationChecks))->toBeGreaterThan(100)
        ->and(count($migrationDomains))->toBeGreaterThan(80)
        ->and($migrationDomains)->toHaveKey('routing.source_intents.state')
        ->and($migrationDomains['routing.source_intents.state'])->toContain('verifying')
        ->and($migrationDomains)->toHaveKey('routing.source_intents.block_reason')
        ->and($migrationDomains['routing.source_intents.block_reason'])->toContain('not_found', 'sibling_branch')
        ->and($migrationDomains)->toHaveKey('content.item_media.role')
        ->and($migrationDomains['content.item_media.role'])->toContain('video');
});
