<?php

// Regression sentinel for the field_bindings_manual_priority CHECK
// (supabase/migrations/20260728150000_field_bindings.sql:43-46) — the schema
// invariant "manual is priority 0 and priority 0 is manual" that
// FieldBindingResolver's "manual always wins" ordering rests on. Nothing in
// application code re-validates this (FieldBindingSeeder.php hardcodes
// PLATFORM_PRIORITY = 10 for every non-manual source and never checks it),
// so the CHECK is the only thing standing between a bad row and a silent
// "manual always wins" bypass. Real PL/pgSQL CHECK semantics, so this can
// only run for real against Postgres — see Tests\PostgresTestCase for the
// skip-without-Postgres guard. Runs via `composer test:pg` / phpunit.pg.xml.
//
// Minimal self-provisioned schema (like tests/Postgres/GalleryMax6TriggerTest
// .php): no FK to site.sites, no RLS, no GRANT — those roles/tables don't
// exist in the CI throwaway DB and aren't what's under test.
//
// The clause is EXTRACTED from the migration file at runtime rather than
// retyped, so this test binds the actual migration text instead of a
// hand-copied stand-in that could drift from it silently.

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

function fieldBindingsCheckClause(): string
{
    $sql = (string) file_get_contents(base_path('supabase/migrations/20260728150000_field_bindings.sql'));
    preg_match('/CONSTRAINT\s+"field_bindings_manual_priority"\s+CHECK\s*\((.*?)\n\s*\)/s', $sql, $m);
    expect($m[1] ?? null)->not->toBeNull('field_bindings_manual_priority CHECK not found in the migration.');

    return $m[1];
}

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS site');
    $pg->statement('DROP TABLE IF EXISTS site.field_bindings');
    $pg->statement('CREATE TABLE site.field_bindings (
        id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        site_id      uuid NOT NULL,
        field        text NOT NULL,
        source_key   text NOT NULL,
        priority     integer NOT NULL DEFAULT 100,
        mode         text NOT NULL DEFAULT \'fill_blank\' CHECK (mode IN (\'overwrite\', \'fill_blank\')),
        is_enabled   boolean NOT NULL DEFAULT true,
        CONSTRAINT field_bindings_manual_priority CHECK ('.fieldBindingsCheckClause().')
    )');
});

function insertFieldBinding(string $sourceKey, ?int $priority, array $overrides = []): void
{
    $row = array_merge([
        'id' => (string) Str::uuid(),
        'site_id' => (string) Str::uuid(),
        'field' => 'name',
        'source_key' => $sourceKey,
    ], $overrides);

    if ($priority !== null) {
        $row['priority'] = $priority;
    }

    DB::connection('pgsql')->table('site.field_bindings')->insert($row);
}

it('accepts manual at priority zero', function () {
    insertFieldBinding('manual', 0);

    expect(DB::connection('pgsql')->table('site.field_bindings')->count())->toBe(1);
});

it('rejects manual at any non-zero priority', function (int $priority) {
    $thrown = null;
    try {
        insertFieldBinding('manual', $priority);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getCode())->toBe('23514');
})->with([1, 5, 100]);

it('rejects a manual row that falls back to the column default', function () {
    // The realistic accident: a seeder that forgets to set priority for a
    // manual row falls through to DEFAULT 100 (migration:35) — the CHECK
    // must still catch it.
    $thrown = null;
    try {
        insertFieldBinding('manual', null);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getCode())->toBe('23514');
});

it('rejects a non-manual source claiming priority zero', function () {
    // The exact "manual always wins" bypass a grep-name-only test cannot
    // catch: changing "priority" > 0 to >= 0 would let a non-manual source
    // tie manual for the top slot.
    $thrown = null;
    try {
        insertFieldBinding('google_business', 0);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getCode())->toBe('23514');
});

it('rejects a non-manual source at a negative priority', function () {
    $thrown = null;
    try {
        insertFieldBinding('google_business', -1);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getCode())->toBe('23514');
});

it("accepts a non-manual source at the seeder's real priority", function () {
    // Matches FieldBindingSeeder::PLATFORM_PRIORITY (10) — any over-tightening
    // of the CHECK that would break the actual seeder must fail here.
    insertFieldBinding('google_business', 10);

    expect(DB::connection('pgsql')->table('site.field_bindings')->count())->toBe(1);
});
