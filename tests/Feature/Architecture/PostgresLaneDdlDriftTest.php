<?php

use Tests\Support\Architecture\PostgresLaneDdlScanner;

/**
 * G — the PG lane's hand-written DDL may not declare a table or column that
 * supabase/migrations/ does not have.
 *
 * THE COVERAGE HOLE THIS FILLS. tests/Postgres/ provisions its own tables
 * per-file against a real disposable Postgres, and every other DDL gate stops
 * at its edge on purpose: SchemaDriftGuardTest introspects the SQLite stand-in
 * only, and NoLocalCanonicalTableDdlTest / DuplicateStandInDdlGuardTest both
 * exclude tests/Postgres/ by path, the first stating outright that
 * SchemaDriftGuardTest "has no jurisdiction there". So nothing checked that
 * lane's DDL against reality, and its DDL is a hand-written guess frozen at the
 * moment each file was written — it can only ever go stale.
 *
 * WHAT IT CAUGHT ON ITS FIRST RUN (all four confirmed absent from dev
 * 2026-08-28, then fixed in the same change):
 *   site.site_media.user_id / .storage_path — the real table has site_id/path;
 *     those two names belong to content.media_assets;
 *   notifications.notifications.read_at — read_at lives on
 *     notifications.notification_receipts;
 *   core.pre_account_builds.source_key — the real column is source_ref, and the
 *     partial unique index ClaimConcurrencyTest builds to mirror
 *     pre_account_builds_live_source_unique was therefore built on a column
 *     name that has never existed. Its own comment claimed it was "named as in
 *     supabase/migrations/ so a shape change there shows up here."
 *
 * WHAT IT CANNOT CATCH, stated so nobody reads it as more than it is: the
 * other direction. A writer that starts touching a table this lane never
 * provisions still 42P01s at runtime and no static scan can see it coming —
 * that failure mode is contained instead by Tests\PostgresTestCase::setUp()'s
 * Queue::fake(), which stops an unrelated downstream job from being executed
 * for real inside this lane.
 */
it('declares no PG-lane table or column that supabase/migrations does not have', function () {
    $drift = PostgresLaneDdlScanner::drift(base_path('supabase/migrations'), base_path('tests/Postgres'));

    $report = [];
    foreach ($drift['tables'] as $table => $files) {
        $report[] = sprintf('  TABLE  %s — created by %s', $table, implode(', ', $files));
    }
    foreach ($drift['columns'] as $column) {
        $report[] = '  COLUMN '.$column;
    }

    expect($report)->toBe([], "PG-lane DDL has drifted from supabase/migrations/:\n".implode("\n", $report).
        "\n\nFix the lane DDL to match the real schema. If the table is a fixture this lane owns rather than a\n".
        'stand-in for a real one, name it with one of: '.implode(', ', PostgresLaneDdlScanner::SCRATCH_SUFFIXES));
});

// A parser that silently matches nothing would make the gate above vacuous and
// permanently green. These pin that both sides actually parsed.
it('parses both DDL sources rather than passing on an empty scan', function () {
    $real = PostgresLaneDdlScanner::realSchema(base_path('supabase/migrations'));
    $lane = PostgresLaneDdlScanner::laneDdl(base_path('tests/Postgres'));

    expect(count($real))->toBeGreaterThan(100)
        ->and(count($lane))->toBeGreaterThan(50)
        ->and($real)->toHaveKey('core.pre_account_builds')
        ->and($real['core.pre_account_builds'])->toContain('source_ref', 'source_ref_lc')
        ->and($real['core.pre_account_builds'])->not->toContain('source_key');
});

it('treats only suffix-named fixtures as lane-local', function () {
    expect(PostgresLaneDdlScanner::isScratch('core.claim_race_probe'))->toBeTrue()
        ->and(PostgresLaneDdlScanner::isScratch('site.section_shape_scratch'))->toBeTrue()
        ->and(PostgresLaneDdlScanner::isScratch('site.platform_connections_pit_test'))->toBeTrue()
        ->and(PostgresLaneDdlScanner::isScratch('core.pre_account_builds'))->toBeFalse()
        ->and(PostgresLaneDdlScanner::isScratch('site.site_media'))->toBeFalse();
});
