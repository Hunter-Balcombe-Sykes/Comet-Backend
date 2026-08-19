<?php

use App\Ingest\Runtime\SourceScheduler;
use App\Jobs\Ingest\RunSourceJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// #LIFE-5 — the eager-connect run is a ONE-SHOT with nothing behind it.
//
// maybeRunEagerly() fires once on creation; nothing retries it; and
// auto_sync = false keeps SourceScheduler::scoreDue() away regardless of
// next_attempt_at. A dispatch lost to a queue blip therefore means that user's
// media never arrives — permanently, with only a Log::warning.
//
// The predicate deserves its own note, because the obvious one is wrong. "Never
// ran" is NOT `last_run_at IS NULL`: the observer's own catch calls
// release('error'), and release() stamps last_run_at on EVERY path. Meanwhile a
// job that was dispatched and silently never executed is cleared by
// releaseStranded(), which does NOT stamp it. The only signal that means the
// same thing in both cases is "no ingest.runs row ever reached a landing
// outcome" — which is what the command actually asks.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    Bus::fake([RunSourceJob::class]);
});

function eagerSource(array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::table('ingest.sources')->insert(array_merge([
        'id' => $id,
        'user_id' => (string) Str::uuid(),
        'source_key' => 'instagram',
        'surface_key' => 'instagram.profile',
        'identifier' => 'acct-'.Str::random(6),
        'auto_sync' => false,          // what the eager lane leaves behind
        'in_flight_since' => null,
        'health' => 'ok',
        'consecutive_failures' => 0,
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subHours(3),
    ], $overrides));

    return $id;
}

function eagerRun(string $sourceId, ?string $outcome): void
{
    DB::table('ingest.runs')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId, 'trigger' => 'connect',
        'started_at' => now()->subHours(2), 'finished_at' => now()->subHours(2),
        'outcome' => $outcome, 'created_at' => now()->subHours(2),
    ]);
}

it('re-dispatches a stranded eager source and claims it first', function () {
    $id = eagerSource();

    $this->artisan('ingest:reconcile-eager')->assertSuccessful();

    Bus::assertDispatched(RunSourceJob::class, fn (RunSourceJob $j) => $j->sourceId === $id);

    // Claimed, exactly as the observer would have — otherwise two passes (or a
    // concurrent scheduler tick) could both dispatch the same source.
    expect(DB::table('ingest.sources')->where('id', $id)->value('in_flight_since'))->not->toBeNull();
});

it('leaves a source alone once ANY run has landed', function () {
    foreach (['ok', 'not_modified', 'degraded'] as $outcome) {
        $id = eagerSource();
        eagerRun($id, $outcome);

        $this->artisan('ingest:reconcile-eager')->assertSuccessful();

        Bus::assertNotDispatched(RunSourceJob::class, fn (RunSourceJob $j) => $j->sourceId === $id);
    }
});

it('still re-dispatches when the only run FAILED, which is the whole point', function () {
    // The observer's dispatch-failure path: release('error') stamped last_run_at
    // and wrote an error run. `last_run_at IS NULL` would miss this source
    // entirely — the trap this command's predicate exists to avoid.
    $id = eagerSource(['last_run_at' => now()->subHours(2)]);
    eagerRun($id, 'error');

    $this->artisan('ingest:reconcile-eager')->assertSuccessful();

    Bus::assertDispatched(RunSourceJob::class, fn (RunSourceJob $j) => $j->sourceId === $id);
});

it('respects the grace window, so a healthy in-flight connect run is not double-charged', function () {
    // Created moments ago: its eager run may simply not have written its
    // ingest.runs row yet. Re-claiming would spend a metered connector's budget
    // to duplicate a run already happening.
    $id = eagerSource(['created_at' => now()->subMinutes(2), 'updated_at' => now()->subMinutes(2)]);

    $this->artisan('ingest:reconcile-eager')->assertSuccessful();

    Bus::assertNotDispatched(RunSourceJob::class, fn (RunSourceJob $j) => $j->sourceId === $id);
});

it('skips a source someone else already holds', function () {
    $id = eagerSource(['in_flight_since' => now()->subMinutes(1), 'in_flight_run_id' => (string) Str::uuid()]);

    $this->artisan('ingest:reconcile-eager')->assertSuccessful();

    Bus::assertNotDispatched(RunSourceJob::class, fn (RunSourceJob $j) => $j->sourceId === $id);
});

it('stops re-dispatching a source that keeps failing, rather than burning vendor budget daily', function () {
    $id = eagerSource(['consecutive_failures' => 3]);
    eagerRun($id, 'error');

    $this->artisan('ingest:reconcile-eager')->assertSuccessful();

    Bus::assertNotDispatched(RunSourceJob::class, fn (RunSourceJob $j) => $j->sourceId === $id);
});

it('ignores a connector that does not run eagerly on connect', function () {
    // uber_eats is one of the three. auto_sync = false there is the NORMAL
    // resting state, not evidence of a lost dispatch — re-running it would
    // invent a sync cadence the manifest deliberately does not ask for.
    $id = eagerSource(['source_key' => 'uber_eats', 'surface_key' => 'uber_eats.store']);

    $this->artisan('ingest:reconcile-eager')->assertSuccessful();

    Bus::assertNotDispatched(RunSourceJob::class, fn (RunSourceJob $j) => $j->sourceId === $id);
});

it('never touches a source the scheduler already owns (auto_sync = true)', function () {
    $id = eagerSource(['auto_sync' => true]);

    $this->artisan('ingest:reconcile-eager')->assertSuccessful();

    Bus::assertNotDispatched(RunSourceJob::class, fn (RunSourceJob $j) => $j->sourceId === $id);
});

it('changes nothing under --dry-run', function () {
    $id = eagerSource();

    $this->artisan('ingest:reconcile-eager', ['--dry-run' => true])->assertSuccessful();

    Bus::assertNotDispatched(RunSourceJob::class);
    expect(DB::table('ingest.sources')->where('id', $id)->value('in_flight_since'))->toBeNull();
});

it('honours --limit so one pass cannot flood the ingest queue', function () {
    foreach (range(1, 5) as $_) {
        eagerSource();
    }

    $this->artisan('ingest:reconcile-eager', ['--limit' => 2])->assertSuccessful();

    Bus::assertDispatchedTimes(RunSourceJob::class, 2);
});

it('waits for a deferred run\'s own retry time instead of re-dispatching daily forever', function () {
    // Found by review. SourceScheduler::release() has an early return for
    // outcome 'deferred' with a retryAfter: it reschedules next_attempt_at and
    // returns BEFORE the $qualifies check, so consecutive_failures and health
    // are both untouched. 'deferred' is also not a landed outcome. So every
    // other guard in this command passes forever, and the source would be
    // re-claimed on every 04:10 run — unbounded spend on a metered connector,
    // which is the failure mode the command exists to prevent.
    $id = eagerSource(['next_attempt_at' => now()->addHours(6)]);
    eagerRun($id, 'deferred');

    $this->artisan('ingest:reconcile-eager')->assertSuccessful();

    Bus::assertNotDispatched(RunSourceJob::class, fn (RunSourceJob $j) => $j->sourceId === $id);

    // …and it IS picked up once the vendor's own retry time arrives, so this is
    // a delay, not a permanent exclusion.
    DB::table('ingest.sources')->where('id', $id)->update(['next_attempt_at' => now()->subMinute()]);

    $this->artisan('ingest:reconcile-eager')->assertSuccessful();

    Bus::assertDispatched(RunSourceJob::class, fn (RunSourceJob $j) => $j->sourceId === $id);
});

it('still recovers a failed Instagram source promptly, through the REAL backoff release() sets', function () {
    // The realistic-interval case, and the reason next_attempt_at is honoured
    // for deferrals ONLY. SourceProvisioner writes min_interval_secs = the
    // manifest's defaultIntervalSeconds, and instagram/spotify/soundcloud all
    // declare 604800 — equal to MAX_INTERVAL_FLOOR_SECS. release()'s
    // min(max, min * 2^failures) is therefore ALREADY MAXED on failure #1.
    //
    // Driven through the real SourceScheduler::release() rather than a fixture
    // value, because the whole point is what the production math produces: an
    // earlier version of this test set an arbitrary 2-hour next_attempt_at and
    // would have passed against a guard that delayed recovery by a week.
    $id = eagerSource([
        'source_key' => 'instagram',
        'surface_key' => 'instagram.profile',
        'min_interval_secs' => 604800,
        'max_interval_secs' => 604800,
        'in_flight_since' => now(),
        'in_flight_run_id' => (string) Str::uuid(),
    ]);
    eagerRun($id, 'error');

    app(SourceScheduler::class)->release($id, 'error', false);

    $source = DB::table('ingest.sources')->where('id', $id)->first();
    // Pin the premise: a full week out, on the FIRST failure.
    expect((int) $source->consecutive_failures)->toBe(1);
    expect(strtotime((string) $source->next_attempt_at) - time())->toBeGreaterThan(6 * 86400);

    $this->artisan('ingest:reconcile-eager')->assertSuccessful();

    // Recovered anyway. A vendor asking us to come back later is a reason to
    // wait; our own queue dropping a job is not, and making the user wait a
    // week for content they just connected would defeat the command.
    Bus::assertDispatched(RunSourceJob::class, fn (RunSourceJob $j) => $j->sourceId === $id);
});
