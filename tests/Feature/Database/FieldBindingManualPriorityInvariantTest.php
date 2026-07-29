<?php

// Always-runs (SQLite, default `composer test` lane) companion to
// tests/Postgres/FieldBindingsManualPriorityTest.php, which is the primary
// deliverable but only runs when a real Postgres is available. This file
// follows the anti-tautology anchor pattern documented in
// ConstraintVocabularyLockstepTest.php:29-37: one side of every comparison
// is a HARDCODED expected string, written by hand into this test and
// independent of both the migration and the SQLite fixture mirror, so a
// drift that touches both the migration and tests/Pest.php's stand-in
// together cannot slip through as a silent no-op.

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('the migration still declares the manual-priority CHECK', function () {
    $sql = (string) file_get_contents(base_path('supabase/migrations/20260728150000_field_bindings.sql'));

    expect($sql)->toContain('CONSTRAINT "field_bindings_manual_priority"');

    preg_match('/CONSTRAINT\s+"field_bindings_manual_priority"\s+CHECK\s*\((.*?)\n\s*\)/s', $sql, $m);
    expect($m[1] ?? null)->not->toBeNull('field_bindings_manual_priority CHECK not found in the migration.');

    // Whitespace-normalise to a single line before comparing.
    $normalised = preg_replace('/\s+/', ' ', trim($m[1]));

    // Hand-written anchor — deliberately NOT derived from the migration or
    // from tests/Pest.php's stand-in (either would be a tautology).
    $expected = '("source_key" = \'manual\' AND "priority" = 0) OR ("source_key" <> \'manual\' AND "priority" > 0)';

    expect($normalised)->toBe($expected);
});

it('the SQLite fixture mirror enforces the same rule', function () {
    // setupFieldBindingsTable() (tests/Pest.php:3390) is the stand-in every
    // other FieldBindings-touching test seeds through. If someone strips the
    // CHECK out of that stand-in, every test using it would stay green while
    // seeding illegal rows — FixtureSchemaParityTest only diffs columns
    // (FIXTURE_PARITY_TABLES) and doesn't cover this table or constraints at
    // all, so this is the only guard for that drift.
    setupFieldBindingsTable();

    $thrown = null;
    try {
        DB::connection('pgsql')->table('site.field_bindings')->insert([
            'id' => (string) Str::uuid(),
            'site_id' => (string) Str::uuid(),
            'field' => 'name',
            'source_key' => 'google_business',
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    } catch (QueryException $e) {
        $thrown = $e;
    }
    expect($thrown)->not->toBeNull();

    DB::connection('pgsql')->table('site.field_bindings')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => (string) Str::uuid(),
        'field' => 'name',
        'source_key' => 'manual',
        'priority' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::connection('pgsql')->table('site.field_bindings')->where('source_key', 'manual')->count())->toBe(1);
});
