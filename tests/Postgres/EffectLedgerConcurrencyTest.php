<?php

// #TEST-7: App\Ingest\Runtime\EffectLedger::once() charge-once has NO
// concurrent test (tests/Feature/Ingest/EffectLedgerTest.php's 8 cases are all
// single-threaded). The premise was traced and confirmed: `once()` is
// pre-read -> INSERT -> catch -> re-read -> verdictFor(), racing on the real
// `digest text PRIMARY KEY` constraint from
// supabase/migrations/20260727130000_ingest_schema.sql. That race is
// genuinely constraint-bound (23505) and invisible under the SQLite mirror,
// which has no notion of a second, independently-committing connection.
//
// One sub-item of the finding was STALE and is deliberately NOT re-added here:
// testing an abandoned (claimed-but-unsettled-past-900s) row is already
// covered by EffectLedgerTest's "marks a long-abandoned claim as abandoned…"
// case.
//
// Two mechanisms, both proving the SAME invariant (charge-once, never twice):
//
//   1. Deterministic race injection (no fork, zero timing dependence): a
//      DB::listen hook fires the instant once()'s pre-read SELECT runs (by
//      then that SELECT's own result — "nothing exists yet" — is already
//      fixed, Laravel dispatches the query event AFTER execution), and on a
//      SECOND, independently resolved Postgres connection, inserts a fully
//      SETTLED competing row simulating a worker that already completed this
//      exact effect. The caller's own INSERT then hits a REAL 23505. A second
//      connection is essential — one connection cannot both be inside once()
//      and concurrently insert the conflicting row.
//   2. A real N-way pcntl_fork race: 8 children call once() on the SAME
//      digest, each on its own freshly-reconnected Postgres connection,
//      released against a shared start gate.
//
// In both, the closure is NOT a no-op: it inserts into a scratch
// ingest.charge_probe table. That row IS the charge — the assertion is on
// money moving, not merely "no exception was thrown".

use App\Ingest\Runtime\EffectLedger;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS ingest');
    $pg->statement('DROP TABLE IF EXISTS ingest.effect_probe CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.charge_probe CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.effects CASCADE');

    // Verbatim shape from the real migration — digest is the PRIMARY KEY that
    // makes the race real.
    $pg->statement('CREATE TABLE ingest.effects (
        digest text PRIMARY KEY,
        run_id uuid,
        source_id uuid,
        kind text NOT NULL,
        cost_tag text,
        cost_units integer NOT NULL DEFAULT 0,
        claimed_at timestamptz NOT NULL DEFAULT now(),
        settled_at timestamptz,
        status text NOT NULL DEFAULT \'claimed\'
            CHECK (status IN (\'claimed\', \'ok\', \'failed\', \'refused\', \'abandoned\')),
        body_ref text,
        meta jsonb NOT NULL DEFAULT \'{}\'::jsonb
    )');

    // Scratch probes — no canonical DDL, no core.users.
    $pg->statement('CREATE TABLE ingest.charge_probe (
        id bigserial PRIMARY KEY,
        source text NOT NULL,
        created_at timestamptz NOT NULL DEFAULT now()
    )');

    $pg->statement('CREATE TABLE ingest.effect_probe (
        id bigserial PRIMARY KEY,
        child_idx integer NOT NULL,
        status text NOT NULL,
        cached boolean NOT NULL,
        result_json text
    )');
});

afterAll(function () {
    $pg = DB::connection('pgsql');
    foreach (['ingest.effect_probe', 'ingest.charge_probe', 'ingest.effects'] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }
});

it('charges an effect exactly once when a second connection commits the winning row between the pre-read and the INSERT (deterministic injection — the actual #TEST-7 regression)', function () {
    // A genuinely SEPARATE Postgres connection to the same database — not the
    // same PDO handle the primary caller is using.
    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);

    $digest = hash('sha256', 'race-inject-'.Str::uuid());
    $winnerResult = ['probe' => 'winner-result'];

    $injected = false;
    DB::listen(function ($query) use ($digest, $winnerResult, &$injected) {
        if ($injected) {
            return; // fires exactly once — the "unregisters itself" step.
        }
        if (! str_contains($query->sql, 'ingest') || ! str_contains($query->sql, 'effects')) {
            return;
        }
        if (! in_array($digest, $query->bindings, true)) {
            return;
        }

        $injected = true;

        // Simulate a concurrent worker that already completed and settled
        // this EXACT effect, on an independently resolved connection — this
        // happens to run right after the caller's own pre-read already found
        // nothing (Laravel fires the query-executed event after the query's
        // result is already fixed), so the caller's own INSERT below races
        // straight into it.
        DB::connection('pgsql_second')->table('ingest.effects')->insert([
            'digest' => $digest,
            'kind' => 'http',
            'cost_tag' => 'race_probe',
            'cost_units' => 1,
            'claimed_at' => now(),
            'settled_at' => now(),
            'status' => 'ok',
            'meta' => json_encode(['result' => $winnerResult]),
        ]);
        DB::connection('pgsql_second')->table('ingest.charge_probe')->insert(['source' => 'winner-second-connection']);
    });

    $ledger = new EffectLedger;
    $calls = 0;
    $verdict = $ledger->once($digest, 'http', function () use (&$calls) {
        $calls++;

        return ['probe' => 'loser-result']; // must never be persisted or counted as a charge
    }, costUnits: 1);

    // The loser's closure must NEVER run.
    expect($calls)->toBe(0);

    // Never ok-with-cached=false from a loser — that exact combination is the
    // double-charge signature.
    expect($verdict['status'] === 'refused' || ($verdict['status'] === 'ok' && $verdict['cached'] === true))->toBeTrue();
    if ($verdict['status'] === 'ok') {
        expect($verdict['result'])->toBe($winnerResult);
    }

    // THE money assertion.
    expect(DB::connection('pgsql')->table('ingest.charge_probe')->count())->toBe(1);

    $rows = DB::connection('pgsql')->table('ingest.effects')->where('digest', $digest)->get();
    expect($rows)->toHaveCount(1);
    expect($rows[0]->status)->toBe('ok');
    expect($rows[0]->settled_at)->not->toBeNull();
    expect((int) DB::connection('pgsql')->table('ingest.effects')->where('digest', $digest)->sum('cost_units'))->toBe(1);

    DB::purge('pgsql_second');
});

it('charges an effect exactly once under a REAL N-way fork race on the same digest (8 children, independently reconnected)', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl_fork is not available in this runtime');
    }

    $digest = hash('sha256', 'race-fork-'.Str::uuid());
    $childCount = 8;
    $startAt = microtime(true) + 0.2; // ~200ms out — a shared gate, not a guess

    $pids = [];
    for ($i = 0; $i < $childCount; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            $this->fail('pcntl_fork failed');
        }

        if ($pid === 0) {
            // A forked PDO socket shared with the parent corrupts in a way
            // that looks exactly like the bug under test — drop it first.
            DB::purge('pgsql');
            DB::reconnect('pgsql');

            $sleepUs = (int) max(0, ($startAt - microtime(true)) * 1_000_000);
            usleep($sleepUs);

            try {
                $ledger = new EffectLedger;
                $verdict = $ledger->once($digest, 'http', function () use ($i) {
                    // The closure IS the charge.
                    DB::connection('pgsql')->table('ingest.charge_probe')->insert(['source' => "child-{$i}"]);

                    return ['winner' => $i];
                }, costUnits: 1);

                DB::connection('pgsql')->table('ingest.effect_probe')->insert([
                    'child_idx' => $i,
                    'status' => $verdict['status'],
                    'cached' => (bool) $verdict['cached'],
                    'result_json' => json_encode($verdict['result']),
                ]);
            } catch (Throwable $e) {
                DB::connection('pgsql')->table('ingest.effect_probe')->insert([
                    'child_idx' => $i,
                    'status' => 'exception',
                    'cached' => false,
                    'result_json' => json_encode(['error' => $e->getMessage()]),
                ]);
            }

            exit(0);
        }

        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    // No child should have hit an unrelated exception — if this fails, the
    // assertions below are not meaningful.
    $exceptions = DB::connection('pgsql')->table('ingest.effect_probe')->where('status', 'exception')->get();
    expect($exceptions)->toHaveCount(0, 'A child hit an unrelated exception: '.$exceptions->pluck('result_json')->implode('; '));

    // 1. THE money assertion: exactly one charge happened, no matter how many
    //    of the 8 children raced for it.
    expect(DB::connection('pgsql')->table('ingest.charge_probe')->count())->toBe(1);

    // 2. Exactly one settled effect row, charged for one unit's worth.
    $effectRows = DB::connection('pgsql')->table('ingest.effects')->where('digest', $digest)->get();
    expect($effectRows)->toHaveCount(1);
    expect($effectRows[0]->status)->toBe('ok');
    expect($effectRows[0]->settled_at)->not->toBeNull();
    expect((int) DB::connection('pgsql')->table('ingest.effects')->where('digest', $digest)->sum('cost_units'))->toBe(1);

    // 3. Every child reported, and the shape is coherent: exactly one winner
    //    (cached=false), every loser is refused or ok-with-cached=true (NEVER
    //    ok-with-cached=false — that combination from a non-winner is the
    //    double-charge signature), and any ok-cached loser's result matches
    //    the winner's exactly.
    $probes = DB::connection('pgsql')->table('ingest.effect_probe')->get();
    expect($probes)->toHaveCount($childCount);

    $winners = $probes->filter(fn ($r) => ! $r->cached);
    $losers = $probes->filter(fn ($r) => (bool) $r->cached);

    expect($winners)->toHaveCount(1);
    expect($winners->first()->status)->toBe('ok');

    foreach ($losers as $loser) {
        expect(in_array($loser->status, ['refused', 'ok'], true))->toBeTrue();
        if ($loser->status === 'ok') {
            expect($loser->result_json)->toBe($winners->first()->result_json);
        }
    }
});

// LIFE-6 / #WHK-3: EffectLedger::once() now catches only UniqueConstraintViolationException
// around its claim INSERT. These two cases are additive to the pair above — do not touch
// them — and are the only place the real 23505-vs-not distinction can be proven, since the
// SQLite mirror (tests/Feature/Ingest/EffectLedgerTest.php) has no independently-committing
// second connection to race against.

it('a non-unique claim-INSERT failure propagates on real Postgres — never laundered into refused', function () {
    config(['database.connections.pgsql_second' => config('database.connections.pgsql')]);

    $digest = hash('sha256', 'race-nonunique-'.Str::uuid());

    $injected = false;
    DB::listen(function ($query) use ($digest, &$injected) {
        if ($injected) {
            return; // fires exactly once — self-unregistering.
        }
        if (! str_contains($query->sql, 'ingest') || ! str_contains($query->sql, 'effects')) {
            return;
        }
        if (! in_array($digest, $query->bindings, true)) {
            return;
        }

        $injected = true;

        // Drop the table on a SEPARATE connection, between the caller's own
        // pre-read (clean) and its claim INSERT — a genuine 42P01, never a
        // unique-constraint conflict.
        DB::connection('pgsql_second')->statement('DROP TABLE ingest.effects CASCADE');
    });

    $ledger = new EffectLedger;
    $calls = 0;
    $thrown = null;

    try {
        $ledger->once($digest, 'http', function () use (&$calls) {
            $calls++;

            return 'nope';
        });
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(QueryException::class);
    expect($thrown)->not->toBeInstanceOf(UniqueConstraintViolationException::class);
    expect($calls)->toBe(0);

    DB::purge('pgsql_second');
});

it('a duplicate PRIMARY KEY insert on real Postgres raises UniqueConstraintViolationException', function () {
    // Locks in the PostgresConnection::isUniqueConstraintError() dependency that
    // once()'s typed catch relies on — a real 23505, not a string-matched SQLSTATE.
    $digest = hash('sha256', 'unique-typecheck-'.Str::uuid());
    $row = [
        'digest' => $digest,
        'kind' => 'http',
        'claimed_at' => now(),
        'status' => 'claimed',
        'meta' => json_encode([]),
    ];

    DB::connection('pgsql')->table('ingest.effects')->insert($row);

    $thrown = null;
    try {
        DB::connection('pgsql')->table('ingest.effects')->insert($row);
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(UniqueConstraintViolationException::class);
});
