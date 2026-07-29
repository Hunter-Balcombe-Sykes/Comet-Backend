<?php

// LIFE-5: App\Ingest\Runtime\RunExecutor::recordStreamFailure() previously
// read consecutive_failures, incremented it in PHP, then wrote it back — a
// classic lost-update race under concurrent failures on the same stream. The
// fix moves the increment into the UPDATE itself (`consecutive_failures + 1`
// in SQL), so no read-modify-write gap exists for two concurrent callers to
// race through.
//
// Fork only: the race is between the read and the write of a value with no
// application-level seam to inject into — genuine concurrent transactions on
// separate connections are the only way to prove a lost update was possible
// and is now closed.
//
// ingest.sources/ingest.streams DDL copied from LanderBatchLandingTest.php's
// beforeEach (deliberately no core.users FK — same precedent).

use App\Ingest\Runtime\RunExecutor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS ingest');
    $pg->statement('DROP TABLE IF EXISTS ingest.streams CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.sources CASCADE');

    $pg->statement('CREATE TABLE ingest.sources (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL,
        connection_id uuid,
        source_key text NOT NULL,
        surface_key text NOT NULL,
        identifier text NOT NULL,
        cost_units integer NOT NULL DEFAULT 1,
        min_interval_secs integer NOT NULL DEFAULT 3600,
        max_interval_secs integer NOT NULL DEFAULT 604800,
        change_rate double precision NOT NULL DEFAULT 0.5,
        next_attempt_at timestamptz NOT NULL DEFAULT now(),
        last_run_at timestamptz,
        visibility double precision NOT NULL DEFAULT 1.0,
        in_flight_since timestamptz,
        in_flight_run_id uuid,
        health text NOT NULL DEFAULT \'ok\',
        consecutive_failures integer NOT NULL DEFAULT 0,
        auto_sync boolean NOT NULL DEFAULT true,
        scope text NOT NULL DEFAULT \'all\',
        scope_n integer,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');

    $pg->statement('CREATE TABLE ingest.streams (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        source_id uuid NOT NULL REFERENCES ingest.sources (id) ON DELETE CASCADE,
        stream_name text NOT NULL,
        cursor jsonb,
        coverage jsonb,
        observed_schema jsonb NOT NULL DEFAULT \'{}\'::jsonb,
        schema_hash text,
        health text NOT NULL DEFAULT \'ok\',
        consecutive_failures integer NOT NULL DEFAULT 0,
        suppressed_until timestamptz,
        guard_tripped_at timestamptz,
        run_seq bigint NOT NULL DEFAULT 0,
        created_at timestamptz NOT NULL DEFAULT now(),
        updated_at timestamptz NOT NULL DEFAULT now()
    )');

    $this->sourceId = (string) Str::uuid();
    $pg->table('ingest.sources')->insert([
        'id' => $this->sourceId,
        'user_id' => (string) Str::uuid(),
        'source_key' => 'test-source',
        'surface_key' => 'releases',
        'identifier' => 'test',
    ]);

    $this->streamId = (string) Str::uuid();
    $pg->table('ingest.streams')->insert([
        'id' => $this->streamId,
        'source_id' => $this->sourceId,
        'stream_name' => 'releases',
        'consecutive_failures' => 0,
    ]);
});

afterAll(function () {
    $pg = DB::connection('pgsql');
    foreach (['ingest.streams', 'ingest.sources'] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }
});

it('never loses an increment under 8 concurrent processes each recording 5 failures (real fork)', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl_fork is not available in this runtime');
    }

    $pg = DB::connection('pgsql');
    $streamId = $this->streamId;
    $childCount = 8;
    $callsPerChild = 5;

    $startAt = microtime(true) + 0.2; // shared gate
    $pids = [];

    for ($i = 0; $i < $childCount; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            DB::purge('pgsql');
            DB::reconnect('pgsql');

            $sleepUs = (int) max(0, ($startAt - microtime(true)) * 1_000_000);
            usleep($sleepUs);

            $executor = app(RunExecutor::class);
            $reflection = new ReflectionMethod($executor, 'recordStreamFailure');
            $reflection->setAccessible(true);

            for ($call = 0; $call < $callsPerChild; $call++) {
                $reflection->invoke($executor, $streamId, 'error');
            }

            exit(0);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $stream = $pg->table('ingest.streams')->where('id', $streamId)->first();

    // THE property: consecutive_failures is EXACTLY childCount * callsPerChild.
    // Pre-fix (read, increment in PHP, write back), a lost update under
    // concurrency makes this strictly LESS — and a sequential test cannot
    // distinguish the buggy implementation from the fixed one at all, only
    // genuine concurrent writers can.
    expect((int) $stream->consecutive_failures)->toBe($childCount * $callsPerChild);

    // Post-increment-derived fields, evaluated on the final (correct) count.
    expect($stream->suppressed_until)->not->toBeNull();
    expect($stream->health)->toBe('unavailable');
});
