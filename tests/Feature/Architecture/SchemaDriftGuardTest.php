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
