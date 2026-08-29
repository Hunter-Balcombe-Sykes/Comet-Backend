<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\Workplace;
use App\Services\Cache\SiteCacheInvalidator;
use App\Services\Http\SafeUrlFetcher;
use App\Site\Documents\SiteCacheLanes;
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
    // T3 (2026-08-20): the hand-add lane refuses unclaimed URLs, so the
    // hand-add case pastes a claimed shape with its reader's fetch stubbed
    // dead — the lanes under test are the cache lanes, not the reader.
    app()->instance(SafeUrlFetcher::class, Mockery::mock(SafeUrlFetcher::class, function ($m) {
        $m->shouldReceive('tryFetch')->andReturnNull()->byDefault();
        $m->shouldIgnoreMissing();
    }));
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
            'url' => 'https://vimeo.com/123456789',
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
// CloudflareCachePurgeJob is ShouldBeUnique, uniqueFor=35 (was 240 until
// 2026-08-19; this comment said 240 until 2026-08-28), keyed on handle+domain
// (CloudflareCachePurgeJob.php uniqueId()) — this SHOULD coalesce same-handle
// dispatches within the window.
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
// the full 35s uniqueFor window and never released. That is a MORE favourable
// coalescing condition than production, where a fast-draining queue can start
// processing (and release the lock) well inside 35s, letting a second
// dispatch back in. So this test's count is a LOWER BOUND on production purge
// volume, not an exact prediction of it.
//
// I1 (2026-08-28) narrowed that gap in the code rather than in this test:
// SiteCacheLanes now delays the purge by EDGE_PURGE_DELAY_SECONDS, so the lock
// really is held for the window in production too. This test cannot show that
// — it reads 1 either way — which is why the delay gets its own assertion at
// the bottom of this file.
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
    // (35s) is held for the rest of the test — Queue::fake() never processes
    // a job, so the lock is never released early the way a real worker would
    // release it. Documented in the comment above the test.
    expect($pushed)->toHaveCount(1);
});

// I1 (2026-08-27 unclaimed-signup quality plan, docs/2026-08-27-unclaimed-signup-quality-plan.md:66,301-306).
// The plan said the purge-storm fix and the write-triggers-build change had to
// be designed TOGETHER; they shipped as two commits that never touched the same
// file. 4ae78f9d5 gave BuildState::bump() a delayed, ShouldBeUnique-coalesced
// dispatch (BuildState.php:55-56); the purge one line class over
// (SiteCacheLanes.php:49) stayed immediate, one per bust().
//
// 16a4efc3f then added the shared 'cloudflare-purge' RateLimiter funnel, which
// correctly absorbs Cloudflare's 429s — a rejected job is release()d, not held.
// But a funnel changes what happens to a dispatch, not how many there are, and
// VOLUME is what I1 asked about.
//
// WHY A DELAY IS THE FIX, AND WHY THE OBVIOUS TEST DOES NOT PROVE IT:
// the coalescing here is not new machinery. CloudflareCachePurgeJob is already
// ShouldBeUnique with uniqueFor=35, and its lock is acquired in
// PendingDispatch::__destruct() -> UniqueLock::acquire(). That lock releases
// when the job STARTS PROCESSING. Undelayed, a purge starts ~1s after dispatch
// and frees the lock, so the next write in the burst dispatches again — which
// is exactly the observed volume. Delaying the job holds the lock for the whole
// window instead, so the burst collapses into one.
//
// The consequence for testing: Queue::fake() never processes a job, so the
// FIRST dispatch's lock is held for the whole test either way. A "one purge per
// burst" count assertion passes with OR without this change — see the volume
// test above, which already reads 1 and is documented as a lower bound. So
// these two tests assert the DELAY and the invariant that makes it work,
// not a count.

it('delays the edge purge so a write burst coalesces instead of purging per write', function () {
    $user = createTenant('poollanesdelay');
    $a = seedContentItem($user->id);
    $b = seedContentItem($user->id);

    actingAsUser($user)
        ->putJson('/api/content/pools/watch/order', ['itemIds' => [$b, $a]])
        ->assertOk();

    // Fails if ->delay() is dropped: $job->delay is then null, no pushed job
    // satisfies the closure, and assertPushed reports "not pushed". It cannot
    // pass vacuously in the other direction either — zero pushes also fails.
    Queue::assertPushed(
        CloudflareCachePurgeJob::class,
        fn (CloudflareCachePurgeJob $job) => $job->delay === SiteCacheLanes::EDGE_PURGE_DELAY_SECONDS,
    );
});

/**
 * The delay only coalesces while the job's own ShouldBeUnique lock outlives it.
 * Raise EDGE_PURGE_DELAY_SECONDS past CloudflareCachePurgeJob::$uniqueFor (35
 * for a primary purge) and the lock expires before the job runs, quietly
 * restoring per-write purge volume with every other test still green. Nothing
 * else in the suite pins that relationship.
 */
it('keeps the purge delay inside the unique lock that does the coalescing', function () {
    // Non-vacuity: a 0 delay would satisfy the test above (delay === 0) while
    // coalescing nothing, so the lower bound is pinned too.
    expect(SiteCacheLanes::EDGE_PURGE_DELAY_SECONDS)->toBeGreaterThan(0);

    expect(SiteCacheLanes::EDGE_PURGE_DELAY_SECONDS)
        ->toBeLessThan((new CloudflareCachePurgeJob('unused-handle'))->uniqueFor);
});

// #W1-CCH-1 (2026-08-29): WorkplaceObserver::saved() ran the identity mirror
// AFTER touchSite() — touchSite() publishes the NEW
// public.profile:{handle}:{ts} cache key (via SiteCacheService::
// raiseResolveFloor(), post-commit), and that key is never explicitly busted
// (rotation by key IS the design). Mirroring `description` -> `users.bio`
// AFTER the key rotated meant the new key went live while users.bio still
// held the PRE-edit value, poisoning it for the full payload TTL + stale
// window.
//
// SEQUENCE, not co-occurrence: a plain "did bio end up updated" assertion
// passes under EITHER ordering, since both blocks run within the same
// request. Instead, bind a recording SiteCacheInvalidator double that reads
// core.users.bio straight from the DB the MOMENT touchSite() is called —
// this observes what a real cache-key reader racing the request would see.
//
// touchSite() is called TWICE by this one save: once by WorkplaceObserver
// itself (reason 'workplace-save'), and once more by UserObserver — because
// mirrorIdentityFields()'s own $user->save() now also fires
// touchParentSiteIfPublicFieldChanged() (bio is in PUBLIC_PROFILE_USER_FIELDS
// as of this same fix). That SECOND call necessarily reads the post-edit
// bio under EITHER ordering, since it only exists because the mirror already
// ran — so it says nothing about WorkplaceObserver's own ordering and would
// mask a regression if not excluded. Key the recording by $reason and assert
// on the 'workplace-save' call specifically.
it('a workplace description edit mirrors users.bio BEFORE the site cache key rotates', function () {
    setupWorkplacesTable();
    $user = createTenant('poollanesworkplaceorder', ['account_type' => 'business']);
    $siteId = (string) $user->site->id;

    Workplace::forceCreate([
        'site_id' => $siteId,
        'name' => 'Ordering Co',
        'description' => 'Original blurb.',
    ]);

    // Bound AFTER the create above (whose own mirror/touch we don't care
    // about) and BEFORE the update under test. createClassCallable() resolves
    // the observer — and its constructor deps — fresh from the container on
    // EVERY event dispatch (Dispatcher::createClassCallable), so this bind is
    // picked up by the very next save.
    $recorder = new class extends SiteCacheInvalidator
    {
        /** @var array<string, ?string> reason => bio read at that call */
        public array $bioByReason = [];

        public function touchSite(Closure|Site|null $site, string $reason, array $context = []): void
        {
            $resolved = $site instanceof Closure ? $site() : $site;
            $this->bioByReason[$reason] = $resolved !== null
                ? DB::connection('pgsql')->table('core.users')->where('id', $resolved->user_id)->value('bio')
                : null;
        }
    };
    app()->instance(SiteCacheInvalidator::class, $recorder);

    $workplace = Workplace::where('site_id', $siteId)->first();
    $workplace->description = 'Updated blurb.';
    $workplace->save();

    expect($recorder->bioByReason)->toHaveKey('workplace-save');
    // Fails under the OLD ordering: WorkplaceObserver's OWN touchSite() ran
    // before the mirror, so this reads the PRE-edit value ('Original
    // blurb.') because mirrorIdentityFields()'s $user->save() hadn't landed
    // yet. Passes once the mirror runs first.
    expect($recorder->bioByReason['workplace-save'])->toBe('Updated blurb.');
});

// Pins B8a step 2 independently of the ordering above: a bio edit that
// arrives with NO workplace involved (a direct dashboard edit, the GBP
// IdentitySync fold) must ALSO roll the public cache key. Before 'bio' was
// added to UserObserver::PUBLIC_PROFILE_USER_FIELDS, this wrote the column
// but left site.sites.updated_at behind, so the SiteCacheInvalidator fix in
// the test above would still not have helped the next bio writer.
it('a bio-only user write advances site.sites.updated_at and purges the edge', function () {
    $user = createTenant('poollanesbioonly');
    $siteId = (string) $user->site->id;

    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)
        ->update(['updated_at' => now()->subMinute()]);
    $before = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');

    $user->bio = 'Freshly written bio.';
    $user->save();

    expect(DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at'))
        ->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});
