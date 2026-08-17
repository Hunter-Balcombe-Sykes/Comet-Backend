<?php

// #TEST-6: App\Ingest\Runtime\SourceScheduler::claimDue() is the system's
// ONLY run lock (see the class's own docblock — deliberately not
// ShouldBeUnique or Cache::lock). Its mutual-exclusion property was previously
// proven only SEQUENTIALLY (tests/Feature/Ingest/SourceSchedulerTest.php makes
// two calls in a row against one in-memory SQLite connection), which proves
// in-memory coherence only, not that the conditional
// `UPDATE ... WHERE in_flight_since IS NULL` actually serialises concurrent
// transactions. Note: this is NOT `FOR UPDATE SKIP LOCKED` — it is a plain
// conditional UPDATE, correct under READ COMMITTED because only one
// concurrent UPDATE can ever match `in_flight_since IS NULL` for a given row;
// the second arrives after the first's row lock commits and sees a non-NULL
// value.
//
// Fork only, no deterministic-injection variant: the race window here is
// INSIDE a single UPDATE statement, with no application-level seam to inject
// into (unlike EffectLedger's pre-read/INSERT gap) — genuine concurrent
// transactions on separate connections are the only way to prove this.
//
// ingest.sources.user_id is a bare uuid with NO FK to core.users (deliberately
// — this file only exercises ingest.sources/ingest.runs, and staying FK-free
// keeps this out of NoLocalCanonicalTableDdlTest's canonical-table scan
// entirely, per LanderBatchLandingTest's precedent).

use App\Ingest\Runtime\SourceScheduler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS ingest');
    $pg->statement('DROP TABLE IF EXISTS ingest.claim_probe CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.runs CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.sources CASCADE');

    $pg->statement('CREATE TABLE ingest.sources (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        user_id uuid NOT NULL,
        connection_id uuid,
        source_key text NOT NULL DEFAULT \'bandcamp\',
        surface_key text NOT NULL DEFAULT \'music\',
        identifier text NOT NULL DEFAULT \'x\',
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

    // Minimal shape — only the columns scoreDue()'s join/group-by reads.
    $pg->statement('CREATE TABLE ingest.runs (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        source_id uuid NOT NULL,
        started_at timestamptz NOT NULL DEFAULT now(),
        cost_claimed integer NOT NULL DEFAULT 0
    )');

    $pg->statement('CREATE TABLE ingest.claim_probe (
        id bigserial PRIMARY KEY,
        child_idx integer NOT NULL,
        source_id uuid NOT NULL,
        run_id uuid NOT NULL
    )');
});

afterAll(function () {
    $pg = DB::connection('pgsql');
    foreach (['ingest.claim_probe', 'ingest.runs', 'ingest.sources'] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }
});

it('claimOne lets exactly one of 8 concurrent processes win the same source (real fork)', function () {
    // The eager-on-connect trigger (Manifest::runsEagerlyOnConnect) claims a
    // KNOWN row rather than scoring for due ones, so it takes a different code
    // path into the same conditional UPDATE. It needs its own proof: an
    // instagram source is CostClass::Actor, so a second winner here is a second
    // paid Apify call that the EffectLedger settles per digest and cannot
    // refund. SQLite cannot demonstrate this — its concurrency model does not
    // exercise READ COMMITTED row locking.
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl_fork is not available in this runtime');
    }

    $pg = DB::connection('pgsql');
    $childCount = 8;

    $sourceId = (string) Str::uuid();
    $pg->table('ingest.sources')->insert([
        'id' => $sourceId,
        'user_id' => (string) Str::uuid(),
        'source_key' => 'instagram',
        'surface_key' => 'instagram.profile',
        'identifier' => 'tobiasbalcombe',
        // The row the scheduler is BARRED from claiming — which is precisely
        // why claimOne() must not consult auto_sync.
        'auto_sync' => false,
        'cost_units' => 50,
    ]);

    $startAt = microtime(true) + 0.2;
    $pids = [];

    for ($i = 0; $i < $childCount; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            DB::purge('pgsql');
            DB::reconnect('pgsql');

            usleep((int) max(0, ($startAt - microtime(true)) * 1_000_000));

            $runId = (string) Str::uuid();

            try {
                // Only WINNERS record a row — a loser writing nothing is the
                // assertion. Sentinel on throw, so a crashed child cannot look
                // identical to a child that simply lost the race.
                if ((new SourceScheduler)->claimOne($sourceId, $runId)) {
                    DB::connection('pgsql')->table('ingest.claim_probe')->insert([
                        'child_idx' => $i,
                        'source_id' => $sourceId,
                        'run_id' => $runId,
                    ]);
                }
            } catch (Throwable $e) {
                DB::connection('pgsql')->table('ingest.claim_probe')->insert([
                    'child_idx' => $i,
                    'source_id' => '00000000-0000-0000-0000-000000000000',
                    'run_id' => $runId,
                ]);
                fwrite(STDERR, "child {$i} exception: {$e->getMessage()}\n");
            }

            exit(0);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $probe = DB::connection('pgsql')->table('ingest.claim_probe')->get();

    expect($probe->where('source_id', '00000000-0000-0000-0000-000000000000'))
        ->toHaveCount(0, 'A child hit an exception instead of claiming.');

    // Exactly one winner — not zero (which "nobody ever wins" would also
    // satisfy), and not more than one.
    expect($probe)->toHaveCount(1);

    // Pin WHY the other seven lost: the row is claimed, and it is claimed by
    // the child that believes it won. A test that only counted winners would
    // pass if claimOne() returned true for everyone and the UPDATE silently
    // no-opped.
    $row = $pg->table('ingest.sources')->where('id', $sourceId)->first();
    expect($row->in_flight_since)->not->toBeNull()
        ->and($row->in_flight_run_id)->toBe($probe[0]->run_id);
});

it('never double-claims a source across 8 concurrent processes, and claims all of them between the 8 (25 sources, real fork)', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl_fork is not available in this runtime');
    }

    $pg = DB::connection('pgsql');
    $sourceCount = 25;
    $childCount = 8;

    // Identical, "never run" sources (score is deterministic and equal for
    // all — no clock sensitivity to worry about): last_run_at NULL gives the
    // maximal staleness score, so candidate selection order carries no
    // meaning here — every child sees the same 25 candidates.
    $sourceIds = [];
    foreach (range(1, $sourceCount) as $i) {
        $id = (string) Str::uuid();
        $sourceIds[] = $id;
        $pg->table('ingest.sources')->insert([
            'id' => $id,
            'user_id' => (string) Str::uuid(),
            'source_key' => 'bandcamp',
            'surface_key' => 'music',
            'identifier' => "concurrency-test-{$i}",
            'cost_units' => 1,
            'visibility' => 1.0,
            'change_rate' => 0.5,
            'next_attempt_at' => now()->subMinute(),
            'last_run_at' => null,
        ]);
    }

    $startAt = microtime(true) + 0.2; // shared gate — not a guess, a real wait-until
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

            $runId = (string) Str::uuid();

            try {
                $claimed = (new SourceScheduler)->claimDue($sourceCount, $runId);

                foreach ($claimed as $row) {
                    DB::connection('pgsql')->table('ingest.claim_probe')->insert([
                        'child_idx' => $i,
                        'source_id' => $row['id'],
                        'run_id' => $runId,
                    ]);
                }
            } catch (Throwable $e) {
                // Surface as a claim_probe row with a sentinel source_id so
                // the parent can see something went wrong rather than a
                // silently missing child (a bare fatal here would otherwise
                // just look identical to "claimed nothing").
                DB::connection('pgsql')->table('ingest.claim_probe')->insert([
                    'child_idx' => $i,
                    'source_id' => '00000000-0000-0000-0000-000000000000',
                    'run_id' => $runId,
                ]);
                fwrite(STDERR, "child {$i} exception: {$e->getMessage()}\n");
            }

            exit(0);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $probe = DB::connection('pgsql')->table('ingest.claim_probe')->get();

    expect($probe->where('source_id', '00000000-0000-0000-0000-000000000000'))->toHaveCount(0, 'A child hit an exception instead of claiming.');

    // THE mutual-exclusion proof: no source claimed twice.
    expect($probe->pluck('source_id')->unique())->toHaveCount($probe->count());

    // Paired with the above, this is what makes the proof load-bearing: a
    // conditional UPDATE that always lost the race would ALSO pass the
    // no-double-claim assertion (by claiming nothing at all). Nothing lost.
    expect($probe->count())->toBe($sourceCount);
    expect($probe->pluck('source_id')->unique()->sort()->values()->all())->toBe(collect($sourceIds)->sort()->values()->all());

    // Per source: the row's lock agrees with who thinks they won.
    foreach ($probe as $claim) {
        $row = $pg->table('ingest.sources')->where('id', $claim->source_id)->first();
        expect($row->in_flight_run_id)->toBe($claim->run_id);
        expect($row->in_flight_since)->not->toBeNull();
    }

    // Soft witness only (never pass/fail) — real overlap is expected but not
    // guaranteed by any single run, so this cannot be allowed to flake red.
    $distinctChildren = $probe->pluck('child_idx')->unique()->count();
    if ($distinctChildren <= 1) {
        fwrite(STDERR, "[SourceSchedulerConcurrencyTest] only {$distinctChildren} distinct child claimed anything — no observed overlap this run.\n");
    }
});
