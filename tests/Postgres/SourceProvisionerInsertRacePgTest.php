<?php

// #LIFE-14 PG-lane counterpart to tests/Feature/Ingest/SourceProvisionerInsertRaceTest.php.
// That file proves the BEHAVIOUR (insertOrIgnore resolves the sqlite stand-in's
// UNIQUE (connection_id, source_key) to 0 rows, and the caller falls through to
// the update path). Only here is the conflict a real 23505 on the real
// sources_unique_per_connection constraint (supabase/migrations/
// 20260727130000_ingest_schema.sql:57), and only here does "the connection is
// still usable afterwards" mean anything — SQLite has no 25P02 (aborted
// transaction) state to poison in the first place.
//
// ingest.sources.user_id/connection_id carry real FKs to core.users/
// site.platform_connections in the shipped schema, but SourceProvisioner::sync()
// never queries either table — it only ever touches ingest.sources — so this
// file deliberately elides both FKs (same reasoning as
// tests/Postgres/SourceSchedulerConcurrencyTest.php's docblock: staying FK-free
// keeps this out of NoLocalCanonicalTableDdlTest's canonical-table scan
// entirely) and drives sync() with an unsaved, in-memory IntegrationConnection
// model rather than a persisted one.
//
// Deterministic injection, modelled on
// tests/Postgres/SourceIntentUpsertRaceTest.php:150-259: a DB::listen hook
// fires the instant sync()'s own pre-read SELECT against ingest.sources for
// this connection executes (by then that statement's own result is already
// fixed — Laravel fires the query-executed event after execution), and on a
// SECOND, independently-resolved Postgres connection, commits a competing row
// for the exact same (connection_id, source_key) pair. The caller's own
// insertOrIgnore then races straight into it.

use App\Ingest\SourceProvisioner;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS ingest');
    $pg->statement('DROP TABLE IF EXISTS ingest.sources CASCADE');

    // Verbatim column list + the real named constraint from
    // supabase/migrations/20260727130000_ingest_schema.sql:28-58, FKs elided
    // (see file docblock above).
    $pg->statement('CREATE TABLE ingest.sources (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL,
        connection_id uuid,
        source_key text NOT NULL,
        surface_key text NOT NULL,
        identifier text NOT NULL,
        selection_ref text,
        cost_units integer NOT NULL DEFAULT 1,
        min_interval_secs integer NOT NULL DEFAULT 3600,
        max_interval_secs integer NOT NULL DEFAULT 604800,
        change_rate double precision NOT NULL DEFAULT 0.5,
        next_attempt_at timestamptz NOT NULL DEFAULT now(),
        last_run_at timestamptz,
        visibility double precision NOT NULL DEFAULT 1.0,
        in_flight_since timestamptz,
        in_flight_run_id uuid,
        health text NOT NULL DEFAULT \'ok\'
            CHECK (health IN (\'ok\', \'degraded\', \'unavailable\', \'shape\', \'suppressed\', \'dead\')),
        consecutive_failures integer NOT NULL DEFAULT 0,
        auto_sync boolean NOT NULL DEFAULT true,
        scope text NOT NULL DEFAULT \'all\' CHECK (scope IN (\'all\', \'latest_n\')),
        scope_n integer,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now(),
        CONSTRAINT sources_unique_per_connection UNIQUE (connection_id, source_key)
    )');
});

afterAll(function () {
    DB::connection('pgsql')->statement('DROP TABLE IF EXISTS ingest.sources CASCADE');
});

it('falls through to the update path instead of raising 23505 when a competing insert commits between the pre-read and the insert', function () {
    // A genuinely SEPARATE Postgres connection to the same database.
    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);

    $userId = (string) Str::uuid();
    $connectionId = (string) Str::uuid();

    // Unsaved, in-memory model — sync() never queries site.platform_connections,
    // so nothing here needs to be persisted for the race to be real.
    $connection = (new IntegrationConnection)->forceFill([
        'id' => $connectionId,
        'user_id' => $userId,
        'platform' => 'bandcamp',
        'payload' => ['url' => 'https://real.bandcamp.com'],
        'is_active' => true,
    ]);

    $injected = false;
    DB::listen(function ($query) use (&$injected, $connectionId, $userId) {
        if ($injected) {
            return; // fires exactly once.
        }
        if (! str_contains($query->sql, 'sources')) {
            return;
        }
        if (! in_array($connectionId, $query->bindings, true)) {
            return;
        }

        $injected = true;

        // A concurrent worker that already committed a row for this EXACT
        // (connection_id, source_key) pair, on an independently resolved
        // connection — this fires right after sync()'s own pre-read SELECT
        // already found nothing, so the caller's own insertOrIgnore below
        // races straight into it.
        DB::connection('pgsql_second')->table('ingest.sources')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'connection_id' => $connectionId,
            'source_key' => 'bandcamp',
            'surface_key' => 'bandcamp.artist',
            'identifier' => 'https://winner-placeholder.bandcamp.com',
        ]);
    });

    // Property 1: does NOT throw (pre-fix: UniqueConstraintViolationException
    // on the real sources_unique_per_connection constraint).
    $result = app(SourceProvisioner::class)->sync($connection);

    expect($injected)->toBeTrue('the race was never injected — every assertion below is vacuous');

    // Property 2: the loser reports the update outcome, never 'created' —
    // the money-adjacent gate (Q1): a status rename here would make a paid
    // connector's maybeRunEagerly() gate see 'created' for a row this call
    // did not insert.
    expect($result)->toBe(['status' => 'updated', 'source_key' => 'bandcamp']);

    // Property 3: exactly one row for the pair.
    $rows = DB::connection('pgsql')->table('ingest.sources')->where('connection_id', $connectionId)->get();
    expect($rows)->toHaveCount(1);

    // Property 4: the loser's own identifier won — falling through to the
    // update path actually lands this caller's identity, not the winner's.
    expect($rows[0]->identifier)->toBe('https://real.bandcamp.com');

    // Property 5: the connection is still usable afterwards — the 25P02
    // non-poisoning proof. insertOrIgnore raises no exception at all (ON
    // CONFLICT DO NOTHING is not an error), so unlike a caught 23505 inside a
    // transaction, nothing here could have aborted it.
    expect(DB::connection('pgsql')->select('select 1 as one')[0]->one)->toBe(1);

    DB::purge('pgsql_second');
});
