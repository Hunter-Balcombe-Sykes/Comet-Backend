<?php

// SCHEMA-2 regression sentinel. ingest.effects is the charge-once MONEY
// ledger (C6) — supabase/migrations/20260727130000_ingest_schema.sql:162
// documented `-- http | actor | api | ai` for `kind` but enforced nothing.
// This test binds the REAL migration file (20260729150002), not a
// hand-copy, so a future narrowing or widening of the domain is caught here.

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS ingest');
    $pg->statement('DROP TABLE IF EXISTS ingest.effects');
    $pg->statement('CREATE TABLE ingest.effects (
        digest text PRIMARY KEY,
        kind   text NOT NULL,
        status text NOT NULL DEFAULT \'ok\'
    )');

    applyAlterFromEffectsMigration('20260729150002_effects_kind_check_not_valid.sql');
});

/** Strip BEGIN/COMMIT/SET LOCAL/comments and run the remaining statements verbatim. */
function applyAlterFromEffectsMigration(string $filename): void
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

it('accepts every documented kind', function (string $kind) {
    // actor: {Doordash,UberEats,Square}MenuConnector + InstagramConnector
    // api:   GoogleBusinessConnector.php:117
    // http:  written by the EffectLedger test suite
    // ai:    reserved by the column comment, unused today
    DB::connection('pgsql')->table('ingest.effects')->insert([
        'digest' => hash('sha256', $kind),
        'kind' => $kind,
    ]);

    expect(DB::connection('pgsql')->table('ingest.effects')->where('kind', $kind)->exists())->toBeTrue();
})->with(['http', 'actor', 'api', 'ai']);

it('rejects a kind outside the money ledger domain', function () {
    $thrown = null;
    try {
        DB::connection('pgsql')->table('ingest.effects')->insert([
            'digest' => hash('sha256', 'apify'),
            'kind' => 'apify',
        ]);
    } catch (QueryException $e) {
        $thrown = $e;
    }

    expect($thrown)->not->toBeNull();
    expect($thrown->getCode())->toBe('23514');
});
