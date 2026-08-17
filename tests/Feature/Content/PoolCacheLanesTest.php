<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// A pool mutation has to move THREE things or the owner's drag doesn't show:
// the document build state, site.sites.updated_at (the public payload cache
// key is derived from it), and the CDN in front of the rendered page.
//
// Lane 2 was missing until 2026-08-14 — a reorder bumped the build state and
// purged the edge while the ORIGIN kept serving the old order from its own
// cache until the TTL ran out, so the drag looked like it hadn't taken for up
// to a minute. Route-level on purpose: a bare Request skips the middleware and
// site resolution that poolChanged() depends on.
//
// EXACT deltas, not "> 0": spec §17.2 records a three-lane test that PASSED
// with a lane deleted precisely because its assertion was a bare greater-than
// check. Case 2 (hand-add) is that trap made concrete — pin()'s own bust()
// call can be deleted entirely and a "> 0" assertion would still pass, because
// writeManualItem() already bumped once on its own.

beforeEach(function () {
    setupContentCurationTables();
    Queue::fake();
});

it('a pool reorder fires all three cache lanes, exactly once', function () {
    $user = createTenant('poollanes');
    $siteId = (string) $user->site->id;
    $a = seedContentItem($user->id);
    $b = seedContentItem($user->id);

    // Laravel binds timestamps at SECOND precision, so a same-second write
    // would let this pass or fail on wall-clock luck. Backdate first.
    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)
        ->update(['updated_at' => now()->subMinute()]);
    $before = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');
    $revisionBefore = (int) DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision');

    actingAsUser($user)
        ->putJson('/api/content/pools/watch/order', ['itemIds' => [$b, $a]])
        ->assertOk();

    // Lane 1 — the document build state. EXACT delta: a reorder is one
    // request, one bump — not "at least one".
    expect((int) DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision'))->toBe($revisionBefore + 1);

    // Lane 2 — the public payload cache key. This is the one that regressed.
    expect(DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at'))
        ->not->toBe($before);

    // Lane 3 — the edge.
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('a pool hand-add fires all three cache lanes, with content_revision bumped TWICE', function () {
    $user = createTenant('poollanesadd');
    $siteId = (string) $user->site->id;

    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)
        ->update(['updated_at' => now()->subMinute()]);
    $before = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');
    $revisionBefore = (int) (DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision') ?? 0);

    // title set, description/favicon/logo unset: keeps this off the
    // EnrichPoolLinkJob dispatch path so the job assertion below stays about
    // the cache-purge lane only, not an unrelated enrichment job.
    actingAsUser($user)
        ->postJson('/api/content/pools/watch/items', [
            'url' => 'https://example.com/videos/one',
            'title' => 'A hand-added video',
        ])
        ->assertCreated();

    // Lane 1 — TWO bumps for one request: writeManualItem() -> bumpSite()
    // bumps once for the content write, and PoolItemCreateController::pin()'s
    // SiteCacheLanes::bust() bumps again for the curation (pin) write. This
    // is the deliberate, asserted-in-the-open double-count from #PGR-6 — do
    // not "fix" it to +1 later without re-reading pin()'s docblock.
    expect((int) DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision'))->toBe($revisionBefore + 2);

    // Lane 2 — the public payload cache key.
    expect(DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at'))
        ->not->toBe($before);

    // Lane 3 — the edge. A bare assertPushed is enough here: pin()'s bust()
    // is the only source of this job on this path (see the deletion proof
    // in the implementation report — deleting pin()'s bust() call drops this
    // to zero pushes, not to some other count).
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('an item delete fires all three cache lanes, exactly once', function () {
    $user = createTenant('poollanesdel');
    $siteId = (string) $user->site->id;
    $itemId = seedContentItem($user->id);

    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)
        ->update(['updated_at' => now()->subMinute()]);
    $before = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');
    $revisionBefore = (int) (DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision') ?? 0);

    actingAsUser($user)
        ->deleteJson("/api/content/items/{$itemId}")
        ->assertOk();

    expect((int) DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision'))->toBe($revisionBefore + 1);
    expect(DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at'))
        ->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('an item link upsert fires all three cache lanes, exactly once', function () {
    $user = createTenant('poollanesupsert');
    $siteId = (string) $user->site->id;
    // listen + release: ItemLinkRules::ROSTER permits spotify only for
    // 'listen', and PoolRegistry::poolForKind() must resolve the item's kind
    // back to that pool.
    $itemId = seedContentItem($user->id, ['kind' => 'release']);

    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)
        ->update(['updated_at' => now()->subMinute()]);
    $before = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');
    $revisionBefore = (int) (DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision') ?? 0);

    actingAsUser($user)
        ->putJson("/api/content/items/{$itemId}/links/spotify", [
            'url' => 'https://open.spotify.com/album/abc123',
        ])
        ->assertOk();

    expect((int) DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision'))->toBe($revisionBefore + 1);
    expect(DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at'))
        ->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('an item link delete fires all three cache lanes, exactly once', function () {
    $user = createTenant('poollanesdelete');
    $siteId = (string) $user->site->id;
    $itemId = seedContentItem($user->id, ['kind' => 'release']);

    // Must PUT the link first or destroy() 404s.
    actingAsUser($user)
        ->putJson("/api/content/items/{$itemId}/links/spotify", [
            'url' => 'https://open.spotify.com/album/abc123',
        ])
        ->assertOk();

    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)
        ->update(['updated_at' => now()->subMinute()]);
    $before = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');
    $revisionBefore = (int) (DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision') ?? 0);

    actingAsUser($user)
        ->deleteJson("/api/content/items/{$itemId}/links/spotify")
        ->assertOk();

    expect((int) DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision'))->toBe($revisionBefore + 1);
    expect(DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at'))
        ->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});
