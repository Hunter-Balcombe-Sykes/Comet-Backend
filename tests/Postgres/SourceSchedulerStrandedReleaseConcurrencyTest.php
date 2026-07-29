<?php

// #WHK-1: App\Ingest\Runtime\SourceScheduler::releaseStranded() previously
// plucked stale-looking source ids, then issued an UNCONDITIONAL per-row
// UPDATE — a TOCTOU race against claimDue() winning the exact same source in
// the gap between the SELECT and the UPDATE. A worker that legitimately
// re-claimed a source in that window would have its claim silently torn out
// from under it. The fix re-checks the same staleness predicate INSIDE the
// UPDATE, under the row lock, and only counts/reports a release when that
// UPDATE actually affects a row.
//
// Fork only, no deterministic-injection variant, same reasoning as
// SourceSchedulerConcurrencyTest.php: the race window is between two
// separate round trips with no application-level seam to inject into —
// genuine concurrent transactions on separate connections are the only way
// to prove this.
//
// ingest.sources.user_id is a bare uuid with NO FK to core.users — same
// precedent as SourceSchedulerConcurrencyTest.php, keeps this file out of
// NoLocalCanonicalTableDdlTest's canonical-table scan.

use App\Ingest\Runtime\SourceScheduler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;

uses(PostgresTestCase::class)->in(__FILE__);

beforeEach(function () {
    $pg = DB::connection('pgsql');

    $pg->statement('CREATE SCHEMA IF NOT EXISTS ingest');
    $pg->statement('DROP TABLE IF EXISTS ingest.claim_probe CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.release_probe CASCADE');
    $pg->statement('DROP TABLE IF EXISTS ingest.anomalies CASCADE');
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

    // Same shape as the real ingest.anomalies table (20260727130000), only
    // columns releaseStranded() actually writes plus the ones the property
    // assertions read back.
    $pg->statement('CREATE TABLE ingest.anomalies (
        id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
        source_id uuid,
        kind text NOT NULL,
        severity text NOT NULL DEFAULT \'warning\',
        summary text NOT NULL,
        detail jsonb NOT NULL DEFAULT \'{}\'::jsonb,
        detected_at timestamptz NOT NULL DEFAULT now()
    )');

    $pg->statement('CREATE TABLE ingest.claim_probe (
        id bigserial PRIMARY KEY,
        child_idx integer NOT NULL,
        source_id uuid NOT NULL,
        run_id uuid NOT NULL
    )');

    $pg->statement('CREATE TABLE ingest.release_probe (
        id bigserial PRIMARY KEY,
        source_id uuid NOT NULL
    )');
});

afterAll(function () {
    $pg = DB::connection('pgsql');
    foreach (['ingest.claim_probe', 'ingest.release_probe', 'ingest.anomalies', 'ingest.runs', 'ingest.sources'] as $t) {
        $pg->statement("DROP TABLE IF EXISTS {$t} CASCADE");
    }
});

it('never releases a source that was legitimately re-claimed under it, and files an anomaly only for an ACTUAL release (real fork)', function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl_fork is not available in this runtime');
    }

    $pg = DB::connection('pgsql');
    $sourceCount = 200;

    // All 200 sources look stranded (in_flight_since well past the 2h
    // cutoff, carrying a dead run id). The 200-candidate pluck followed by
    // ~400 sequential round trips (pre-fix) leaves a window tens of ms wide
    // between any given row's SELECT and its UPDATE — plenty of time for a
    // claimDue() winner to land in between.
    $deadRunId = (string) Str::uuid(); // in_flight_run_id is uuid, not free text
    $sourceIds = [];
    foreach (range(1, $sourceCount) as $i) {
        $id = (string) Str::uuid();
        $sourceIds[] = $id;
        $pg->table('ingest.sources')->insert([
            'id' => $id,
            'user_id' => (string) Str::uuid(),
            'source_key' => 'bandcamp',
            'surface_key' => 'music',
            'identifier' => "stranded-release-{$i}",
            'cost_units' => 1,
            'visibility' => 1.0,
            'change_rate' => 0.5,
            'in_flight_since' => now()->subHours(3),
            'in_flight_run_id' => $deadRunId,
            'next_attempt_at' => now()->subMinute(),
            'last_run_at' => now()->subDays(1),
        ]);
    }

    $startAt = microtime(true) + 0.2; // shared gate
    $pids = [];

    // Release workers 0-1: TWO concurrent callers, not one. A single
    // sequential releaseStranded() caller can never race itself — it nulls a
    // row in the very statement that discovers it stale, so no window opens
    // for a claim to land before ITS OWN update. The real TOCTOU needs a
    // SECOND worker independently plucking the same stale snapshot: worker A
    // nulls a row, a claimer re-claims it fresh, then worker B — still
    // walking its own earlier candidate list — reaches that same row and
    // (pre-fix) unconditionally wipes the fresh claim.
    for ($r = 0; $r < 2; $r++) {
        $pidRelease = pcntl_fork();
        if ($pidRelease === -1) {
            $this->fail('pcntl_fork failed');
        }
        if ($pidRelease === 0) {
            DB::purge('pgsql');
            DB::reconnect('pgsql');

            $sleepUs = (int) max(0, ($startAt - microtime(true)) * 1_000_000);
            usleep($sleepUs);

            $deadline = microtime(true) + 1.0;
            while (microtime(true) < $deadline) {
                try {
                    $released = (new SourceScheduler)->releaseStranded();
                    foreach ($released as $id) {
                        DB::connection('pgsql')->table('ingest.release_probe')->insert(['source_id' => $id]);
                    }
                } catch (Throwable $e) {
                    fwrite(STDERR, "release child {$r} exception: {$e->getMessage()}\n");
                }
            }

            exit(0);
        }
        $pids[] = $pidRelease;
    }

    // Children 1-3: hammer claimDue() for ~1s each, logging every claim.
    for ($i = 1; $i <= 3; $i++) {
        $pid = pcntl_fork();
        if ($pid === -1) {
            $this->fail('pcntl_fork failed');
        }
        if ($pid === 0) {
            DB::purge('pgsql');
            DB::reconnect('pgsql');

            $sleepUs = (int) max(0, ($startAt - microtime(true)) * 1_000_000);
            usleep($sleepUs);

            $deadline = microtime(true) + 1.0;
            while (microtime(true) < $deadline) {
                $runId = (string) Str::uuid();
                try {
                    $claimed = (new SourceScheduler)->claimDue(50, $runId);
                    foreach ($claimed as $row) {
                        DB::connection('pgsql')->table('ingest.claim_probe')->insert([
                            'child_idx' => $i,
                            'source_id' => $row['id'],
                            'run_id' => $runId,
                        ]);
                    }
                } catch (Throwable $e) {
                    fwrite(STDERR, "claim child {$i} exception: {$e->getMessage()}\n");
                }
            }

            exit(0);
        }
        $pids[] = $pid;
    }

    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }

    $claims = $pg->table('ingest.claim_probe')->get();
    $releases = $pg->table('ingest.release_probe')->get();
    $anomalyCount = $pg->table('ingest.anomalies')->where('kind', 'stranded')->count();

    // Property 1 (the fix): every source a claiming child believes it holds
    // must still actually hold it — a releaseStranded() call that raced past
    // a legitimate claim never tears it back out.
    foreach ($claims as $claim) {
        $row = $pg->table('ingest.sources')->where('id', $claim->source_id)->first();
        expect($row->in_flight_run_id)->toBe($claim->run_id)
            ->and($row->in_flight_since)->not->toBeNull();
    }

    // Property 2: an anomaly is filed once per ACTUAL release, never per
    // candidate — count(anomalies) must equal the total number of ids
    // releaseStranded() ever returned across all its calls.
    expect($anomalyCount)->toBe($releases->count());

    // ANTI-VACUITY (hard): the race must actually have produced both a
    // release and a claim, or this proves nothing.
    expect($releases->count())->toBeGreaterThan(0, 'releaseStranded() never released anything — the test setup is broken.');
    expect($claims->count())->toBeGreaterThan(0, 'claimDue() never claimed anything — the test setup is broken.');

    // SOFT WITNESS only (never pass/fail) — whether the SAME source was ever
    // both released and re-claimed is what would have failed pre-fix, but no
    // single run guarantees that exact interleaving happened.
    $overlap = $releases->pluck('source_id')->unique()->intersect($claims->pluck('source_id')->unique());
    if ($overlap->isEmpty()) {
        fwrite(STDERR, "[SourceSchedulerStrandedReleaseConcurrencyTest] no source was observed both released and re-claimed this run — no direct overlap witnessed.\n");
    }
});
