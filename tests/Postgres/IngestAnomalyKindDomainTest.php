<?php

// SCHEMA-3 regression sentinel — THE REGRESSION THIS UNIT FOUND. The
// documented domain in supabase/migrations/20260727130000_ingest_schema.sql:184
// (delete_guard | shape | drift | stranded | schema) is STALE: RunExecutor.php
// :180 writes kind = 'projection' when a projection fails after a successful
// landing (see plan-unit-g.md §SCHEMA-3 — every one of dev's 13 live anomaly
// rows was 'projection'). The naive 5-value CHECK the finding text proposes
// would have aborted VALIDATE CONSTRAINT with SQLSTATE 23514 against real
// data, and in prod would 500 the exact insert meant to record the failure.
//
// This test binds the REAL migration file (20260729150004), not a hand-copy,
// so a future narrowing of the CHECK back to 5 values fails here first.

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS ingest');
    $pg->statement('DROP TABLE IF EXISTS ingest.anomalies');
    $pg->statement('CREATE TABLE ingest.anomalies (
        id       uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        kind     text NOT NULL,
        severity text NOT NULL DEFAULT \'warning\'
    )');

    applyAlterFromMigrationFile('20260729150004_anomalies_kind_check_not_valid.sql');
});

/** Strip BEGIN/COMMIT/SET LOCAL/comments and run the remaining statements verbatim. */
function applyAlterFromMigrationFile(string $filename): void
{
    $sql = (string) file_get_contents(base_path("supabase/migrations/{$filename}"));
    $sql = preg_replace('/--.*$/m', '', $sql) ?? $sql;

    $statements = array_filter(array_map('trim', explode(';', $sql)), function (string $s) {
        if ($s === '') {
            return false;
        }
        $upper = strtoupper($s);

        return ! str_starts_with($upper, 'BEGIN') && ! str_starts_with($upper, 'COMMIT') && ! str_starts_with($upper, 'SET LOCAL');
    });

    foreach ($statements as $statement) {
        DB::connection('pgsql')->statement($statement);
    }
}

it('accepts every kind literal the app actually writes', function (string $kind) {
    // delete_guard: app/Ingest/Landing/Lander.php:416
    // shape:        app/Ingest/Runtime/RunExecutor.php:128
    // stranded:     app/Ingest/Runtime/SourceScheduler.php:209
    // projection:   app/Ingest/Runtime/RunExecutor.php:180 — pinned by
    //               tests/Feature/Ingest/RunExecutorProjectionTest.php:101
    DB::connection('pgsql')->table('ingest.anomalies')->insert(['kind' => $kind]);

    expect(DB::connection('pgsql')->table('ingest.anomalies')->where('kind', $kind)->exists())->toBeTrue();
})->with(['delete_guard', 'shape', 'stranded', 'projection']);

it('accepts the two reserved-but-unwritten values', function (string $kind) {
    DB::connection('pgsql')->table('ingest.anomalies')->insert(['kind' => $kind]);

    expect(DB::connection('pgsql')->table('ingest.anomalies')->where('kind', $kind)->exists())->toBeTrue();
})->with(['drift', 'schema']);

it('rejects a kind outside the domain', function () {
    $thrown = null;
    try {
        DB::connection('pgsql')->table('ingest.anomalies')->insert(['kind' => 'nonsense']);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getCode())->toBe('23514');
});
