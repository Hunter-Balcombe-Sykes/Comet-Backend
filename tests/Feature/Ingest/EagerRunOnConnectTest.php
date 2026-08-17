<?php

use App\Ingest\ConnectorRegistry;
use App\Ingest\Runtime\SourceScheduler;
use App\Jobs\Ingest\RunSourceJob;
use App\Models\Core\Site\IntegrationConnection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The ONLY trigger a paid connector has (RESULTS 2026-08-17, verdict C).
// SourceProvisioner sets auto_sync = (cost === Free) and scoreDue() selects only
// auto_sync = true, so an instagram source is created and then never runs unless
// something fires it on connect. These tests pin that trigger AND — just as
// importantly — pin the three cases where it must NOT fire, because each of
// those is a paid third-party call.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    Bus::fake();
});

function eagerUser(): string
{
    return createTenant('eager-'.Str::lower(Str::random(6)))->id;
}

/** @param array<string, mixed> $attributes */
function connectFor(string $userId, array $attributes): IntegrationConnection
{
    return IntegrationConnection::create($attributes + [
        'user_id' => $userId,
        'resource_id' => 'acct-'.substr(sha1(Str::random(8)), 0, 16),
        'payload' => [],
        'is_active' => true,
    ]);
}

// ── It fires ────────────────────────────────────────────────────────────────

it('dispatches exactly one run for a newly connected instagram source', function () {
    $connection = connectFor(eagerUser(), [
        'platform' => 'instagram',
        'payload' => ['username' => 'tobiasbalcombe'],
    ]);

    $sourceId = DB::table('ingest.sources')->where('connection_id', $connection->id)->value('id');

    expect($sourceId)->not->toBeNull();

    Bus::assertDispatchedTimes(RunSourceJob::class, 1);
    Bus::assertDispatched(RunSourceJob::class, fn (RunSourceJob $job) => $job->sourceId === $sourceId);
});

it('claims the source before dispatching so a scheduler tick cannot run it too', function () {
    $connection = connectFor(eagerUser(), [
        'platform' => 'instagram',
        'payload' => ['username' => 'tobiasbalcombe'],
    ]);

    $row = DB::table('ingest.sources')->where('connection_id', $connection->id)->first();

    // An unclaimed dispatch would let claimDue() hand the same row to a second
    // worker and pay Apify twice — the EffectLedger settles per digest and
    // cannot refund.
    expect($row->in_flight_since)->not->toBeNull()
        ->and($row->in_flight_run_id)->not->toBeNull();
});

it('still provisions instagram with auto_sync off — the eager run does not make it schedulable', function () {
    $connection = connectFor(eagerUser(), [
        'platform' => 'instagram',
        'payload' => ['username' => 'tobiasbalcombe'],
    ]);

    // Guards against "fixing" this by widening schedulable(), which would turn
    // on recurring spend for all seven paid connectors.
    expect((bool) DB::table('ingest.sources')->where('connection_id', $connection->id)->value('auto_sync'))
        ->toBeFalse();
});

// ── It must NOT fire ────────────────────────────────────────────────────────

it('does not dispatch for a free connector — the scheduler owns those', function () {
    connectFor(eagerUser(), [
        'platform' => 'bandcamp',
        'payload' => ['url' => 'https://kinggizzard.bandcamp.com'],
    ]);

    // bandcamp is CostClass::Free: provisioned auto_sync = true with
    // next_attempt_at = now(), so ingest:dispatch picks it up within the tick.
    // An eager run here would be a duplicate fetch, not a fix.
    Bus::assertNotDispatched(RunSourceJob::class);
});

it('does not dispatch for a paid connector that has not opted in', function () {
    connectFor(eagerUser(), [
        'platform' => 'spotify',
        'payload' => ['url' => 'https://open.spotify.com/artist/1vCWHaC5f2uS3yhpwWbIA6'],
    ]);

    // spotify is CostClass::Actor but eagerOnConnect stays false. This is the
    // containment: opting in is one visible line per connector, never a
    // side effect of being paid.
    expect(ConnectorRegistry::manifestFor('spotify')->runsEagerlyOnConnect())->toBeFalse();
    Bus::assertNotDispatched(RunSourceJob::class);
});

it('does not dispatch again when an existing connection is updated', function () {
    $connection = connectFor(eagerUser(), [
        'platform' => 'instagram',
        'payload' => ['username' => 'tobiasbalcombe'],
    ]);

    Bus::assertDispatchedTimes(RunSourceJob::class, 1);

    // Release the claim first — otherwise claimOne() refuses the second
    // dispatch and this test passes for the WRONG reason, staying green even
    // with the `created` gate removed (confirmed by mutation, 2026-08-17).
    // Releasing is also what really happens: RunSourceJob's finally calls
    // SourceScheduler::release() the moment the first run ends, so every
    // refresh after that meets an unclaimed row.
    DB::table('ingest.sources')
        ->where('connection_id', $connection->id)
        ->update(['in_flight_since' => null, 'in_flight_run_id' => null]);

    // A status-only write and an identifier-preserving payload write both
    // return `unchanged` from sync(), so neither would catch a loosened gate.
    // A CHANGED USERNAME is the case that returns `updated` — it rewrites
    // identifier + next_attempt_at — and is the realistic one: someone renames
    // their Instagram account. Under a gate of `['created','updated']` this
    // buys a second paid actor call, which is the regression being pinned.
    $connection->update(['payload' => ['username' => 'someone_else']]);

    expect(DB::table('ingest.sources')->where('connection_id', $connection->id)->value('identifier'))
        ->toBe('someone_else');

    Bus::assertDispatchedTimes(RunSourceJob::class, 1);
});

// ── The claim itself ────────────────────────────────────────────────────────

it('claimOne wins once and refuses a second caller for the same row', function () {
    $connection = connectFor(eagerUser(), [
        'platform' => 'instagram',
        'payload' => ['username' => 'tobiasbalcombe'],
    ]);

    $sourceId = (string) DB::table('ingest.sources')->where('connection_id', $connection->id)->value('id');
    $scheduler = app(SourceScheduler::class);

    // The connect trigger already holds the claim, so the next caller must lose.
    expect($scheduler->claimOne($sourceId, (string) Str::uuid()))->toBeFalse();

    // ...and win again once the claim is released, or a failed run would strand
    // the source forever.
    DB::table('ingest.sources')->where('id', $sourceId)
        ->update(['in_flight_since' => null, 'in_flight_run_id' => null]);

    expect($scheduler->claimOne($sourceId, (string) Str::uuid()))->toBeTrue();
});
