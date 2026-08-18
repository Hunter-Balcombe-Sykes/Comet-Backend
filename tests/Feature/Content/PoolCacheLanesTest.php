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

// #PGR-36: the three lane-1-only paths #PGR-6 left open (ManualOverrideController,
// ItemMerger, SectionItemController), plus the builder lane (SectionController,
// SectionGroupController, PageController — owner-included for uniformity), now
// route through SiteCacheLanes::bust() too. No behavioural test for ItemMerger:
// it has no production caller (no `new ItemMerger`, no `ItemMerger::class`, no
// container resolution anywhere in app/) — PoolCacheLaneSeamTest's static guard
// is its only coverage.

it('a manual override upsert fires all three cache lanes, exactly once', function () {
    $user = createTenant('poollanesoverride');
    $siteId = (string) $user->site->id;
    $itemId = seedContentItem($user->id);

    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)
        ->update(['updated_at' => now()->subMinute()]);
    $before = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');
    $revisionBefore = (int) (DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision') ?? 0);

    actingAsUser($user)->putJson("/api/content/items/{$itemId}/overrides", [
        'facet' => 'f_text', 'column' => 'headline', 'value' => 'Mine',
    ])->assertOk();

    expect((int) DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision'))->toBe($revisionBefore + 1);
    expect(DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at'))
        ->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('a manual override reset (destroy) fires all three cache lanes, exactly once', function () {
    $user = createTenant('poollanesoverridedel');
    $siteId = (string) $user->site->id;
    $itemId = seedContentItem($user->id);

    // Must PUT first or destroy() 404s (ManualOverrideController.php:82-84).
    actingAsUser($user)->putJson("/api/content/items/{$itemId}/overrides", [
        'facet' => 'f_text', 'column' => 'headline', 'value' => 'Mine',
    ])->assertOk();

    // Backdate AFTER the seeding PUT, so that write's own lane-2 bump doesn't
    // get read back as this test's delta.
    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)
        ->update(['updated_at' => now()->subMinute()]);
    $before = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');
    $revisionBefore = (int) (DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision') ?? 0);

    actingAsUser($user)->deleteJson("/api/content/items/{$itemId}/overrides/f_text/headline")
        ->assertOk();

    expect((int) DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision'))->toBe($revisionBefore + 1);
    expect(DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at'))
        ->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('a section-item pin fires all three cache lanes, exactly once', function () {
    $user = createTenant('poollanessecpin');
    $siteId = (string) $user->site->id;
    [, $sectionId] = seedPageWithSection($siteId);
    $itemId = seedContentItem($user->id);

    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)
        ->update(['updated_at' => now()->subMinute()]);
    $before = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');
    $revisionBefore = (int) (DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision') ?? 0);

    actingAsUser($user)
        ->putJson("/api/site/sections/{$sectionId}/items/{$itemId}", ['state' => 'pinned'])
        ->assertOk();

    expect((int) DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision'))->toBe($revisionBefore + 1);
    expect(DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at'))
        ->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('a section-item destroy fires all three cache lanes, exactly once', function () {
    $user = createTenant('poollanessecdel');
    $siteId = (string) $user->site->id;
    [, $sectionId] = seedPageWithSection($siteId);
    $itemId = seedContentItem($user->id);

    // Must pin first or destroy() 404s.
    actingAsUser($user)
        ->putJson("/api/site/sections/{$sectionId}/items/{$itemId}", ['state' => 'pinned'])
        ->assertOk();

    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)
        ->update(['updated_at' => now()->subMinute()]);
    $before = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');
    $revisionBefore = (int) (DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision') ?? 0);

    actingAsUser($user)
        ->deleteJson("/api/site/sections/{$sectionId}/items/{$itemId}")
        ->assertOk();

    expect((int) DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision'))->toBe($revisionBefore + 1);
    expect(DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at'))
        ->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

// Builder lane (owner-included for uniformity, 2026-08-17). A bare
// Queue::assertPushed plus the updated_at move is enough here — exact
// revision deltas buy little on the builder lane.

it('a section create fires the cache lanes (builder lane)', function () {
    $user = createTenant('poollanesbuildersection');
    $siteId = (string) $user->site->id;
    [$pageId] = seedPageWithSection($siteId);

    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)
        ->update(['updated_at' => now()->subMinute()]);
    $before = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');

    actingAsUser($user)->postJson('/api/site/sections', [
        'page_id' => $pageId,
        'kind' => 'collection',
        'label' => 'New section',
    ])->assertStatus(201);

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at'))
        ->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('a page create fires the cache lanes (builder lane)', function () {
    $user = createTenant('poollanesbuilderpage');
    $siteId = (string) $user->site->id;

    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)
        ->update(['updated_at' => now()->subMinute()]);
    $before = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');

    actingAsUser($user)->postJson('/api/site/pages', [
        'key' => 'listen',
        'label' => 'Listen',
    ])->assertStatus(201);

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at'))
        ->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('a section-group upsert fires the cache lanes (builder lane)', function () {
    $user = createTenant('poollanesbuildergroup');
    $siteId = (string) $user->site->id;
    [, $sectionId] = seedPageWithSection($siteId);

    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)
        ->update(['updated_at' => now()->subMinute()]);
    $before = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');

    actingAsUser($user)->putJson("/api/site/sections/{$sectionId}/groups/2026-08", [
        'label' => 'This month',
    ])->assertOk();

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at'))
        ->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

// PURGE-VOLUME MEASUREMENT (owner-requested, 2026-08-17). The concern: builder
// writes are high-frequency during a site-build session (drag, rename, create),
// so an owner reorganising their page could dispatch a purge per write.
// CloudflareCachePurgeJob is ShouldBeUnique, uniqueFor=240, keyed on
// handle+domain (CloudflareCachePurgeJob.php uniqueId()) — this SHOULD coalesce
// same-handle dispatches within the window.
//
// Whether Queue::fake() actually proves that is NOT obvious and had to be
// checked rather than assumed: uniqueness for a queued job is NOT enforced by
// QueueFake::push() (it records every push into $this->jobs unconditionally —
// see vendor/laravel/framework/.../QueueFake.php:478-499). It is enforced one
// layer UP, in Illuminate\Foundation\Bus\PendingDispatch::__destruct(), which
// calls shouldDispatch() -> (new UniqueLock($cache))->acquire($job) BEFORE the
// job ever reaches the (fake) queue. That lock is a REAL cache-backed lock
// (phpunit.xml sets CACHE_STORE=array, and Illuminate\Cache\ArrayStore
// implements real in-process locks) — Cache is not faked anywhere in this
// suite. So in this Laravel version (12.x), Queue::fake() does NOT bypass
// unique-job coalescing: a burst of same-handle dispatches within the same
// test genuinely exercises the same lock a production run would.
//
// Caveat that still matters for reading the number: the lock releases when the
// job STARTS PROCESSING, not when it finishes — and Queue::fake() never
// processes anything, so within one test the FIRST dispatch's lock is held for
// the full 240s uniqueFor window and never released. That is a MORE favourable
// coalescing condition than production, where a fast-draining queue can start
// processing (and release the lock) well inside 240s, letting a second
// dispatch back in. So this test's count is a LOWER BOUND on production purge
// volume, not an exact prediction of it.
it('measures actual purge-job volume for a burst of builder writes on one site', function () {
    $user = createTenant('poollanesburst');
    $siteId = (string) $user->site->id;
    [$pageId] = seedPageWithSection($siteId);

    // 8 successive section creates against the SAME site — a realistic
    // "owner dragging sections around" burst. All share one handle, so all
    // share one uniqueId() lock key.
    for ($i = 0; $i < 8; $i++) {
        actingAsUser($user)->postJson('/api/site/sections', [
            'page_id' => $pageId,
            'kind' => 'collection',
            'label' => "Burst section {$i}",
            'key' => "burst-{$i}",
        ])->assertStatus(201);
    }

    $pushed = Queue::pushed(CloudflareCachePurgeJob::class);

    // The real number, not asserted blind: 8 writes to the same site coalesce
    // to exactly ONE purge dispatch, because the first dispatch's unique lock
    // (240s) is held for the rest of the test — Queue::fake() never processes
    // a job, so the lock is never released early the way a real worker would
    // release it. Documented in the comment above the test.
    expect($pushed)->toHaveCount(1);
});
