<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\ShopProduct;
use App\Models\Core\User\User;
use App\Services\Migration\ShopBackfiller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Task 7: selection() now reads content.storefronts (ShopContentReader)
    // with a fallback to the legacy site.shop_brands map — attach the
    // stand-in schema so the content.* half of that read doesn't 500 on
    // SQLite's real absence of the table.
    setupContentTables();
});

function shopPayloadUser(string $h): User
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

it('shop updateBrand preserves other brands fields verbatim', function () {
    $user = shopPayloadUser('shp1');
    // FOUND-25: two brands as separate site.shop_brands rows. brand-1 carries
    // internal fields (fetch_mode, source_url) the product dispatch depends on.
    // Updating brand-2's discount must not touch brand-1's row at all.
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $brand1 = ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'brand-1', 'provider' => 'woocommerce',
        'url' => 'https://b1', 'source_url' => 'https://b1/shop', 'fetch_mode' => 'client',
        'discount_code' => 'A', 'position' => 0,
    ]);
    ShopProduct::create(['brand_id' => $brand1->id, 'product_id' => 'p1', 'position' => 0, 'data' => ['productId' => 'p1', 'url' => 'https://b1/p1']]);
    ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'brand-2', 'provider' => 'shopify',
        'url' => 'https://b2', 'discount_code' => 'B', 'position' => 1,
    ]);

    actingAsUser($user)->patchJson('/api/platforms/shop/brands/brand-2', ['discountCode' => 'NEW'])
        ->assertOk()
        ->assertJsonPath('discountCode', 'NEW');

    // brand-1's fields + products survive verbatim; brand-2's discount updated.
    $brand1->refresh();
    expect($brand1->fetch_mode)->toBe('client');
    expect($brand1->source_url)->toBe('https://b1/shop');
    expect($brand1->products->map->data->all())->toBe([['productId' => 'p1', 'url' => 'https://b1/p1']]);
    expect(ShopBrand::where('connection_id', $conn->id)->where('brand_id', 'brand-2')->value('discount_code'))
        ->toBe('NEW');
});

it('shop selection returns the compat flat view of the first brand with products', function () {
    $user = shopPayloadUser('shp2');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'empty', 'provider' => 'shopify',
        'url' => 'https://e', 'discount_code' => '', 'position' => 0,
    ]);
    $full = ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'full', 'provider' => 'shopify',
        'url' => 'https://f', 'discount_code' => 'SAVE', 'position' => 1,
    ]);
    // createdAt supplied so the content.* round-trip below is deterministic
    // (ShopContentWriter::cataloguesFor() falls back to items.first_seen_at —
    // now() at backfill time — when a blob has none, which this assertion
    // can't pin).
    ShopProduct::create(['brand_id' => $full->id, 'product_id' => 'p1', 'position' => 0, 'data' => [
        'productId' => 'p1', 'url' => 'https://f/p1', 'createdAt' => '2026-01-01T00:00:00Z',
    ]]);
    // Task 8: selection() reads ShopContentReader with no legacy fallback
    // (hybridBrandMap() is gone) — land this fixture in content.* via the
    // real migration path, same as ShopEndpointParityTest's parityFixture().
    app(ShopBackfiller::class)->run();

    actingAsUser($user)->getJson('/api/platforms/shop/selection')
        ->assertOk()
        ->assertExactJson(['selection' => [
            'url' => 'https://f',
            'provider' => 'shopify',
            'discountCode' => 'SAVE',
            // popularityRank rides every dashboard product since 2026-08-04
            // (brandMap annotates from content_popularity_scores; null when
            // the site has no ranks) — the Smart order switch sorts on it.
            // The rest of these keys are ShopContentReader's own fuller
            // reconstruction shape (Task 7) — a bare {productId, url} blob
            // like this fixture's reads back with every other field present
            // as explicit null/empty, not omitted (documented divergence,
            // same as ShopEndpointParityTest's brand-c fixture).
            'products' => [[
                'productId' => 'p1', 'title' => null, 'url' => 'https://f/p1',
                'price' => null, 'currency' => null, 'available' => true,
                'handle' => null, 'image' => null, 'images' => [],
                'variantId' => null, 'createdAt' => '2026-01-01T00:00:00+00:00',
                'variants' => [], 'popularityRank' => null,
            ]],
        ]]);
});

it('shop selection surfaces a seeded popularityRank keyed by product handle', function () {
    // Covers the dashboard route (shop_product ranks were ALREADY correctly
    // keyed by handle — ShopBrand::toBrandArray():135 — unlike custom/links'
    // resource_id mismatch, RANK-1). ShopRelationalStorageTest pins the same
    // keying on the PUBLIC wire only; this is the /api/platforms/shop/selection
    // half.
    setupContentPopularityScoresTable();
    $user = shopPayloadUser('shp4');
    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $user->id,
        'subdomain' => 'shp4',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $brand = ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'full', 'provider' => 'shopify',
        'url' => 'https://f', 'discount_code' => 'SAVE', 'position' => 0,
    ]);
    ShopProduct::create([
        'brand_id' => $brand->id, 'product_id' => 'p1', 'position' => 0,
        'data' => ['productId' => 'p1', 'handle' => 'mug', 'url' => 'https://f/p1'],
    ]);
    // Task 8: selection() reads ShopContentReader with no legacy fallback —
    // land this fixture in content.* (handle='mug' round-trips through
    // content.f_catalog) via the real migration path.
    app(ShopBackfiller::class)->run();

    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'content_type' => 'shop_product',
        'content_key' => 'mug',
        'score' => 8.0,
        'rank' => 1,
        'computed_at' => now()->toDateTimeString(),
    ]);

    actingAsUser($user)->getJson('/api/platforms/shop/selection')
        ->assertOk()
        ->assertJsonPath('selection.products.0.popularityRank', 1);
});

it('shop selection is null when no brand has products', function () {
    $user = shopPayloadUser('shp3');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'b', 'provider' => 'shopify',
        'url' => 'https://b', 'position' => 0,
    ]);

    actingAsUser($user)->getJson('/api/platforms/shop/selection')
        ->assertOk()
        ->assertExactJson(['selection' => null]);
});

// ── Fix round 3, Finding 4: N+1 regression guard ────────────────────────
//
// productRanksFor() was called PER BRAND inside ShopController::brandMap()'s
// ->map() closure (an accidental N+1 introduced when the shared helper was
// extracted in round 1) — measured as 5 analytics.content_popularity_scores
// queries for 4 brands on GET /selection, a path that also feeds the public
// wire. Hoisted back out; this pins the fixed count so it cannot regress.
// The count is 1, not 2: Task 8 deleted hybridBrandMap() (fix round 1,
// Finding 6's TEMPORARY merge of the legacy site.shop_brands map and
// ShopContentReader's content.* map, each calling productRanksFor() once) —
// selection() now calls ShopContentReader::brandMap() directly, a single
// build, a single ranks query.
it('calls analytics.content_popularity_scores at most once per brand-map build, not once per brand', function () {
    setupContentPopularityScoresTable();
    $user = shopPayloadUser('shp5');
    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId, 'user_id' => $user->id, 'subdomain' => 'shp5',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    foreach (['b1', 'b2', 'b3', 'b4'] as $i => $brandId) {
        $brand = ShopBrand::create([
            'connection_id' => $conn->id, 'brand_id' => $brandId, 'provider' => 'shopify',
            'url' => "https://{$brandId}.example.com", 'position' => $i,
        ]);
        ShopProduct::create([
            'brand_id' => $brand->id, 'product_id' => "p-{$brandId}", 'position' => 0,
            'data' => ['productId' => "p-{$brandId}", 'handle' => $brandId, 'url' => "https://{$brandId}.example.com/p"],
        ]);
    }
    // Task 8: land all 4 brands in content.* — selection() reads
    // ShopContentReader with no legacy fallback now.
    app(ShopBackfiller::class)->run();

    DB::connection('pgsql')->enableQueryLog();
    actingAsUser($user)->getJson('/api/platforms/shop/selection')->assertOk();
    $log = DB::connection('pgsql')->getQueryLog();
    DB::connection('pgsql')->disableQueryLog();

    $rankQueries = array_filter($log, fn ($q) => str_contains($q['query'], 'content_popularity_scores'));
    expect(count($rankQueries))->toBe(1, 'Expected exactly 1 popularity-rank query for 4 brands (one '.
        'brandMap() build — see this test\'s own docblock), not one per brand.');
});
