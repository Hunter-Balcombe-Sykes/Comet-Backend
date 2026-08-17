<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\ShopProduct;
use App\Models\Core\User\User;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\Strategies\Fetch\FetchNotModifiedException;
use App\Services\Platforms\Strategies\Fetch\ShopFetch;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// FOUND-25: the shop brand+product map moved out of the single
// site.platform_connections.payload JSONB cell into site.shop_brands /
// site.shop_products child tables. These tests cover the storage shape itself
// (not just the API responses already covered elsewhere): the connection row's
// payload collapses to a static marker, brand/product rows are the source of
// truth, and lifecycle operations (remove/forget/re-add) don't leave orphans.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Task 6: ShopCatalog::syncLatest() (reached from updateBrand's
    // selectionMode=latest immediate sync AND a real ShopFetch::fetch() run)
    // now reconciles into content.* instead of site.shop_products — several
    // tests below exercise that for real (not a mocked ShopCatalog), so the
    // content.* stand-in tables must exist or those requests 500.
    setupIngestTables();
    setupContentTables();
});

// orderedProductIdsFor()/curatedAtFor(): moved to tests/Pest.php (shared
// across Shop test files — PHP has no per-file function scoping, and
// ShopSelectionLockTest also needs them).

function shopStorageUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

/** shopStorageUser() + a site.sites row, so IntegrationConnectionObserver / the
 * shared refresher can resolve a subdomain to purge (mirrors PlatformLoopTest's
 * loopUser()). */
function shopStorageUserWithSite(string $h): User
{
    $user = shopStorageUser($h);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => $h,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

it('addBrand + setProducts persist the relational marker and child rows, not a JSONB map', function () {
    $user = shopStorageUser('rel1');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probe')->andReturn(true);
        // W9: ShopProviderDetector now carries probeMeta()'s result forward
        // instead of calling probe() a second time — id must match fetchBrand()'s
        // id below so ShopBrandIdentity (Unit 4) never derives a different one.
        $m->shouldReceive('probeMeta')->andReturn(['id' => 'rel-brand', 'name' => 'Rel Store']);
        // W9 Unit 4: ShopBrandIdentity::for() now calls brandIdFrom($meta, $origin)
        // — must agree with fetchBrand()'s id below (both read the same 'id').
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        $m->shouldReceive('fetchBrand')->andReturn([
            'id' => 'rel-brand', 'name' => 'Rel Store', 'currency' => 'AUD', 'favicon' => null, 'logo' => null,
        ]);
        $m->shouldReceive('fetchProducts')->andReturn([
            ['productId' => 'p1', 'title' => 'One', 'url' => 'https://rel1.example.com/p1'],
            ['productId' => 'p2', 'title' => 'Two', 'url' => 'https://rel1.example.com/p2'],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://rel1.example.com'])
        ->assertOk();
    actingAsUser($user)->putJson('/api/platforms/shop/brands/rel-brand/selection', ['productIds' => ['p1', 'p2']])
        ->assertOk();

    $conn = IntegrationConnection::where('user_id', $user->id)->whereIn('surface_key', ShopConnections::surfaces())->firstOrFail();
    // The row itself is now just the lifecycle/authorization anchor.
    expect($conn->payload)->toBe(['storage' => 'relational']);

    $brand = ShopBrand::where('brand_id', 'rel-brand')->firstOrFail();
    expect($brand->provider)->toBe('shopify');
    // Task 8: setProducts() writes the selection to content.* only —
    // site.shop_products is no longer touched (see the Task 8 report).
    expect(ShopProduct::where('brand_id', $brand->id)->count())->toBe(0);
    expect(orderedProductIdsFor('rel-brand'))->toBe(['p1', 'p2']);
});

// Re-home Task 2 retired the explicit site.shop_products child delete: that
// table has not been WRITTEN since slice 5a, so the delete only ever cleared
// pre-slice-5a rows, and those go with the DROP (Task 13). The assertion below
// pins the new truth — the brand row goes, the legacy product row is left
// where it is — rather than being deleted, so a re-introduction of the legacy
// delete would show up as a failure rather than as silence.
it('removeBrand hard-deletes the brand row and leaves legacy product rows for the DROP', function () {
    $user = shopStorageUser('rel2');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $brand = ShopBrand::create(['connection_id' => $conn->id, 'brand_id' => 'brand-x', 'provider' => 'shopify', 'url' => 'https://x', 'position' => 0]);
    ShopProduct::create(['brand_id' => $brand->id, 'product_id' => 'p1', 'position' => 0, 'data' => ['productId' => 'p1']]);

    actingAsUser($user)->deleteJson('/api/platforms/shop/brands/brand-x')
        ->assertOk()
        ->assertJsonPath('brands', []);

    expect(ShopBrand::where('connection_id', $conn->id)->count())->toBe(0);
    expect(ShopProduct::where('brand_id', $brand->id)->count())->toBe(1);
});

it('forget deletes the brand rows and soft-deletes the connection', function () {
    $user = shopStorageUser('rel3');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $brand = ShopBrand::create(['connection_id' => $conn->id, 'brand_id' => 'b1', 'provider' => 'shopify', 'url' => 'https://b1', 'position' => 0]);
    ShopProduct::create(['brand_id' => $brand->id, 'product_id' => 'p1', 'position' => 0, 'data' => ['productId' => 'p1']]);

    actingAsUser($user)->deleteJson('/api/platforms/shop')
        ->assertOk()
        ->assertJsonPath('brands', []);

    expect(ShopBrand::where('connection_id', $conn->id)->count())->toBe(0);
    // Same as removeBrand above — the legacy product row survives until the DROP.
    expect(ShopProduct::where('brand_id', $brand->id)->count())->toBe(1);
    expect(IntegrationConnection::find($conn->id))->toBeNull(); // soft-deleted
    expect(IntegrationConnection::withTrashed()->find($conn->id))->not->toBeNull();
});

it('re-adding a brand after forget creates a fresh row with no orphaned products', function () {
    $user = shopStorageUser('rel4');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probe')->andReturn(true);
        // W9: see the 'rel-brand' block above — id mirrors fetchBrand()'s.
        $m->shouldReceive('probeMeta')->andReturn(['id' => 'again-brand', 'name' => 'Again Store']);
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        $m->shouldReceive('fetchBrand')->andReturn([
            'id' => 'again-brand', 'name' => 'Again Store', 'currency' => 'AUD', 'favicon' => null, 'logo' => null,
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://again.example.com'])->assertOk();
    actingAsUser($user)->deleteJson('/api/platforms/shop')->assertOk();

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://again.example.com'])
        ->assertOk()
        ->assertJsonPath('id', 'again-brand');

    $active = IntegrationConnection::where('user_id', $user->id)->whereIn('surface_key', ShopConnections::surfaces())->firstOrFail();
    $brand = ShopBrand::where('brand_id', 'again-brand')->firstOrFail();
    expect(ShopProduct::where('brand_id', $brand->id)->count())->toBe(0);
});

it('addProduct dedupes by productId, keeps newest first, and caps at 20', function () {
    $user = shopStorageUser('ind1');

    $this->mock(GenericShopScraper::class, function ($m) {
        foreach (range(1, 21) as $n) {
            $m->shouldReceive('readProductPage')
                ->with("https://example.com/p{$n}")
                ->andReturn([
                    'outcome' => GenericShopScraper::OUTCOME_PRODUCT,
                    'product' => ['productId' => "p{$n}", 'title' => "Product {$n}", 'url' => "https://example.com/p{$n}"],
                    'storeUrl' => null,
                ]);
        }
    });

    foreach (range(1, 21) as $n) {
        actingAsUser($user)->postJson('/api/platforms/shop/products', ['url' => "https://example.com/p{$n}"])
            ->assertOk();
    }

    $conn = IntegrationConnection::where('user_id', $user->id)->whereIn('surface_key', ShopConnections::surfaces())->firstOrFail();
    $individual = ShopBrand::where('brand_id', 'individual')->firstOrFail();
    expect($individual->is_individual)->toBeTrue();
    // Task 8: addProduct() writes content.* only — site.shop_products is no
    // longer touched (mirrors ShopProductSeeder::seed(), the already-shipped
    // reference implementation for this same reserved-bucket pattern).
    expect(ShopProduct::where('brand_id', $individual->id)->count())->toBe(0);

    $ids = orderedProductIdsFor('individual');
    expect($ids)->toHaveCount(20);
    expect($ids[0])->toBe('p21'); // newest first
    expect($ids)->not->toContain('p1'); // oldest evicted by the 20-cap

    // Re-adding an already-present product moves it to the front without duplicating.
    actingAsUser($user)->postJson('/api/platforms/shop/products', ['url' => 'https://example.com/p10'])
        ->assertOk();
    $idsAfter = orderedProductIdsFor('individual');
    expect($idsAfter[0])->toBe('p10');
    expect(array_count_values($idsAfter)['p10'])->toBe(1);
    expect(count($idsAfter))->toBe(20);
});

it('removeProduct drops the individual bucket once it has no products left', function () {
    $user = shopStorageUser('ind2');

    $this->mock(GenericShopScraper::class, fn ($m) => $m->shouldReceive('readProductPage')
        ->with('https://example.com/only')
        ->andReturn([
            'outcome' => GenericShopScraper::OUTCOME_PRODUCT,
            'product' => ['productId' => 'only', 'title' => 'Only', 'url' => 'https://example.com/only'],
            'storeUrl' => null,
        ]));

    actingAsUser($user)->postJson('/api/platforms/shop/products', ['url' => 'https://example.com/only'])->assertOk();

    $conn = IntegrationConnection::where('user_id', $user->id)->whereIn('surface_key', ShopConnections::surfaces())->firstOrFail();
    $individual = ShopBrand::where('brand_id', 'individual')->firstOrFail();

    actingAsUser($user)->deleteJson('/api/platforms/shop/products/only')
        ->assertOk()
        ->assertJsonPath('brands', []);

    expect(ShopBrand::where('id', $individual->id)->exists())->toBeFalse();
    expect(ShopProduct::where('brand_id', $individual->id)->count())->toBe(0);
});

// RE-BASED by slice 5b Task 8 (2026-08-13). This case used to freeze the whole
// brand+product object the public endpoint emitted, value for value, against
// the pre-relational contract. That contract is retired: `shop` publishes an
// EMPTY payload and the sitepage reads `profile.pools.shop` instead
// (ShopPoolPayloadTest freezes THAT shape). What is worth keeping here is the
// end-to-end path — a real connect + a real selection through the HTTP
// endpoints — proving the retirement holds for a genuinely populated store and
// not just a hand-built fixture.
it('publishes an empty shop payload even after a real connect + selection through the endpoints', function () {
    $user = shopStorageUser('pubshop');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probe')->andReturn(true);
        // W9: see the 'rel-brand' block above — id mirrors fetchBrand()'s.
        $m->shouldReceive('probeMeta')->andReturn(['id' => 'pub-brand', 'name' => 'Pub Store']);
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        $m->shouldReceive('fetchBrand')->andReturn([
            'id' => 'pub-brand', 'name' => 'Pub Store', 'currency' => 'AUD',
            'favicon' => 'https://pub.example.com/favicon.ico', 'logo' => 'https://pub.example.com/logo.png',
        ]);
        // createdAt is a DETERMINISM PIN, not a shape change: content.* always
        // emits the key (from f_published, falling back to items.first_seen_at),
        // so without a fixed input the assertion below would carry a wall-clock
        // timestamp.
        $m->shouldReceive('fetchProducts')->andReturn([
            ['productId' => 'p1', 'title' => 'Mug', 'url' => 'https://pub.example.com/p1', 'available' => true, 'price' => '10.00', 'currency' => 'AUD', 'createdAt' => '2026-01-05T00:00:00Z'],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://pub.example.com', 'discountCode' => 'SAVE10'])
        ->assertOk();
    actingAsUser($user)->putJson('/api/platforms/shop/brands/pub-brand/selection', ['productIds' => ['p1']])
        ->assertOk();

    $res = $this->getJson('/api/public/profiles/pubshop/platforms');
    $res->assertOk();

    // The envelope survives; the contents are gone. Convergence Phase 6: the
    // store's anchor is a shopify.store row now, so it groups under the BRAND
    // key rather than the retired 'shop' pseudo-key.
    $row = $res->json('data.platforms.shopify.0');
    expect($row)->toHaveKeys(['resourceId', 'payload', 'lastRefreshedAt'])
        ->and($row['payload'])->toBe([]);

    // The connect + selection really landed in content.* — the empty payload
    // is the retirement, not a store that was never written.
    expect(DB::table('content.storefronts')->count())->toBe(1);
    expect(DB::table('content.f_catalog')->where('sku', 'p1')->exists())->toBeTrue();

    // Nothing the old contract carried rides anywhere in the body.
    //
    // 'pub-brand' is NOT asserted absent any more, and the change is named
    // rather than quietly dropped: convergence Phase 6 gives each store its own
    // connection keyed by its brand id, so that id is now the row's
    // `resourceId` — envelope, not payload. It is the store's own public
    // identifier (Shopify serves it from the storefront's meta.json), the
    // payload below is still `[]`, and every field the retired contract
    // actually leaked is still asserted gone.
    $body = $res->getContent();
    expect($body)->not->toContain('Pub Store');
    expect($body)->not->toContain('SAVE10');
    expect($body)->not->toContain('Mug');
    expect($body)->not->toContain('linkMode');
    expect($body)->not->toContain('popularityRank');
});

// The handle-keyed popularityRank guard that lived here MOVED to
// tests/Feature/Shop/ShopStoreReadLaneTest.php with re-home Task 2. It was
// written against ShopBrand::toBrandArray(), which that task deleted; the rule
// itself is unchanged and is now asserted through ShopContentReader::brandMap()
// on real content.* rows instead of two in-memory models.

// ── CCG-102 on this endpoint, RE-BASED by slice 5b Task 8 ─────────────────
//
// CCG-102 wrapped PublicIntegrationController's shop-product popularity read
// in rememberLocked, because ranks recompute on a 15-minute cadence and edge
// purging does nothing to bound their cost. Slice 5b deleted the read outright,
// which is the strictly better version of that fix: ZERO reads per request
// rather than one per cache window. PoolResolver carries its own copy of the
// same single-flight cache for the profile payload that serves the ranks now
// (same key, same TTL) — that lane's coverage lives with the pool tests.
//
// Kept, inverted, rather than deleted: it is the only guard that this endpoint
// makes no per-request popularity read, which is exactly what CCG-102 was
// about and exactly what a re-added shop block would silently undo.
it('makes ZERO popularity reads on the public platforms endpoint and publishes no shop payload', function () {
    setupContentPopularityScoresTable();
    $user = shopStorageUserWithSite('pubshopcache');
    $site = DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->first();

    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $brand = ShopBrand::create(['connection_id' => $conn->id, 'brand_id' => 'cache-brand', 'provider' => 'shopify', 'url' => 'https://cache.example.com', 'position' => 0]);
    // The fixture lands through the same writer addBrand()/setProducts() use,
    // so a re-added shop block would have real data to publish and re-rank.
    $collectionId = app(ShopContentWriter::class)->upsertStore($brand->toStoreRecord(), (string) $user->id);
    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [[
        'productId' => 'p1', 'handle' => 'mug', 'title' => 'Mug', 'url' => 'https://cache.example.com/p1',
        'price' => null, 'currency' => null, 'available' => true, 'image' => null, 'images' => [], 'variants' => [],
    ]], null);

    // content_popularity_scores keys shop_product by product HANDLE (test at
    // "keys public popularityRank by product HANDLE" above pins this).
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        'id' => (string) Str::uuid(), 'site_id' => $site->id, 'content_type' => 'shop_product',
        'content_key' => 'mug', 'score' => 9.5, 'rank' => 1, 'computed_at' => now()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->enableQueryLog();
    $first = $this->getJson('/api/public/profiles/pubshopcache/platforms')->assertOk();
    $second = $this->getJson('/api/public/profiles/pubshopcache/platforms')->assertOk();
    $log = DB::connection('pgsql')->getQueryLog();
    DB::connection('pgsql')->disableQueryLog();

    // Both requests are identical, and neither carries a rank — or a product.
    expect($first->json('data.platforms.shopify.0.payload'))->toBe([]);
    expect($second->json())->toEqual($first->json());
    expect($first->getContent())->not->toContain('popularityRank');

    // The rank row is real and would have been found: content.* holds the
    // product it is keyed to, by handle.
    expect(DB::table('content.f_catalog')->where('handle', 'mug')->exists())->toBeTrue();

    // The proof: content_popularity_scores is not read AT ALL by this endpoint
    // any more — zero queries across both HTTP calls, where CCG-102's cached
    // version made one.
    $rankQueries = array_filter($log, fn ($q) => str_contains($q['query'], 'content_popularity_scores'));
    expect(count($rankQueries))->toBe(0);
});

// ── FOUND-25 regression: edge-cache purge must survive past the first write ──
//
// IntegrationConnectionObserver only purges on wasRecentlyCreated / a
// payload change / an is_active flip. Every ShopController mutation now
// writes the SAME static marker ({"storage":"relational"}), so after the
// first connect the payload never changes again — without an explicit
// refresh, a second brand/product edit would silently stop purging the
// sitepage edge cache (stale shop data for up to the 24h TTL).

it('purges the edge cache on a SECOND shop mutation, not just the first connect', function () {
    Bus::fake();
    $user = shopStorageUserWithSite('purge2');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probe')->andReturn(true);
        // W9: see the 'rel-brand' block above — id mirrors fetchBrand()'s.
        $m->shouldReceive('probeMeta')->andReturn(['id' => 'purge-brand', 'name' => 'Purge Store']);
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        $m->shouldReceive('fetchBrand')->andReturn([
            'id' => 'purge-brand', 'name' => 'Purge Store', 'currency' => 'AUD', 'favicon' => null, 'logo' => null,
        ]);
    });

    // First connect always purges (new connection row, wasRecentlyCreated) —
    // not the behavior under test.
    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://purge2.example.com'])
        ->assertOk();

    // CloudflareCachePurgeJob is ShouldBeUnique: PendingDispatch::__destruct()
    // acquires its uniqueness lock BEFORE even reaching the (real or faked)
    // Dispatcher, so it's enforced regardless of Bus::fake() — and the array
    // cache store keeps locks in a SEPARATE `locks` property that plain
    // Cache::flush() does not touch (Illuminate\Cache\ArrayLock/ArrayStore).
    // Reset it directly to release the lock the first write took, so the
    // SECOND write's dispatch attempt isn't swallowed by the coalescing
    // window this test isn't exercising (that's covered separately by
    // PlatformLoopTest's connect+disconnect-coalesce assertion).
    Cache::getStore()->locks = [];

    // Re-fake to discard the first write's recorded dispatches — isolates the
    // assertion to whatever the SECOND mutation triggers.
    Bus::fake();

    // Second mutation on the SAME connection: the marker payload is
    // unchanged and is_active stays true, so the observer's own gate misses
    // entirely. Before the fix, this dispatched nothing.
    actingAsUser($user)->patchJson('/api/platforms/shop/brands/purge-brand', ['discountCode' => 'SAVE20'])
        ->assertOk();

    Bus::assertDispatched(CloudflareCachePurgeJob::class);
});

// Final review F6: spec §5.2 lists "every write path bumps build state, moves
// sites.updated_at, and dispatches a purge" as a deliverable, and NO test
// covered it for any ShopController endpoint — bumpSiteCache()'s body could be
// deleted with a green suite. The test above only proves lane 3, which the
// shared refresher owns; the two lanes the controller owns itself were unpinned.
it('a write endpoint fires all three cache lanes', function () {
    Bus::fake();
    $user = shopStorageUserWithSite('lanes3');
    modesBrandFor($user);

    // Laravel binds timestamps at SECOND precision (same hazard documented in
    // ShopBackfillerTest) — backdate so the touch below cannot pass or fail on
    // wall-clock luck. Done AFTER the connect write, which touches it too.
    DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)
        ->update(['updated_at' => now()->subMinute()]);
    $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $user->id)->value('id');
    $before = DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at');
    $revisionBefore = (int) DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision');

    // Re-fake so the assertion sees only THIS write's dispatches, and release
    // the ShouldBeUnique lock the connect write took (see the note above).
    Cache::getStore()->locks = [];
    Bus::fake();

    // updateBrand with no selection change: writes nothing through
    // writeManualItem(), so bumpSiteCache() is the sole source of lanes 1 and 2.
    actingAsUser($user)->patchJson('/api/platforms/shop/brands/modes-brand', ['discountCode' => 'LANES3'])
        ->assertOk();

    Bus::assertDispatched(CloudflareCachePurgeJob::class);
    expect(DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->value('updated_at'))
        ->not->toBe($before);
    expect((int) DB::connection('pgsql')->table('site.site_build_state')
        ->where('site_id', $siteId)->value('content_revision'))->toBeGreaterThan($revisionBefore);
});

// ── Store modes (selection_mode / link_mode / referral_query) ──────────────

function modesBrandFor(User $user): void
{
    test()->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probe')->andReturn(true);
        // W9: see the 'rel-brand' block above — id mirrors fetchBrand()'s.
        $m->shouldReceive('probeMeta')->andReturn(['id' => 'modes-brand', 'name' => 'Modes Store']);
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        $m->shouldReceive('fetchBrand')->andReturn([
            'id' => 'modes-brand', 'name' => 'Modes Store', 'currency' => 'AUD',
            'favicon' => null, 'logo' => null,
        ]);
        // Catalog newest-first by createdAt: p3 newest, p1 oldest.
        $m->shouldReceive('fetchProducts')->andReturn([
            ['productId' => 'p1', 'title' => 'Old', 'url' => 'https://m.example.com/p1', 'available' => true, 'price' => '1.00', 'currency' => 'AUD', 'createdAt' => '2026-01-01T00:00:00Z', 'variants' => []],
            ['productId' => 'p3', 'title' => 'Newest', 'url' => 'https://m.example.com/p3', 'available' => true, 'price' => '3.00', 'currency' => 'AUD', 'createdAt' => '2026-07-01T00:00:00Z', 'variants' => []],
            ['productId' => 'p2', 'title' => 'Mid', 'url' => 'https://m.example.com/p2', 'available' => true, 'price' => '2.00', 'currency' => 'AUD', 'createdAt' => '2026-04-01T00:00:00Z', 'variants' => []],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://m.example.com'])->assertOk();
}

it('updateBrand persists selectionMode/linkMode and parses the referral URL to its query suffix', function () {
    $user = shopStorageUser('modes1');
    modesBrandFor($user);

    $res = actingAsUser($user)->patchJson('/api/platforms/shop/brands/modes-brand', [
        'linkMode' => 'checkout',
        'referralUrl' => 'https://m.example.com/landing?ref=abc123&utm_source=friend',
    ]);
    $res->assertOk()
        ->assertJsonPath('linkMode', 'checkout')
        ->assertJsonPath('referralQuery', 'ref=abc123&utm_source=friend');

    $brand = ShopBrand::where('brand_id', 'modes-brand')->firstOrFail();
    expect($brand->link_mode)->toBe('checkout')
        ->and($brand->referral_query)->toBe('ref=abc123&utm_source=friend')
        ->and($brand->selection_mode)->toBe('manual');

    // Bare query form + clearing.
    actingAsUser($user)->patchJson('/api/platforms/shop/brands/modes-brand', ['referralUrl' => 'ref=zzz'])
        ->assertOk()->assertJsonPath('referralQuery', 'ref=zzz');
    actingAsUser($user)->patchJson('/api/platforms/shop/brands/modes-brand', ['referralUrl' => ''])
        ->assertOk()->assertJsonPath('referralQuery', '');
    // Prose / URL-without-query stores nothing.
    actingAsUser($user)->patchJson('/api/platforms/shop/brands/modes-brand', ['referralUrl' => 'https://m.example.com/landing'])
        ->assertOk()->assertJsonPath('referralQuery', '');
});

// The referral suffix is appended AFTER the merchant's own discount by
// ShopOutboundUrl::compose(), and a store reading a repeated key takes the LAST
// one — so before 2026-08-14 a referral of `ref=abc&discount=FREE` sailed through
// referralQueryFrom() (it has an `=`, no space, no `://`) and overrode the
// merchant's discount code on every product link. Sanitised at the write, not
// papered over by the composer.
it('updateBrand strips referral params that would override the composed link', function () {
    $user = shopStorageUser('modesref');
    modesBrandFor($user);

    // The reserved key goes, the genuine referral survives.
    actingAsUser($user)->patchJson('/api/platforms/shop/brands/modes-brand', [
        'referralUrl' => 'ref=abc&discount=FREE',
    ])->assertOk()->assertJsonPath('referralQuery', 'ref=abc');

    expect(ShopBrand::where('brand_id', 'modes-brand')->firstOrFail()->referral_query)
        ->toBe('ref=abc');

    // Case-insensitive: Shopify does not care, so neither do we.
    actingAsUser($user)->patchJson('/api/platforms/shop/brands/modes-brand', [
        'referralUrl' => 'https://m.example.com/l?ref=abc&Discount=FREE&add-to-cart=99',
    ])->assertOk()->assertJsonPath('referralQuery', 'ref=abc');

    // A referral that is ONLY a reserved param stores nothing at all.
    actingAsUser($user)->patchJson('/api/platforms/shop/brands/modes-brand', [
        'referralUrl' => 'discount=FREE',
    ])->assertOk()->assertJsonPath('referralQuery', '');

    // `#` is encoded rather than passed through — appended raw it would turn the
    // rest of the composed URL into a fragment the store never sees.
    actingAsUser($user)->patchJson('/api/platforms/shop/brands/modes-brand', [
        'referralUrl' => 'ref=abc#discount=FREE',
    ])->assertOk()->assertJsonPath('referralQuery', 'ref=abc%23discount%3DFREE');
});

it('selectionMode=latest syncs the selection to the newest products immediately', function () {
    $user = shopStorageUser('modes2');
    modesBrandFor($user);

    $res = actingAsUser($user)->patchJson('/api/platforms/shop/brands/modes-brand', ['selectionMode' => 'latest']);
    $res->assertOk()
        // Fix round 1, I2: a PATCH echoes back the mode the client set. The
        // GET side still reports the derived constant 'manual' (Task 7's
        // sanctioned divergence — ShopContentReader gap 3); applying that to
        // the mutation RESPONSE would make a dashboard toggle visibly flip
        // back the moment it was switched.
        ->assertJsonPath('selectionMode', 'latest')
        ->assertJsonPath('latestSyncPending', false);

    // Task 6: syncLatest() now reconciles into content.*, not site.shop_products.
    // createdAt DESC: newest first, capped at the default count (8 > 3 → all).
    expect(orderedProductIdsFor('modes-brand'))->toBe(['p3', 'p2', 'p1']);
});

// Fix round 1, M2: renamed. The old name ("…flips a latest-mode brand back to
// manual") promised an assertion this test no longer makes — selection_mode is
// vestigial and setProducts() doesn't write it. What it does prove is that a
// manual PUT after selectionMode=latest wins: the stored selection is the
// user's, not the sync's.
it('a manual selection PUT after selectionMode=latest stores the user\'s chosen products', function () {
    $user = shopStorageUser('modes3');
    modesBrandFor($user);

    actingAsUser($user)->patchJson('/api/platforms/shop/brands/modes-brand', ['selectionMode' => 'latest'])->assertOk();
    actingAsUser($user)->putJson('/api/platforms/shop/brands/modes-brand/selection', ['productIds' => ['p1']])->assertOk();

    // Task 8: setProducts() no longer writes the legacy selection_mode
    // column at all (it's vestigial now — content.* has no per-brand
    // selectionMode; see ShopContentReader gap 3) — the real, meaningful
    // assertion is the product selection itself, in content.*.
    expect(orderedProductIdsFor('modes-brand'))->toBe(['p1']);
});

// #SEM-1: ShopFetch::fetch() never read selection_mode (a comment's own
// evidence it never guarded anything) — a scheduled sync wholesale-replaced a
// hand-picked selection with the store's newest N products. The guard is now
// products_curated_at, stamped by setProducts(). These three tests run the
// REAL ShopFetch (resolved from the container) and the REAL ShopCatalog::
// syncLatest() against modesBrandFor()'s known p1/p2/p3 catalog — proving the
// product ROWS survive, not just a flag.
it('a hand-picked selection survives a scheduled ShopFetch run while the global auto-latest is on', function () {
    $user = shopStorageUser('sem1a');
    modesBrandFor($user);

    actingAsUser($user)->putJson('/api/platforms/shop/brands/modes-brand/selection', ['productIds' => ['p1']])
        ->assertOk();

    $conn = IntegrationConnection::where('user_id', $user->id)->whereIn('surface_key', ShopConnections::surfaces())->firstOrFail();
    // Task 8: setProducts() stamps content.storefronts.products_curated_at
    // directly now — isCurated() (what ShopFetch actually calls) reads that
    // column first, not the legacy site.shop_brands one setProducts() no
    // longer writes.
    expect(curatedAtFor('modes-brand'))->not->toBeNull();

    // Global shop_auto_latest defaults ON (no site row = null → treated as on
    // by ShopFetch's own gate) — the curated brand must still be skipped.
    expect(fn () => app(ShopFetch::class)->fetch($conn->fresh()))
        ->toThrow(FetchNotModifiedException::class);

    expect(orderedProductIdsFor('modes-brand'))->toBe(['p1']);
});

it('a non-curated brand still syncs to the newest products on a scheduled ShopFetch run', function () {
    $user = shopStorageUser('sem1b');
    modesBrandFor($user);

    $conn = IntegrationConnection::where('user_id', $user->id)->whereIn('surface_key', ShopConnections::surfaces())->firstOrFail();
    $brand = ShopBrand::where('brand_id', 'modes-brand')->firstOrFail();
    expect($brand->products_curated_at)->toBeNull();

    // Not mocking ShopCatalog away and asserting the flag instead of the rows
    // is exactly the failure mode this pins: a fix that (wrongly) blocks every
    // brand from syncing would pass a flag-only assertion but fail this one.
    app(ShopFetch::class)->fetch($conn->fresh());

    // Task 6: syncLatest() now reconciles into content.*, not site.shop_products.
    expect(orderedProductIdsFor('modes-brand'))->toBe(['p3', 'p2', 'p1']);
});

it('selectionMode=latest clears products_curated_at, opting the brand back into scheduled sync', function () {
    $user = shopStorageUser('sem1c');
    modesBrandFor($user);

    actingAsUser($user)->putJson('/api/platforms/shop/brands/modes-brand/selection', ['productIds' => ['p1']])
        ->assertOk();
    // Task 8: setProducts() stamps content.storefronts.products_curated_at
    // directly — the legacy site.shop_brands column is no longer written.
    expect(curatedAtFor('modes-brand'))->not->toBeNull();

    actingAsUser($user)->patchJson('/api/platforms/shop/brands/modes-brand', ['selectionMode' => 'latest'])
        ->assertOk();
    expect(curatedAtFor('modes-brand'))->toBeNull();
    // Task 6 fix round 1, Finding 3's transitional over-sync is CLOSED by
    // Task 8, exactly as this comment block anticipated (kept for the
    // history): setProducts() now calls upsertStore() + syncStore() itself,
    // so the curated PUT above already minted modes-brand's content.*
    // collection with ONE collection_items row (p1) — syncLatest()'s
    // count-preserving read (DB::table('content.collection_items')->
    // where('collection_id',...)->count()) now finds that 1, not an
    // empty-collection miss falling back to DEFAULT_LATEST_COUNT (8). Back
    // to the true pre-Task-6 behaviour: only the single newest product.
    expect(orderedProductIdsFor('modes-brand'))->toBe(['p3']);

    // The opt-back-in proof: the NEXT scheduled fetch() also runs (not
    // skipped) now that products_curated_at is null again — a curated brand
    // would have thrown FetchNotModifiedException instead (see the sibling
    // "survives a scheduled ShopFetch run" test above).
    $conn = IntegrationConnection::where('user_id', $user->id)->whereIn('surface_key', ShopConnections::surfaces())->firstOrFail();
    $result = app(ShopFetch::class)->fetch($conn->fresh());
    expect($result)->toBe(['storage' => 'relational']);
});

it('rejects invalid mode values', function () {
    $user = shopStorageUser('modes4');
    modesBrandFor($user);

    actingAsUser($user)->patchJson('/api/platforms/shop/brands/modes-brand', ['selectionMode' => 'auto'])
        ->assertStatus(422);
    actingAsUser($user)->patchJson('/api/platforms/shop/brands/modes-brand', ['linkMode' => 'cart'])
        ->assertStatus(422);
});

// W9 unit 1: content-proxy for the connect_status column — nothing reads/writes
// it yet, but the column must be nullable with no DB default under both
// SQLite (tests) and Postgres (prod), since a green SQLite suite proves nothing
// about the CHECK/nullability itself (see CheckConstraintsTest for the real thing).
it('connect_status column is nullable with no default and round-trips a written value', function () {
    $user = shopStorageUser('connstatus1');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $pending = ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'pending-brand', 'provider' => 'shopify',
        'url' => 'https://pending.example.com', 'position' => 0, 'connect_status' => 'pending',
    ]);
    $settled = ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'settled-brand', 'provider' => 'shopify',
        'url' => 'https://settled.example.com', 'position' => 1,
    ]);

    expect($pending->fresh()->connect_status)->toBe('pending');
    expect($settled->fresh()->connect_status)->toBeNull();
});
