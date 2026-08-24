<?php

use App\Ingest\Runtime\SourceScheduler;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// #LIFE-5, option (b) — owner ruling 2026-08-19.
//
// The eager connect run is a ONE-SHOT: the observer fires it once on creation,
// nothing retried it, and auto_sync = false keeps SourceScheduler::scoreDue()
// away no matter what next_attempt_at says. So a dispatch lost to a queue blip
// meant that user's media never arrived — indefinitely.
//
// ingest.sources.needs_eager_run is the retry, and it lives on the ROW rather
// than in a nightly reconcile command precisely so that every guard scoreDue()
// already has applies to it unchanged: the backoff, the dead-source cutoff and
// the in-flight claim. Those are asserted here as inherited behaviour, because
// "we get them for free" is the whole argument for this design over a command
// that had to re-implement each one.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
});

function obligationSource(array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::table('ingest.sources')->insert(array_merge([
        'id' => $id,
        'user_id' => (string) Str::uuid(),
        'source_key' => 'instagram',
        'surface_key' => 'instagram.profile',
        'identifier' => 'acct-'.Str::random(6),
        'auto_sync' => false,
        'needs_eager_run' => true,
        'health' => 'ok',
        'consecutive_failures' => 0,
        'next_attempt_at' => now()->subMinute(),
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ], $overrides));

    return $id;
}

function claimedIds(int $limit = 10): array
{
    return array_map(
        fn ($s) => (string) $s['id'],
        app(SourceScheduler::class)->claimDue($limit, (string) Str::uuid()),
    );
}

it('picks up an eager source the scheduler would otherwise never see', function () {
    $id = obligationSource();

    // auto_sync is false — before #LIFE-5 this row was invisible to scoreDue()
    // forever, which is the whole finding.
    expect((bool) DB::table('ingest.sources')->where('id', $id)->value('auto_sync'))->toBeFalse();
    expect(claimedIds())->toContain($id);
});

it('ignores a non-eager source that is simply not auto-syncing', function () {
    // The control. Without it the case above could pass because claimDue()
    // returns everything, for a reason unrelated to the flag.
    $id = obligationSource(['needs_eager_run' => false]);

    expect(claimedIds())->not->toContain($id);
});

it('discharges the obligation once a run actually lands', function () {
    $id = obligationSource();
    $scheduler = app(SourceScheduler::class);

    $scheduler->claimOne($id, (string) Str::uuid());
    $scheduler->release($id, 'ok', true);

    expect((bool) DB::table('ingest.sources')->where('id', $id)->value('needs_eager_run'))->toBeFalse();
    // …and the row goes back to being governed by auto_sync alone.
    DB::table('ingest.sources')->where('id', $id)->update(['next_attempt_at' => now()->subMinute()]);
    expect(claimedIds())->not->toContain($id);
});

it('keeps the obligation when the run FAILED, which is what makes it a retry', function () {
    $id = obligationSource();
    $scheduler = app(SourceScheduler::class);

    $scheduler->claimOne($id, (string) Str::uuid());
    $scheduler->release($id, 'error', false);

    expect((bool) DB::table('ingest.sources')->where('id', $id)->value('needs_eager_run'))->toBeTrue();
});

it('inherits the failure backoff instead of re-implementing it', function () {
    // release('error') pushed next_attempt_at out. scoreDue() already filters on
    // it, so the eager source waits its turn with no extra code — this is the
    // guard option (a) had to hand-roll, and get wrong twice.
    $id = obligationSource();
    $scheduler = app(SourceScheduler::class);
    $scheduler->claimOne($id, (string) Str::uuid());
    $scheduler->release($id, 'error', false);

    expect(strtotime((string) DB::table('ingest.sources')->where('id', $id)->value('next_attempt_at')))
        ->toBeGreaterThan(time());
    expect(claimedIds())->not->toContain($id);
});

it('inherits the deferral rule — a vendor asking us to come back later is respected', function () {
    // The hole a review found in option (a): release()'s 'deferred' branch
    // returns early and bumps neither consecutive_failures nor health, so a
    // command guarding only on those would have re-dispatched forever.
    // scoreDue()'s next_attempt_at filter handles it with no special case.
    $id = obligationSource();
    $scheduler = app(SourceScheduler::class);
    $scheduler->claimOne($id, (string) Str::uuid());
    $scheduler->release($id, 'deferred', false, 3600);

    expect((bool) DB::table('ingest.sources')->where('id', $id)->value('needs_eager_run'))->toBeTrue();
    expect(claimedIds())->not->toContain($id);
});

it('inherits the dead-source cutoff, so a permanently broken source stops costing money', function () {
    // Ten failures mark a source dead and scoreDue() excludes it — the bound on
    // vendor spend, which for an Actor-billed connector is the point.
    $id = obligationSource(['health' => 'dead', 'consecutive_failures' => 10]);

    expect(claimedIds())->not->toContain($id);
});

it('inherits the in-flight claim, so two workers cannot both take it', function () {
    $id = obligationSource(['in_flight_since' => now(), 'in_flight_run_id' => (string) Str::uuid()]);

    expect(claimedIds())->not->toContain($id);
});
