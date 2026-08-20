<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\Strategies\Fetch\FetchNotModifiedException;
use App\Services\Platforms\Strategies\Fetch\ShopFetch;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// FOUND-25: the shop brand+product map moved out of the single
// site.platform_connections.payload JSONB cell into dedicated storage. These
// tests cover the storage shape itself (not just the API responses already
// covered elsewhere): the connection row's payload collapses to a static
// marker, the store rows are the source of truth, and lifecycle operations
// (remove/forget/re-add) don't leave orphans.
//
// That storage was site.shop_brands / site.shop_products; the shop re-home
// moved it to content.collections + content.storefronts (+ content.items for
// the products) and DROPPED both legacy tables — 20260819000200 and
// 20260819000210. The invariants below are unchanged; the table they are
// asserted against is not.

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

/**
 * One connected store for $user, plus its own anchor connection.
 *
 * Replaces the `site.shop_brands` + `site.shop_products` fixture rows these
 * tests used to build (and then mirror into content.* through
 * ShopContentWriter). Both tables are dropped, so there is one write where
 * there were two — through the real writer, the same lane addBrand() uses.
 *
 * $overrides are StoreRecord property names, not the legacy column names.
 */
function shopStorageStore(User $user, string $externalRef, array $overrides = []): StoreRecord
{
    $record = (new StoreRecord(
        externalRef: $externalRef,
        provider: 'shopify',
        position: 0,
        url: 'https://'.$externalRef,
        discountCode: '',
    ))->with($overrides);

    app(ShopConnections::class)->anchor($user, $record->provider, $externalRef);
    app(ShopContentWriter::class)->upsertStore($record, (string) $user->id);

    return app(ShopConnections::class)->store($user, $externalRef);
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

    // Re-home Task 7: addBrand() writes content.collections +
    // content.storefronts through ShopContentWriter::upsertStore() — brand_id
    // is `external_ref` there. The companion "…and site.shop_brands stayed
    // empty" assertion retired with the table (20260819000210): there is only
    // one place a store can land now, so the positive assertion IS the whole
    // guarantee.
    expect(DB::table('content.storefronts')->where('external_ref', 'rel-brand')->value('provider'))
        ->toBe('shopify');
    // Task 8: setProducts() writes the selection to content.* (same reasoning
    // for the dropped site.shop_products half — 20260819000200).
    expect(orderedProductIdsFor('rel-brand'))->toBe(['p1', 'p2']);
});

// Re-home Task 2 retired the EXPLICIT site.shop_products child delete, and the
// re-home then dropped both legacy tables outright. This test used to pin
// "removeBrand issues no DELETE against shop_products"; that assertion cannot
// fail against a table which does not exist, so it is MOVED to its positive
// counterpart — the DELETEs this endpoint issues target the content.* store.
//
// Still asserted on the QUERY, not only on a surviving row count, for the
// original reason: a row count is an SQLite-only truth wherever ON DELETE
// CASCADE is involved (SQLite does not enforce FKs, Postgres does), so pinning
// which statements ran is the driver-independent half.
it('removeBrand retires the content.* store row, deleting it rather than orphaning it', function () {
    $user = shopStorageUser('rel2');
    $store = shopStorageStore($user, 'brand-x', ['url' => 'https://x']);
    makeShopStoreProduct($store, ['productId' => 'p1']);

    $deletes = [];
    DB::listen(function ($q) use (&$deletes): void {
        if (str_starts_with(strtolower(ltrim($q->sql)), 'delete')) {
            $deletes[] = $q->sql;
        }
    });

    actingAsUser($user)->deleteJson('/api/platforms/shop/brands/brand-x')
        ->assertOk()
        ->assertJsonPath('brands', []);

    expect(DB::table('content.storefronts')->where('external_ref', 'brand-x')->count())->toBe(0)
        ->and(DB::table('content.collections')->where('kind', 'storefront')->count())->toBe(0)
        // Non-vacuous: the endpoint DOES delete, so an empty $deletes would
        // mean the listener never fired rather than that the rows went.
        ->and($deletes)->not->toBeEmpty()
        // …and the delete lands on the storefront row itself, which is the
        // assertion that replaced the retired shop_products one.
        ->and(collect($deletes)->filter(fn (string $sql) => str_contains($sql, 'storefronts')))
        ->not->toBeEmpty();
});

it('forget deletes the content.* stores and soft-deletes the connection', function () {
    $user = shopStorageUser('rel3');
    $store = shopStorageStore($user, 'b1', ['url' => 'https://b1']);
    makeShopStoreProduct($store, ['productId' => 'p1']);
    $conn = app(ShopConnections::class)->anchorFor($user, 'b1');

    $deletes = [];
    DB::listen(function ($q) use (&$deletes): void {
        if (str_starts_with(strtolower(ltrim($q->sql)), 'delete')) {
            $deletes[] = $q->sql;
        }
    });

    actingAsUser($user)->deleteJson('/api/platforms/shop')
        ->assertOk()
        ->assertJsonPath('brands', []);

    expect(DB::table('content.collections')->where('kind', 'storefront')->count())->toBe(0);
    // Same as removeBrand above — a query assertion, not a row count, because
    // Postgres's ON DELETE CASCADE removes the child rows and SQLite does not.
    // The retired "…and never touches shop_products" half is replaced by its
    // positive counterpart: the DELETEs land on the content.* store.
    expect($deletes)->not->toBeEmpty()
        ->and(collect($deletes)->filter(fn (string $sql) => str_contains($sql, 'storefronts')))
        ->not->toBeEmpty();
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
    // Re-home Task 7: the re-added store is a content.* row, so "no orphaned
    // products" reads as "its fresh collection carries no items" — the first
    // forget() retired whatever the first connect had linked.
    $collectionId = DB::table('content.storefronts')->where('external_ref', 'again-brand')->value('collection_id');
    expect($collectionId)->not->toBeNull();
    expect(DB::table('content.collection_items')->where('collection_id', $collectionId)->count())->toBe(0);
});

it('addProduct dedupes by productId, keeps newest first, and caps at 50', function () {
    $user = shopStorageUser('ind1');

    $this->mock(GenericShopScraper::class, function ($m) {
        foreach (range(1, 51) as $n) {
            $m->shouldReceive('readProductPage')
                ->with("https://example.com/p{$n}")
                ->andReturn([
                    'outcome' => GenericShopScraper::OUTCOME_PRODUCT,
                    'product' => ['productId' => "p{$n}", 'title' => "Product {$n}", 'url' => "https://example.com/p{$n}"],
                    'storeUrl' => null,
                ]);
        }
    });

    foreach (range(1, 51) as $n) {
        actingAsUser($user)->postJson('/api/platforms/shop/products', ['url' => "https://example.com/p{$n}"])
            ->assertOk();
    }

    $conn = IntegrationConnection::where('user_id', $user->id)->whereIn('surface_key', ShopConnections::surfaces())->firstOrFail();
    // Re-home Task 7: the reserved bucket is a content.* store like any other
    // — is_individual keeps its name there. Cast because SQLite hands the
    // column back as 0/1 where Postgres returns a real boolean.
    $individual = DB::table('content.storefronts')->where('external_ref', 'individual')->first();
    expect($individual)->not->toBeNull();
    expect((bool) $individual->is_individual)->toBeTrue();
    // Task 8: addProduct() writes content.* only (mirrors
    // ShopProductSeeder::seed(), the already-shipped reference implementation
    // for this same reserved-bucket pattern). The paired "…and neither legacy
    // table was written" assertions retired with those tables; the content.*
    // ordering assertions below are the guarantee now.
    $ids = orderedProductIdsFor('individual');
    expect($ids)->toHaveCount(50);
    expect($ids[0])->toBe('p51'); // newest first
    expect($ids)->not->toContain('p1'); // oldest evicted by the 50-cap (T9)

    // Re-adding an already-present product moves it to the front without duplicating.
    actingAsUser($user)->postJson('/api/platforms/shop/products', ['url' => 'https://example.com/p10'])
        ->assertOk();
    $idsAfter = orderedProductIdsFor('individual');
    expect($idsAfter[0])->toBe('p10');
    expect(array_count_values($idsAfter)['p10'])->toBe(1);
    expect(count($idsAfter))->toBe(50);
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
    // Re-home Task 7: the bucket is a content.collections + content.storefronts
    // pair now — capture its id before the delete so the assertion below is
    // about THAT row disappearing, not merely about a lookup missing.
    $collectionId = DB::table('content.storefronts')->where('external_ref', 'individual')->value('collection_id');
    expect($collectionId)->not->toBeNull();

    actingAsUser($user)->deleteJson('/api/platforms/shop/products/only')
        ->assertOk()
        ->assertJsonPath('brands', []);

    expect(DB::table('content.storefronts')->where('collection_id', $collectionId)->exists())->toBeFalse();
    expect(DB::table('content.collections')->where('id', $collectionId)->exists())->toBeFalse();
    // The bucket's product goes with it — the content.* counterpart of the
    // retired `ShopProduct::count()` line, which pinned a table that no longer
    // exists. Not a hard delete of content.items (analytics.item_views
    // references those ids): retireStore() drops the LINK and stamps
    // removed_at, which is what "the bucket is gone" means here.
    expect(DB::table('content.collection_items')->where('collection_id', $collectionId)->count())->toBe(0);
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

    $res = $this->getJson('/api/public/profiles/pubshop/integrations');
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

    // The fixture lands through the same writer addBrand()/setProducts() use,
    // so a re-added shop block would have real data to publish and re-rank.
    $store = shopStorageStore($user, 'cache-brand', ['url' => 'https://cache.example.com']);
    $collectionId = (string) $store->collectionId;
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
    $first = $this->getJson('/api/public/profiles/pubshopcache/integrations')->assertOk();
    $second = $this->getJson('/api/public/profiles/pubshopcache/integrations')->assertOk();
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

    // Laravel binds timestamps at SECOND precision (the hazard that used to be
    // documented in ShopBackfillerTest, deleted with the backfiller) — backdate
    // so the touch below cannot pass or fail on wall-clock luck. Done AFTER the
    // connect write, which touches it too.
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

it('updateBrand parses the referral URL to its query suffix', function () {
    $user = shopStorageUser('modes1');
    modesBrandFor($user);

    // The per-brand linkMode key is GONE from the wire (2026-08-19) — link
    // mode is one site-wide value on /platforms/shop/settings, pinned by
    // ShopGlobalSettingsTest.
    $res = actingAsUser($user)->patchJson('/api/platforms/shop/brands/modes-brand', [
        'referralUrl' => 'https://m.example.com/landing?ref=abc123&utm_source=friend',
    ]);
    $res->assertOk()
        ->assertJsonMissingPath('linkMode')
        ->assertJsonPath('referralQuery', 'ref=abc123&utm_source=friend');

    // Re-home Task 7: referral_query keeps its storage on
    // content.storefronts.
    expect(DB::table('content.storefronts')->where('external_ref', 'modes-brand')->value('referral_query'))
        ->toBe('ref=abc123&utm_source=friend');

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

    expect(DB::table('content.storefronts')->where('external_ref', 'modes-brand')->value('referral_query'))
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
    // Opt-in (2026-08-17): stores mint with auto_sync_latest OFF now, and the
    // scheduled fetch is gated on it — this test is about the SYNC path, so
    // the fixture opts the store in the way an owner would.
    $conn->display_settings = ['auto_sync_latest' => true] + (array) ($conn->display_settings ?? []);
    $conn->save();
    // #SEM-1's flag lives on content.storefronts now (curatedAtFor()) — the
    // legacy site.shop_brands column is written by nothing.
    expect(curatedAtFor('modes-brand'))->toBeNull();

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
    // Opt-in (2026-08-17): the fetch is also gated on auto_sync_latest and
    // stores mint with it OFF — opted in here so this asserts the
    // products_curated_at gate alone, as it always did.
    $conn->display_settings = ['auto_sync_latest' => true] + (array) ($conn->display_settings ?? []);
    $conn->save();
    $result = app(ShopFetch::class)->fetch($conn->fresh());
    expect($result)->toBe(['storage' => 'relational']);
});

it('rejects invalid mode values', function () {
    $user = shopStorageUser('modes4');
    modesBrandFor($user);

    actingAsUser($user)->patchJson('/api/platforms/shop/brands/modes-brand', ['selectionMode' => 'auto'])
        ->assertStatus(422);
    // linkMode left the request contract 2026-08-19 — an unknown key is
    // simply ignored, never validated.
    actingAsUser($user)->patchJson('/api/platforms/shop/brands/modes-brand', ['linkMode' => 'cart'])
        ->assertOk()->assertJsonMissingPath('linkMode');
});

// W9 unit 1: content-proxy for the connect_status column — the column must be
// nullable with no DB default under both SQLite (tests) and Postgres (prod),
// since a green SQLite suite proves nothing about the CHECK/nullability itself
// (see CheckConstraintsTest for the real thing).
//
// MOVED from site.shop_brands.connect_status to its replacement,
// content.storefronts.connect_status. The column crossed over with its
// guarantee intact: 20260819000120 added storefronts_connect_status_check with
// the identical vocabulary (NULL | pending | failed) BEFORE 20260819000210
// dropped the original, and ConstraintVocabularyLockstepTest pins that
// vocabulary. A store is 'pending' from ShopController::addBrand() arming the
// deferred poll, and back to null (settle) or 'failed' (terminal) from
// ShopBrandConnectJob.
it('connect_status is nullable with no default and round-trips a written value', function () {
    $user = shopStorageUser('connstatus1');

    shopStorageStore($user, 'pending-brand', [
        'url' => 'https://pending.example.com', 'connectStatus' => 'pending',
    ]);
    shopStorageStore($user, 'settled-brand', [
        'url' => 'https://settled.example.com', 'position' => 1,
    ]);

    expect(DB::table('content.storefronts')->where('external_ref', 'pending-brand')->value('connect_status'))
        ->toBe('pending');
    // No DB default: a store written without one reads back null, not ''.
    expect(DB::table('content.storefronts')->where('external_ref', 'settled-brand')->value('connect_status'))
        ->toBeNull();
});
