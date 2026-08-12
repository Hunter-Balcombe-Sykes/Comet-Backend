<?php

// W9 §3f/§4 Unit 5 — ShopController::setProducts() used to run its up-to-20s
// vendor fetch (FetchBudget::open(... providerProducts ...)) AND the DB
// delete+reinsert transaction inside withConnectionLock()'s Cache::lock($key,
// 10)->block(5, ...) — a 10s TTL. The fetch alone could exceed that TTL,
// letting the lock expire while the transaction was still open: a second
// writer could then acquire the "free" lock and interleave with this
// request's uncommitted write. The fix moves the fetch outside the lock;
// only the read→mutate→write cycle is serialised.
//
// T15 mirrors FreshaConnectLockTest.php / InstagramControllerLockTest.php's
// proof shape (pre-acquire the SAME key formula CacheKeyGenerator::
// platformConnectionLock('shop', $userId) a concurrent writer would hold) but
// runs it the other direction: instead of asserting a HELD lock blocks a
// writer, it asserts the lock is FREE precisely while the vendor fetch is in
// flight. CACHE_STORE=array in phpunit.xml, so Cache::lock() is a real
// in-process ArrayLock — Illuminate\Cache\ArrayLock::acquire() only checks
// whether the store's record for that key exists and is unexpired (not who
// owns it), so a second Lock object for the same key genuinely fails to
// acquire while a first Lock object (a different random owner) holds it, and
// genuinely succeeds when nothing holds it. Under the pre-fix code the fetch
// runs INSIDE the outer withConnectionLock() closure, so this same probe
// would observe the key already held and record false.

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\ShopProduct;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\ShopifyScraper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Task 8: setProducts() now writes content.* (ShopContentWriter) instead
    // of site.shop_products — the stand-in schema must exist for every test
    // in this file that calls the real (non-mocked-away) endpoint.
    setupContentTables();
});

function shopSelLockUser(string $h): User
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

/** A stored shopify brand ready for setProducts(), no picker catalog warmed. */
function shopSelLockBrand(User $user, string $brandId = 'lockbrand'): ShopBrand
{
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    return ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => $brandId, 'provider' => 'shopify',
        'url' => 'https://lockbrand.example.com', 'source_url' => 'https://lockbrand.example.com',
        'currency' => 'AUD', 'discount_code' => '', 'position' => 0,
    ]);
}

// ── T15 — structural proof ──────────────────────────────────────────────────

it('T15: the vendor fetch runs with the shop connection lock free, not held', function () {
    $user = shopSelLockUser('lockstruct1');
    shopSelLockBrand($user, 'lockbrand');

    $lockWasFree = null;
    $this->mock(ShopifyScraper::class, function ($m) use (&$lockWasFree, $user) {
        $m->shouldReceive('fetchProducts')->once()->andReturnUsing(function () use (&$lockWasFree, $user) {
            // Attempt to acquire the SAME key withConnectionLock() uses for
            // this request. Release immediately on success so the probe
            // itself doesn't starve the real write-phase acquisition that
            // follows it in the request.
            $lock = Cache::lock(CacheKeyGenerator::platformConnectionLock('shop', (string) $user->id), 10);
            $lockWasFree = $lock->get();
            if ($lockWasFree) {
                $lock->release();
            }

            return [
                ['productId' => 'p1', 'title' => 'One', 'url' => 'https://lockbrand.example.com/p1'],
            ];
        });
    });

    actingAsUser($user)->putJson('/api/platforms/shop/brands/lockbrand/selection', ['productIds' => ['p1']])
        ->assertOk();

    expect($lockWasFree)->toBeTrue();
});

// ── T16 — regressions ────────────────────────────────────────────────────────

it('T16a: a warm catalog still short-circuits the scrape', function () {
    $user = shopSelLockUser('lockwarm1');
    shopSelLockBrand($user, 'lockbrand');

    Cache::put('platforms.shopify.brands.catalog.lockbrand', [
        ['productId' => 'p1', 'title' => 'One'],
        ['productId' => 'p2', 'title' => 'Two'],
    ], now()->addMinutes(10));

    $this->mock(ShopifyScraper::class, fn ($m) => $m->shouldNotReceive('fetchProducts'));

    $res = actingAsUser($user)->putJson('/api/platforms/shop/brands/lockbrand/selection', ['productIds' => ['p2']]);

    $res->assertOk();
    expect($res->json('products'))->toHaveCount(1);
    expect($res->json('products.0.productId'))->toBe('p2');
});

it('T16b: a cold catalog still scrapes', function () {
    $user = shopSelLockUser('lockcold1');
    shopSelLockBrand($user, 'lockbrand');

    $this->mock(ShopifyScraper::class, fn ($m) => $m->shouldReceive('fetchProducts')->once()->andReturn([
        ['productId' => 'p1', 'title' => 'One'],
        ['productId' => 'p2', 'title' => 'Two'],
    ]));

    $res = actingAsUser($user)->putJson('/api/platforms/shop/brands/lockbrand/selection', ['productIds' => ['p1']]);

    $res->assertOk();
    expect($res->json('products'))->toHaveCount(1);
    expect($res->json('products.0.productId'))->toBe('p1');
});

it('T16c: the full ShopBrandResource shape, including the productsCuratedAt stamp this PUT sets', function () {
    $user = shopSelLockUser('lockbody1');
    shopSelLockBrand($user, 'lockbrand');

    // createdAt supplied so the content.* round-trip is deterministic
    // (ShopContentWriter::cataloguesFor() falls back to items.first_seen_at —
    // now() at write time — for a blob with none).
    $this->mock(ShopifyScraper::class, fn ($m) => $m->shouldReceive('fetchProducts')->once()->andReturn([
        ['productId' => 'p1', 'title' => 'One', 'url' => 'https://lockbrand.example.com/p1', 'createdAt' => '2026-01-01T00:00:00Z'],
    ]));

    $response = actingAsUser($user)->putJson('/api/platforms/shop/brands/lockbrand/selection', ['productIds' => ['p1']])
        ->assertOk();

    // #SEM-1: setProducts() IS the moment of curation, so this response now
    // carries productsCuratedAt (conditional key, present only when set —
    // T16d/T16e's still-uncurated brands never see it). Task 8: the stamp
    // lands on content.storefronts now, not the legacy site.shop_brands
    // column (setProducts() no longer writes it) — read it back the same
    // way ShopContentReader does.
    $curatedAt = DB::table('content.storefronts')->where('external_ref', 'lockbrand')->value('products_curated_at');
    expect($curatedAt)->not->toBeNull();

    $response->assertExactJson([
        'id' => 'lockbrand',
        'provider' => 'shopify',
        'url' => 'https://lockbrand.example.com',
        'name' => null,
        'currency' => 'AUD',
        'favicon' => null,
        'logo' => null,
        'discountCode' => '',
        'selectionMode' => 'manual',
        // Task 8: linkMode now comes from ShopContentReader (site.sites.
        // shop_link_mode) — shopSelLockUser() has no site row, so it falls
        // to Site::DEFAULT_SHOP_LINK_MODE ('checkout'), not ShopBrand's own
        // per-brand column default ('product'). Same documented divergence
        // as ShopEndpointParityTest's GET /brands test.
        'linkMode' => 'checkout',
        'referralQuery' => '',
        'individual' => false,
        // Task 8: the ShopContentReader reconstruction shape — a bare
        // {productId, title, url} blob reads back with every other field
        // present as explicit null/empty/default, not omitted (documented
        // divergence, same as ShopEndpointParityTest's brand-c fixture).
        // currency reads back null, not the brand's AUD: content.offers has
        // NO row for this item at all (ShopProductProjection::offers()
        // skips the base offer entirely when the blob carries no `price`),
        // and `currency` is read off the offer row, not the brand.
        'products' => [[
            'productId' => 'p1', 'title' => 'One', 'url' => 'https://lockbrand.example.com/p1',
            'price' => null, 'currency' => null, 'available' => true,
            'handle' => null, 'image' => null, 'images' => [],
            'variantId' => null, 'createdAt' => '2026-01-01T00:00:00+00:00',
            'variants' => [],
        ]],
        'productsCuratedAt' => Carbon::parse((string) $curatedAt)->toIso8601String(),
    ]);
});

// #SEM-1: setProducts() is the moment of curation — it must stamp
// products_curated_at so ShopFetch's scheduled sync skips this brand
// afterwards (see ShopRelationalStorageTest's ShopFetch-level proof). The
// wire body gains only that stamp (T16c above) — this only covers the
// locked write.
it('T16f: setProducts stamps products_curated_at', function () {
    $user = shopSelLockUser('lockcurate1');
    shopSelLockBrand($user, 'lockbrand');
    // Task 8: the stamp lands on content.storefronts.products_curated_at —
    // setProducts() no longer writes the legacy site.shop_brands column at
    // all, so there is nothing there to assert null-then-non-null on; no
    // content.storefronts row exists yet either (brand never synced), so
    // the value() call below is null pre-PUT by construction.
    expect(DB::table('content.storefronts')->where('external_ref', 'lockbrand')->value('products_curated_at'))
        ->toBeNull();

    $this->mock(ShopifyScraper::class, fn ($m) => $m->shouldReceive('fetchProducts')->once()->andReturn([
        ['productId' => 'p1', 'title' => 'One', 'url' => 'https://lockbrand.example.com/p1'],
    ]));

    actingAsUser($user)->putJson('/api/platforms/shop/brands/lockbrand/selection', ['productIds' => ['p1']])
        ->assertOk();

    expect(DB::table('content.storefronts')->where('external_ref', 'lockbrand')->value('products_curated_at'))
        ->not->toBeNull();
});

it('T16d: a scraper HttpException still surfaces as 502, same body', function () {
    $user = shopSelLockUser('lock5021');
    shopSelLockBrand($user, 'lockbrand');

    $this->mock(ShopifyScraper::class, fn ($m) => $m->shouldReceive('fetchProducts')
        ->once()
        ->andThrow(new HttpException(502, 'Shopify returned HTTP 502')));

    // The STATUS is the contract here: #P2-30 replaces every 5xx body with the
    // generic message in the deployed env, so the scraper's own text never
    // reaches the wire. Asserting the generic body also pins that no internal
    // detail leaks out.
    actingAsUser($user)->putJson('/api/platforms/shop/brands/lockbrand/selection', ['productIds' => ['p1']])
        ->assertStatus(502)
        ->assertJsonPath('message', 'An error occurred');

    // Never reached the transaction — no product rows written.
    $brand = ShopBrand::where('brand_id', 'lockbrand')->firstOrFail();
    expect(ShopProduct::where('brand_id', $brand->id)->count())->toBe(0);
});

it('T16e: a brand deleted between the pre-lock read and the locked write yields 404, not 500', function () {
    $user = shopSelLockUser('lockdel1');
    shopSelLockBrand($user, 'lockbrand');

    // Simulate the concurrent removeBrand/forget: delete the row from inside
    // the vendor-fetch mock, i.e. exactly the window between the pre-lock
    // read (which found the brand) and the locked re-read (which must not).
    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('fetchProducts')->once()->andReturnUsing(function () {
            $brand = ShopBrand::where('brand_id', 'lockbrand')->firstOrFail();
            ShopProduct::where('brand_id', $brand->id)->delete();
            $brand->delete();

            return [['productId' => 'p1', 'title' => 'One']];
        });
    });

    actingAsUser($user)->putJson('/api/platforms/shop/brands/lockbrand/selection', ['productIds' => ['p1']])
        ->assertStatus(404)
        ->assertJsonPath('message', 'Brand not found.');

    expect(ShopBrand::where('brand_id', 'lockbrand')->exists())->toBeFalse();
});

// ── Fix 1 (P1 review finding) — was: the in-lock write is a single bulk insert ──
//
// TASK 8 UPDATE: the delete+reinsert-as-one-bulk-INSERT this pinned is GONE —
// setProducts() no longer touches site.shop_products at all (ShopContentWriter::
// syncStore() replaces it). The "single INSERT, not one per row" invariant
// this test used to pin no longer applies to the real write path: syncStore()
// writes ONE upsert per product (ProjectionWriter::writeManualItem(), each
// in its own transaction) plus one content.collection_items upsert per
// product — genuinely N round-trips again, just against content.* now
// rather than site.shop_products. FINDING for the Task 8 report: this is a
// real performance-characteristic regression versus the old bulk insert
// (though DB round-trips are cheap relative to the 10s lock TTL the F1
// tests below still exercise at 250 products — no failure observed in this
// suite's run). Restructuring ShopContentWriter::syncStore() into a genuine
// bulk write is out of this task's scope (that class is Task 5's deliverable,
// shared by every syncStore() caller, not just this endpoint). What this
// test can still usefully pin: site.shop_products stays untouched (0 rows,
// 0 inserts) and the selection lands correctly in content.*.

it('F1: setProducts writes zero rows to the legacy site.shop_products table', function () {
    $user = shopSelLockUser('lockqc1');
    shopSelLockBrand($user, 'lockbrand');

    $products = collect(range(1, 6))->map(fn ($n) => [
        'productId' => "p{$n}", 'title' => "Product {$n}", 'url' => "https://lockbrand.example.com/p{$n}",
    ])->all();
    Cache::put('platforms.shopify.brands.catalog.lockbrand', $products, now()->addMinutes(10));
    $this->mock(ShopifyScraper::class, fn ($m) => $m->shouldNotReceive('fetchProducts'));

    DB::connection('pgsql')->enableQueryLog();
    $res = actingAsUser($user)->putJson('/api/platforms/shop/brands/lockbrand/selection', [
        'productIds' => ['p1', 'p2', 'p3', 'p4', 'p5', 'p6'],
    ]);
    $log = DB::connection('pgsql')->getQueryLog();
    DB::connection('pgsql')->disableQueryLog();

    $res->assertOk();
    expect($res->json('products'))->toHaveCount(6);

    // Matched by statement shape (starts with INSERT, touches shop_products),
    // not an exact SQL string — robust to quoting differences between the
    // real pgsql driver and the SQLite test mirror.
    $insertQueries = array_values(array_filter(
        $log,
        fn ($q) => str_starts_with(strtolower(ltrim((string) $q['query'])), 'insert')
            && str_contains($q['query'], 'shop_products'),
    ));
    expect($insertQueries)->toHaveCount(0);
    expect(ShopProduct::count())->toBe(0);

    expect(orderedProductIdsFor('lockbrand'))->toBe(['p1', 'p2', 'p3', 'p4', 'p5', 'p6']);
});

it('F1: a 250-product selection round-trips correctly through content.*', function () {
    $user = shopSelLockUser('lock2501');
    shopSelLockBrand($user, 'lockbrand');

    $productIds = collect(range(1, 250))->map(fn ($n) => "p{$n}")->all();
    // Catalog order is deliberately shuffled — the SAVED order must follow
    // the requested productIds order, not catalog order.
    $catalog = collect($productIds)->shuffle()->map(fn ($pid) => [
        'productId' => $pid, 'title' => "Title for {$pid}", 'url' => "https://lockbrand.example.com/{$pid}", 'price' => '9.99',
    ])->values()->all();
    Cache::put('platforms.shopify.brands.catalog.lockbrand', $catalog, now()->addMinutes(10));
    $this->mock(ShopifyScraper::class, fn ($m) => $m->shouldNotReceive('fetchProducts'));

    $res = actingAsUser($user)->putJson('/api/platforms/shop/brands/lockbrand/selection', ['productIds' => $productIds]);
    $res->assertOk();

    $responseProducts = $res->json('products');
    expect($responseProducts)->toHaveCount(250);
    // Response order follows the request, not the shuffled catalog.
    expect(array_column($responseProducts, 'productId'))->toBe($productIds);

    // content.* replacement for the old ShopProduct-row assertions —
    // position ordering preserved, matching the requested order, and
    // site.shop_products stays entirely untouched.
    expect(orderedProductIdsFor('lockbrand'))->toBe($productIds);
    expect(ShopProduct::count())->toBe(0);

    $first = collect($responseProducts)->firstWhere('productId', $productIds[0]);
    expect($first['title'])->toBe('Title for '.$productIds[0]);
    expect($first['price'])->toBe('9.99');
});

// ── Fix 2 (P2 review finding) — a fresher cached catalog wins inside the lock ──
//
// Moving the fetch outside the lock means two concurrent setProducts() calls
// (or a setProducts() racing a picker GET) now scrape unserialized, where the
// old fully-locked version serialized them. Without a re-check, a stale
// pre-lock snapshot could persist even though a fresher catalog landed in the
// cache before the write. The fix re-reads the cache once inside the lock and
// prefers it over the pre-lock snapshot — never re-scrapes.

it('F2: a fresher catalog that lands after the pre-lock snapshot wins over the stale snapshot', function () {
    $user = shopSelLockUser('lockfresh1');
    shopSelLockBrand($user, 'lockbrand');

    // No warm catalog yet, so the pre-lock read scrapes — and returns a STALE
    // product. From inside that same scrape call (i.e. after the pre-lock
    // snapshot is captured but before the lock is acquired), simulate a
    // concurrent picker GET landing a FRESHER catalog in the cache.
    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('fetchProducts')->once()->andReturnUsing(function () {
            Cache::put('platforms.shopify.brands.catalog.lockbrand', [
                ['productId' => 'p1', 'title' => 'Fresh Title'],
            ], now()->addMinutes(10));

            return [['productId' => 'p1', 'title' => 'Stale Title']];
        });
    });

    actingAsUser($user)->putJson('/api/platforms/shop/brands/lockbrand/selection', ['productIds' => ['p1']])
        ->assertOk()
        ->assertJsonPath('products.0.title', 'Fresh Title');

    // Task 8: content.f_text.headline replaces the legacy ShopProduct.data
    // JSONB read — setProducts() no longer writes site.shop_products.
    $collectionId = DB::table('content.storefronts')->where('external_ref', 'lockbrand')->value('collection_id');
    $itemId = DB::table('content.collection_items')->where('collection_id', $collectionId)->value('item_id');
    expect(DB::table('content.f_text')->where('item_id', $itemId)->value('headline'))->toBe('Fresh Title');
});
