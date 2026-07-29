<?php

// #TEST-5 residual: App\Jobs\Ingest\RunSourceJob had ZERO behavioural test
// coverage before this file (grepped: only two stray comments mention the
// class name). The finding's "concurrency" framing was traced and found
// wrong — handle()'s finally/failed() interaction is SEQUENCING within one
// process (a claim held across a try/finally, a defensive re-check in
// failed()), not contention between two processes, so this stays in the
// normal Feature lane rather than tests/Postgres/. Both nets in the source
// were traced and found correct; these tests exist to PROVE that rather than
// take it on faith, and to catch a regression in either.
//
// DB-backed via the SQLite mirror (setupIngestTables(), tests/Pest.php),
// following the house convention: real classes (SourceScheduler, RunExecutor),
// no mocks.

use App\Ingest\Runtime\RunExecutor;
use App\Ingest\Runtime\SourceScheduler;
use App\Jobs\Ingest\RunSourceJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

beforeEach(function () {
    setupIngestTables();
});

/**
 * ingest.sources row seeded as ALREADY CLAIMED — the real precondition every
 * RunSourceJob dispatch starts from (SourceScheduler::claimDue() stamps
 * in_flight_since/in_flight_run_id before the job ever runs).
 */
function seedClaimedRunSource(array $overrides = []): string
{
    $id = (string) Str::uuid();

    DB::table('ingest.sources')->insert(array_merge([
        'id' => $id,
        'user_id' => (string) Str::uuid(),
        'connection_id' => null,
        // Deliberately unregistered: ConnectorRegistry::for() throws
        // InvalidArgumentException for any key not in its MAP — the cleanest
        // deterministic way to make handle() throw AFTER the claim exists and
        // BEFORE the normal in-try release, with no mock needed.
        'source_key' => 'no_such_connector',
        'surface_key' => 'music',
        'identifier' => 'https://someartist.example',
        'cost_units' => 1,
        'min_interval_secs' => 3600,
        'max_interval_secs' => 604800,
        'change_rate' => 0.5,
        'next_attempt_at' => now()->subMinute(),
        'last_run_at' => null,
        'visibility' => 1.0,
        'in_flight_since' => now(),
        'in_flight_run_id' => 'run-1',
        'health' => 'ok',
        'consecutive_failures' => 0,
        'auto_sync' => true,
        'scope' => 'all',
        'scope_n' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $id;
}

it('releases the claim in finally when handle() throws, recording the failure and pushing next_attempt_at into the future', function () {
    Exceptions::fake();

    $id = seedClaimedRunSource();
    $job = new RunSourceJob($id);

    expect(fn () => $job->handle(app(SourceScheduler::class), app(RunExecutor::class)))
        ->toThrow(InvalidArgumentException::class);

    $row = DB::table('ingest.sources')->where('id', $id)->first();

    // Both holding together is the point: the claim was dropped AND the
    // failure was recorded — not "no exception" alone, which an unconditional
    // release would also satisfy while leaving stale claim state behind.
    expect($row->in_flight_since)->toBeNull();
    expect($row->in_flight_run_id)->toBeNull();
    expect((int) $row->consecutive_failures)->toBe(1);
    expect(strtotime((string) $row->next_attempt_at))->toBeGreaterThan(time());
});

it('failed() releases the claim when handle()\'s finally never ran (a hard kill before it)', function () {
    Log::spy();
    Exceptions::fake();

    $id = seedClaimedRunSource();

    // No handle() call at all — simulates a worker killed between dispatch
    // and the finally block ever executing. failed() is the only backstop.
    $job = new RunSourceJob($id);
    $job->failed(new RuntimeException('worker was killed mid-flight'));

    $row = DB::table('ingest.sources')->where('id', $id)->first();
    expect($row->in_flight_since)->toBeNull();
    expect($row->in_flight_run_id)->toBeNull();
    expect((int) $row->consecutive_failures)->toBe(1);

    Log::shouldHaveReceived('error')->once()->with('ingest.run_source.job_failed', \Mockery::type('array'));
});

it('failed() is a no-op when finally already released the claim — consecutive_failures does not double-count (the finding\'s exact scenario)', function () {
    Log::spy();
    Exceptions::fake();
    Carbon::setTestNow('2026-07-29 12:00:00');

    // The EXACT state handle()'s finally leaves after a normal 'error'-outcome
    // release: claim dropped, one failure already recorded, backoff already set.
    $id = seedClaimedRunSource([
        'in_flight_since' => null,
        'in_flight_run_id' => null,
        'consecutive_failures' => 1,
        'next_attempt_at' => now()->addSeconds(7200)->toDateTimeString(),
    ]);
    $before = DB::table('ingest.sources')->where('id', $id)->first();

    // failed() fires from a freshly-unserialized copy AFTER the ORIGINAL
    // instance's finally already ran — this call must find nothing to do.
    $job = new RunSourceJob($id);
    $job->failed(new RuntimeException('reported after the original instance already released'));

    $after = DB::table('ingest.sources')->where('id', $id)->first();

    // The assertion this test exists for: STILL 1, not 2. Asserting the
    // UNCHANGED counter is what proves the $stillClaimed guard — "no
    // exception" would pass against a double release that silently corrupts
    // backoff.
    expect((int) $after->consecutive_failures)->toBe(1);
    expect((string) $after->next_attempt_at)->toBe((string) $before->next_attempt_at);
    expect($after->in_flight_since)->toBeNull();

    // failed() still logs unconditionally (that half is not what's under
    // test); the guard is specifically around the release() call.
    Log::shouldHaveReceived('error')->once();

    Carbon::setTestNow();
});
