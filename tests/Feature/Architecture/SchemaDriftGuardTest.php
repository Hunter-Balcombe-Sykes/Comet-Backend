<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\SchemaDrift\DriftComparator;
use Tests\Support\SchemaDrift\Snapshot;
use Tests\Support\SchemaDrift\SqliteIntrospector;

/**
 * Schema-drift gate: the SQLite test schema must mirror the NOT NULL / CHECK
 * constraints of the real dev Postgres (schema-snapshot.json), so a write
 * that would violate a prod constraint can never again pass CI green.
 *
 * Pre-existing permissive columns are grandfathered in schema-drift-baseline.json.
 * To accept intentional new drift: SCHEMA_DRIFT_BASELINE=1 php artisan test --filter=SchemaDriftGuardTest
 * To refresh the Postgres snapshot: php scripts/launch-check/refresh-schema-snapshot.php
 */
const SNAPSHOT_PATH = __DIR__.'/../../../scripts/launch-check/schema-snapshot.json';
const BASELINE_PATH = __DIR__.'/../../../scripts/launch-check/schema-drift-baseline.json';

it('sqlite test schema mirrors dev Postgres constraints (or is baselined)', function () {
    // Build EVERY table the test suite knows how to build. The setup helpers
    // are global no-arg functions in tests/Pest.php named setup*Table/Tables.
    foreach (get_defined_functions()['user'] as $fn) {
        $short = str_contains($fn, '\\') ? substr($fn, strrpos($fn, '\\') + 1) : $fn;
        if (str_starts_with($short, 'setup') && (new ReflectionFunction($fn))->getNumberOfRequiredParameters() === 0) {
            $fn();
        }
    }

    $findings = (new DriftComparator)->compare(
        Snapshot::fromFile(SNAPSHOT_PATH),
        new SqliteIntrospector(DB::connection('pgsql')),
    );

    if (getenv('SCHEMA_DRIFT_BASELINE') === '1') {
        file_put_contents(BASELINE_PATH, json_encode($findings, JSON_PRETTY_PRINT)."\n");
        expect(true)->toBeTrue(); // baseline regenerated — always green

        return;
    }

    $baseline = is_file(BASELINE_PATH) ? json_decode(file_get_contents(BASELINE_PATH), true) : [];
    $new = array_values(array_diff($findings, $baseline));
    $fixed = array_values(array_diff($baseline, $findings));

    expect($new)->toBe([], sprintf(
        "NEW schema drift — these Postgres constraints are missing from the SQLite test schema in tests/Pest.php:\n  %s\n".
        "Fix: add the NOT NULL / CHECK to the matching setup*Table() helper (preferred), or if intentional run:\n".
        "  SCHEMA_DRIFT_BASELINE=1 php artisan test --filter=SchemaDriftGuardTest\n".
        'Also refresh the snapshot after schema changes: php scripts/launch-check/refresh-schema-snapshot.php',
        implode("\n  ", $new)
    ));

    if ($fixed !== []) {
        fwrite(STDERR, "\n[schema-drift] ".count($fixed)." baselined finding(s) now fixed — regenerate the baseline to lock them in.\n");
    }
});

it('the Postgres snapshot is not stale against supabase/migrations (#PARITY-1 G2)', function () {
    // Converts "someone remembers to refresh the snapshot" into an
    // enforceable check. Without this, the gate above only ever compares
    // against whatever was live the day someone last ran
    // refresh-schema-snapshot.php — a schema-drift finding is only as fresh
    // as that snapshot, and nothing forces a refresh. This is exactly how
    // the content/ingest/routing/catalog tables (added to SCHEMAS above,
    // #PARITY-1 G1) went unseen: three of the four #PARITY-1 tables were
    // never queried, and the fourth (content.items) was queried but the
    // snapshot predates the migration that added its CHECK.
    //
    // INERT BY DEFAULT (opt-in via SCHEMA_SNAPSHOT_STALENESS_GATE=1). As of
    // this commit, schema-snapshot.json's latest_migration is
    // 20260724150957 — it predates the entire content-platform rebuild
    // (20260727xxxxxx onward, including this unit's own CHECKs). Refreshing
    // it needs SUPABASE_ACCESS_TOKEN against dev Supabase, AND dev must
    // already have those migrations applied — it does not, as of
    // 2026-07-29. Turning this gate on unconditionally today would break the
    // schema-drift CI job for every future PR, for a reason nobody landing
    // unrelated work could fix from this repo alone. The logic is complete
    // and correct; flipping it on is a follow-up, not a "todo, someday":
    //   1. Apply the pending supabase/migrations/*.sql to dev.
    //   2. php scripts/launch-check/refresh-schema-snapshot.php
    //   3. Commit the refreshed schema-snapshot.json.
    //   4. Set SCHEMA_SNAPSHOT_STALENESS_GATE=1 in the schema-drift job's
    //      `env:` in .github/workflows/ci.yml.
    if (getenv('SCHEMA_SNAPSHOT_STALENESS_GATE') !== '1') {
        expect(true)->toBeTrue();

        return;
    }

    $migrationVersions = array_map(
        fn (string $path) => (int) explode('_', basename($path), 2)[0],
        glob(base_path('supabase/migrations/*.sql')) ?: []
    );
    $latestOnDisk = max($migrationVersions);
    $latestSnapshotted = (int) Snapshot::fromFile(SNAPSHOT_PATH)->latestMigration;

    expect($latestSnapshotted)->toBeGreaterThanOrEqual($latestOnDisk, sprintf(
        "schema-snapshot.json is STALE: latest_migration=%d but supabase/migrations/ has %d.\n".
        'Refresh: php scripts/launch-check/refresh-schema-snapshot.php (requires SUPABASE_ACCESS_TOKEN; '.
        'dev must have every migration up to %d applied first).',
        $latestSnapshotted, $latestOnDisk, $latestOnDisk
    ));
});
