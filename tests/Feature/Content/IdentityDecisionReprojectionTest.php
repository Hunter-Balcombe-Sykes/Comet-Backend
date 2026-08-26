<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Content\ApplyIdentityDecisionJob;
use App\Jobs\Content\ReprojectSourcesJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * The manual-source half of an owner identity ruling (plan
 * docs/superpowers/plans/2026-08-25-projectionwriter-identity-scope.md §A.4,
 * follow-up 1).
 *
 * store() finds the sources to reproject through an INNER JOIN onto
 * ingest.sources.connection_id. A MANUAL content.source has no connection_id
 * at all (ProjectionWriter::ensureManualSource — "a manual source has none"),
 * so for two owner-added items that join returned nothing and NOTHING was
 * dispatched. Correctness was already closed upstream — IdentityScope seeds
 * from live `same` rulings, so any later resolve applies the verdict — but
 * for a kind fed only by hand-added items there is no later resolve until the
 * owner happens to edit that kind again. "Applies on the next sync" was the
 * optimistic reading; "applies never" is the reachable one.
 *
 * Route-level rather than a direct controller call on purpose
 * (feedback_direct_controller_call_antipattern): the bug lives in a query the
 * controller runs, and only a real request proves the resolved user and the
 * route binding reach it.
 */
beforeEach(function () {
    setupContentCurationTables();
    setupIngestTables();
    // SiteCacheLanes::bust() dispatches CloudflareCachePurgeJob, and
    // QUEUE_CONNECTION=sync would run it inline. Faked so these tests measure
    // dispatch, not job side effects (same reasoning as ManualOverridesTest).
    Queue::fake();
});

/** An ingest.sources row for a connection, which is what store()'s join needs. */
function idrIngestSource(string $userId, string $connectionId): string
{
    $id = (string) Str::uuid();
    DB::table('ingest.sources')->insert([
        'id' => $id, 'user_id' => $userId, 'connection_id' => $connectionId,
        'source_key' => 'youtube', 'surface_key' => 'youtube.channel',
        'identifier' => 'acct-'.Str::random(6),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

/** The kind and both coords of a pair, as the controller derives them. */
function idrCoords(string $leftItemId, string $rightItemId): array
{
    return DB::table('content.source_items')
        ->whereIn('item_id', [$leftItemId, $rightItemId])
        ->pluck('coord')->map(fn ($c) => (string) $c)->all();
}

it('dispatches a resolve for a same ruling on two hand-added items', function () {
    $pro = createTenant('idr-manual');
    $manual = poolSource($pro->id, null);

    $left = poolItem($pro->id, $manual, 'video', 'Hand added one', now()->toDateTimeString());
    $right = poolItem($pro->id, $manual, 'video', 'Hand added two', now()->toDateTimeString());

    $response = actingAsUser($pro)->postJson("/api/content/items/{$left}/identity", [
        'other' => $right, 'verdict' => 'same',
    ]);

    $response->assertStatus(202);

    // The decisions are written either way — that half was never broken.
    expect(DB::table('content.identity_decisions')->where('user_id', $pro->id)->count())->toBe(1);

    // There is no ingest source to reproject, and that is correct.
    Queue::assertNotPushed(ReprojectSourcesJob::class);

    // What was missing: nothing at all made the ruling take effect.
    $coords = idrCoords($left, $right);
    Queue::assertPushed(ApplyIdentityDecisionJob::class, function (ApplyIdentityDecisionJob $job) use ($pro, $coords) {
        sort($coords);
        $jobCoords = $job->coords;
        sort($jobCoords);

        return $job->userId === (string) $pro->id
            && $job->kind === 'video'
            && $jobCoords === $coords;
    });

    $response->assertJsonPath('resolving', 2);
});

it('leaves the connector lane on reprojection alone, with no duplicate resolve', function () {
    $pro = createTenant('idr-connector');
    $connection = poolConnection($pro->id);
    $source = poolSource($pro->id, $connection);
    idrIngestSource($pro->id, $connection);

    $left = poolItem($pro->id, $source, 'video', 'Synced one', now()->toDateTimeString());
    $right = poolItem($pro->id, $source, 'video', 'Synced two', now()->toDateTimeString());

    $response = actingAsUser($pro)->postJson("/api/content/items/{$left}/identity", [
        'other' => $right, 'verdict' => 'same',
    ]);

    $response->assertStatus(202)->assertJsonPath('reprojecting', 1);

    Queue::assertPushed(ReprojectSourcesJob::class);
    // ingest:project runs its own resolve, so a second one would be pure cost.
    Queue::assertNotPushed(ApplyIdentityDecisionJob::class);

    $response->assertJsonPath('resolving', 0);
});

it('covers the manual half of a mixed pair without dropping the connector half', function () {
    $pro = createTenant('idr-mixed');
    $connection = poolConnection($pro->id);
    $synced = poolSource($pro->id, $connection);
    idrIngestSource($pro->id, $connection);
    $manual = poolSource($pro->id, null);

    $left = poolItem($pro->id, $synced, 'video', 'Synced', now()->toDateTimeString());
    $right = poolItem($pro->id, $manual, 'video', 'Hand added', now()->toDateTimeString());

    $response = actingAsUser($pro)->postJson("/api/content/items/{$left}/identity", [
        'other' => $right, 'verdict' => 'same',
    ]);

    $response->assertStatus(202);

    Queue::assertPushed(ReprojectSourcesJob::class);

    // Only the uncovered coord is seeded: the synced one is already reached by
    // the reprojection above, and seeding it twice would resolve it twice.
    $manualCoord = (string) DB::table('content.source_items')->where('item_id', $right)->value('coord');
    Queue::assertPushed(ApplyIdentityDecisionJob::class, fn (ApplyIdentityDecisionJob $job) => $job->coords === [$manualCoord]);
});

it('does not dispatch a resolve for a different ruling, which is a provable no-op', function () {
    // A cut can only PREVENT a future union, never undo one, and both entry
    // points to a ruling require two DISTINCT items — so there is no merge for
    // it to reverse. The next resolve reads the cut from
    // content.identity_decisions regardless, and the open candidate is
    // dismissed synchronously. Dispatching here would buy an owner triaging
    // twenty pairs as "different" twenty pointless resolves and forty CDN
    // purges for a guaranteed zero-diff outcome.
    $pro = createTenant('idr-different');
    $manual = poolSource($pro->id, null);

    $left = poolItem($pro->id, $manual, 'video', 'Not the same one', now()->toDateTimeString());
    $right = poolItem($pro->id, $manual, 'video', 'Not the same two', now()->toDateTimeString());

    $response = actingAsUser($pro)->postJson("/api/content/items/{$left}/identity", [
        'other' => $right, 'verdict' => 'different',
    ]);

    $response->assertStatus(202)->assertJsonPath('resolving', 0);

    // The cut itself is still recorded — that half must not regress.
    expect(DB::table('content.identity_decisions')
        ->where('user_id', $pro->id)->where('verdict', 'different')->count())->toBe(1);

    Queue::assertNotPushed(ApplyIdentityDecisionJob::class);
});

/** A minimal owner-authored projection, distinct per call so nothing auto-merges. */
function idrRelease(string $headline, string $url): array
{
    return [
        'kind' => 'release',
        'headline' => $headline,
        'facets' => ['f_link' => ['url' => $url]],
    ];
}

it('applies the ruling for real — the job merges two hand-added items', function () {
    $pro = createTenant('idr-e2e');
    $writer = app(ProjectionWriter::class);

    // Through the real spine, so these carry identity keys and anchors exactly
    // as a hand-add from the dashboard does.
    $one = $writer->writeManualItem($pro->id, 'manual:'.Str::uuid(), idrRelease('First listing', 'https://example.test/one'));
    $two = $writer->writeManualItem($pro->id, 'manual:'.Str::uuid(), idrRelease('Second listing', 'https://example.test/two'));

    expect($one)->not->toBe($two);

    actingAsUser($pro)->postJson("/api/content/items/{$one}/identity", [
        'other' => $two, 'verdict' => 'same',
    ])->assertStatus(202);

    // The ruling is RECORDED but not yet applied — this is the state the owner
    // used to be stranded in indefinitely.
    expect(DB::table('content.source_items')->whereIn('item_id', [$one, $two])->distinct()->count('item_id'))
        ->toBe(2);

    $job = Queue::pushed(ApplyIdentityDecisionJob::class)->first();
    expect($job)->not->toBeNull();
    $job->handle($writer);

    // One item now owns both coords: the owner's verdict took effect.
    $itemIds = DB::table('content.source_items')
        ->whereIn('coord', $job->coords)->whereNull('removed_at')
        ->pluck('item_id')->unique()->values()->all();

    expect($itemIds)->toHaveCount(1)
        ->and($itemIds[0])->toBeIn([$one, $two]);
});
