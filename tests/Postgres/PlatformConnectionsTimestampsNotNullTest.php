<?php

// #PARITY-1 regression sentinel. site.platform_connections.created_at/
// updated_at carried DEFAULT now() with no NOT NULL since the baseline
// (20260726000000_baseline_pilot.sql:1853-1854) — a DEFAULT never fires on an
// explicit NULL, so both columns were genuinely nullable, which is exactly
// the gap tests/Feature/Platforms/DueForRefreshScopeTest.php's old
// null-updated_at case exploited to prove scopeStrandedPending()'s
// whereNotNull('updated_at') branch. That clause was removed on 2026-07-30
// (it never selected differently — `updated_at < $cutoff` is NULL, not TRUE,
// for a NULL row), so what this file now pins is the DB constraint itself,
// not a code branch. This test binds the REAL migration files
// (20260729150016/150017/150018), not a hand-copy, so a future rollback of
// the constraint fails here first.
//
// Property (a) is why tests/Pest.php's SQLite stand-in mirrors the columns
// as `NOT NULL DEFAULT CURRENT_TIMESTAMP` rather than dropping the default —
// several insert sites across the suite omit both columns and rely on it.
// Property (b) is the DB-boundary replacement for the null-updated_at case
// removed from DueForRefreshScopeTest.php: it proves the state that scope's
// whereNotNull() defends against no longer exists once this migration
// applies, which is the premise the SQLite stand-in change rests on.

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS site');
    $pg->statement('DROP TABLE IF EXISTS site.platform_connections_pit_test');

    // Scaffold mirrors only the columns under test — same minimal-shape
    // discipline as SectionItemsItemFkTest / IngestAnomalyKindDomainTest.
    // Pre-migration shape: DEFAULT now(), no NOT NULL
    // (baseline_pilot.sql:1853-1854).
    $pg->statement('CREATE TABLE site.platform_connections_pit_test (
        id         uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        created_at timestamp with time zone DEFAULT now(),
        updated_at timestamp with time zone DEFAULT now()
    )');

    applyPconnTimestampsMigration('20260729150016_pconn_timestamps_check_not_valid.sql');
    applyPconnTimestampsMigration('20260729150017_backfill_pconn_timestamps.sql');
    applyPconnTimestampsMigration('20260729150018_pconn_timestamps_validate_and_not_null.sql');
});

/**
 * Strip BEGIN/COMMIT/SET LOCAL/comments and run the remaining statements
 * verbatim against the scaffold table, retargeted from the real table name
 * to the scoped test table so this can run repeatedly without touching
 * anything else in partna_test.
 */
function applyPconnTimestampsMigration(string $filename): void
{
    $sql = (string) file_get_contents(base_path("supabase/migrations/{$filename}"));
    $sql = preg_replace('/--.*$/m', '', $sql) ?? $sql;
    $sql = str_replace('"site"."platform_connections"', 'site.platform_connections_pit_test', $sql);

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

it('a: still accepts an insert that omits both timestamp columns, via DEFAULT now()', function () {
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.platform_connections_pit_test')->insert(['id' => $id]);

    $row = DB::connection('pgsql')->table('site.platform_connections_pit_test')->where('id', $id)->first();

    expect($row->created_at)->not->toBeNull();
    expect($row->updated_at)->not->toBeNull();
});

it('b: rejects an explicit null updated_at — the state scopeStrandedPending defends against no longer exists', function () {
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.platform_connections_pit_test')->insert(['id' => $id]);

    $thrown = null;
    try {
        DB::connection('pgsql')->table('site.platform_connections_pit_test')->where('id', $id)->update(['updated_at' => null]);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getCode())->toBe('23502');
});

it('c: rejects an explicit null created_at the same way', function () {
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.platform_connections_pit_test')->insert(['id' => $id]);

    $thrown = null;
    try {
        DB::connection('pgsql')->table('site.platform_connections_pit_test')->where('id', $id)->update(['created_at' => null]);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getCode())->toBe('23502');
});
