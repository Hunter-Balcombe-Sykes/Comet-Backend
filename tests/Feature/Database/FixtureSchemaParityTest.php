<?php

use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureFlags\FeatureFlagTestCase;
use Tests\Support\SchemaDrift\MigrationColumnReplay;
use Tests\Support\SchemaDrift\SqliteIntrospector;

/*
|--------------------------------------------------------------------------
| Fixture Schema Parity Guard
|--------------------------------------------------------------------------
| A hand-written SQLite fixture (tests/Pest.php setup*Table() helpers, or a
| test-local *TestCase::boot()) can invent a column that no migration ever
| created. SQLite happily builds it, and every test asserting against that
| fixture goes on lying green — that's exactly how a phantom `brand_id` on
| core.feature_flag_overrides certified a controller re-read that 500'd on
| real Postgres (42703) for two months (#FFLAG-1).
|
| This guard replays supabase/migrations/*.sql (MigrationColumnReplay — the
| same DB-independent parser DataExportCoverageTest uses) and diffs it
| against whatever DDL the fixture helpers actually executed, via SQLite's
| own PRAGMA table_info. Both sides are read at runtime, not hand-copied, so
| this can't itself drift out of sync with the fixtures it's checking.
|
| Direction: EXTRA fixture columns (columns with no migration backing) are a
| hard failure. MISSING fixture columns are not — fixtures are deliberately
| minimal subsets of the real schema (see setupUsersTable()'s own docblock),
| so failing on "the fixture doesn't have every real column" would be pure
| noise. Missing columns are still logged to STDERR for visibility, the same
| pattern SchemaDriftGuardTest uses for its own baseline diff.
|
| Scope is a curated allowlist (FIXTURE_PARITY_TABLES), not every fixture
| table in the suite — there is a known long tail (e.g. brand.brand_profiles
| is built by other *TestCase helpers for a schema with no migration at all)
| that needs its own triage pass before this guard can safely cover it.
| Widening the allowlist is a one-line edit once that triage happens.
*/

const FIXTURE_PARITY_TABLES = [
    'core.feature_flag_overrides',  // #FFLAG-1: a phantom brand_id here masked a live 42703 for two months.
    'core.feature_flags',
    'core.users',                   // #FFLAG-1 sibling: FeatureFlagTestCase also invented professional_type.
];

it('every hand-written fixture column has a migration backing it', function () {
    // Boots both the canonical helper and its alias — after the #FFLAG-1 fix
    // FeatureFlagTestCase::boot() calls setupFeatureFlagsTable() itself, so
    // this is redundant (CREATE TABLE IF NOT EXISTS no-ops), but calling both
    // documents that convergence rather than assuming it silently.
    setupFeatureFlagsTable();
    FeatureFlagTestCase::boot();

    $real = MigrationColumnReplay::tables();
    $sqlite = new SqliteIntrospector(DB::connection('pgsql'));

    $violations = [];
    $missing = [];
    $unchecked = [];

    foreach (FIXTURE_PARITY_TABLES as $qualified) {
        [$schema, $table] = explode('.', $qualified);

        $fixtureColumns = $sqlite->columns($schema, $table);
        $realColumns = $real[$qualified] ?? [];

        // Either side coming back empty makes the diff below vacuously pass — and
        // neither errors: PRAGMA table_info() on a table that doesn't exist returns
        // an empty set, and a misspelled allowlist key just misses the replay map.
        // That is this guard silently guarding nothing, i.e. the very failure mode
        // it exists to catch (#TESTFX-1). Fail loudly instead of skipping.
        if ($fixtureColumns === [] || $realColumns === []) {
            $unchecked[] = $qualified.($fixtureColumns === []
                ? ' — no fixture table (does this test call the setup* helper that builds it?)'
                : ' — no migration table (is the allowlist key misspelled?)');

            continue;
        }

        foreach ($fixtureColumns as $col) {
            if (! isset($realColumns[$col])) {
                $violations[] = "{$qualified}.{$col}";
            }
        }

        foreach (array_keys($realColumns) as $col) {
            if (! in_array($col, $fixtureColumns, true)) {
                $missing[] = "{$qualified}.{$col}";
            }
        }
    }

    expect($unchecked)->toBe([], "These allowlisted tables were never actually compared, so this guard verified nothing for them:\n  - ".implode("\n  - ", $unchecked)."\n\nA silently-skipped table is the failure mode this guard exists to prevent. Fix the wiring or remove the entry from FIXTURE_PARITY_TABLES.");

    if ($missing !== []) {
        fwrite(STDERR, "\n[fixture-parity] real migration columns absent from the fixture (informational only, not a failure):\n  - ".implode("\n  - ", $missing)."\n");
    }

    expect($violations)->toBe([], "These hand-written SQLite fixture columns have no backing column in supabase/migrations/:\n  - ".implode("\n  - ", $violations)."\n\nDo NOT add the column to a migration to silence this — either the fixture helper is wrong (fix it in tests/Pest.php or the relevant *TestCase), or the column was genuinely dropped from prod and the fixture is stale. Verify against supabase/migrations/.");
});
