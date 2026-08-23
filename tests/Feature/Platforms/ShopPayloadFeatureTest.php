<?php

use App\Models\Core\User\User;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Task 7: selection() reads content.storefronts (ShopContentReader) —
    // attach the stand-in schema so that read doesn't 500 on SQLite's real
    // absence of the table. (The legacy site.shop_brands fallback it briefly
    // had is gone with the table: 20260819000210.)
    setupContentTables();
    // makeShopStoreProduct() writes through ProjectionWriter::writeManualItem().
    setupIngestTables();
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

/**
 * One connected store for $user, plus its own anchor connection.
 *
 * Replaces the `site.shop_brands` row + `ShopBackfiller::run()` these fixtures
 * used to build: the table is dropped (20260819000210) and the backfiller went
 * with it, so content.collections + content.storefronts is the only storage a
 * store has. Written through the real ShopContentWriter — the same lane
 * addBrand() uses — so the fixture cannot drift from what production writes.
 *
 * $overrides are StoreRecord property names, not the legacy column names.
 */
function shopPayloadStore(User $user, string $externalRef, array $overrides = []): StoreRecord
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

it('shop updateBrand preserves other brands fields verbatim', function () {
    $user = shopPayloadUser('shp1');
    // FOUND-25: two brands as separate stores. brand-1 carries internal fields
    // (fetch_mode, source_url) the product dispatch depends on. Updating
    // brand-2's discount must not touch brand-1's row at all — which is exactly
    // the risk upsertStore() carries, since it rewrites every column of the row
    // it targets.
    $brand1 = shopPayloadStore($user, 'brand-1', [
        'provider' => 'woocommerce',
        'url' => 'https://b1', 'sourceUrl' => 'https://b1/shop', 'fetchMode' => 'client',
        'discountCode' => 'A', 'position' => 0,
    ]);
    makeShopStoreProduct($brand1, ['productId' => 'p1', 'url' => 'https://b1/p1']);
    shopPayloadStore($user, 'brand-2', [
        'url' => 'https://b2', 'discountCode' => 'B', 'position' => 1,
    ]);

    actingAsUser($user)->patchJson('/api/platforms/shop/brands/brand-2', ['discountCode' => 'NEW'])
        ->assertOk()
        ->assertJsonPath('discountCode', 'NEW');

    // brand-1's fields + products survive verbatim; brand-2's discount
    // updated. Every assertion reads content.storefronts, where the old
    // brand_id is `external_ref` and fetch_mode/source_url/discount_code keep
    // their names — that is the row upsertStore() rewrites wholesale on every
    // write, so "did brand-2's edit blank a sibling?" is asked there now.
    $store1 = DB::table('content.storefronts')->where('external_ref', 'brand-1')->first();
    expect($store1->fetch_mode)->toBe('client');
    expect($store1->source_url)->toBe('https://b1/shop');
    expect(orderedProductIdsFor('brand-1'))->toBe(['p1']);
    expect(DB::table('content.storefronts')->where('external_ref', 'brand-2')->value('discount_code'))
        ->toBe('NEW');
});

it('shop selection returns the compat flat view of the first brand with products', function () {
    $user = shopPayloadUser('shp2');
    shopPayloadStore($user, 'empty', ['url' => 'https://e', 'position' => 0]);
    $full = shopPayloadStore($user, 'full', [
        'url' => 'https://f', 'discountCode' => 'SAVE', 'position' => 1,
    ]);
    // createdAt supplied so the content.* round-trip below is deterministic
    // (ShopContentWriter::cataloguesFor() falls back to items.first_seen_at —
    // now() at write time — when a blob has none, which this assertion
    // can't pin). title/price are explicitly null, NOT merely omitted: the
    // legacy fixture was a bare {productId, url, createdAt} blob, and the
    // expected body below is what that reads back as. makeShopStoreProduct()
    // otherwise merges its own title/price defaults over them.
    makeShopStoreProduct($full, [
        'productId' => 'p1', 'url' => 'https://f/p1', 'createdAt' => '2026-01-01T00:00:00Z',
        'title' => null, 'price' => null,
    ]);

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

it('shop selection surfaces a seeded popularityRank keyed by the product item id', function () {
    // Covers the dashboard route: shop_product ranks key by content.items.id
    // (every family, 2026-08-23); ShopController::productRanksFor re-keys
    // them by handle for the legacy catalogue shape, which carries no item id.
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
    $brand = shopPayloadStore($user, 'full', [
        'url' => 'https://f', 'discountCode' => 'SAVE', 'position' => 0,
    ]);
    // handle='mug' round-trips through content.f_catalog, which is how the
    // id-keyed rank is mapped back onto the handle-keyed catalogue.
    makeShopStoreProduct($brand, ['productId' => 'p1', 'handle' => 'mug', 'url' => 'https://f/p1']);
    $itemId = (string) DB::table('content.f_catalog')->where('handle', 'mug')->value('item_id');

    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'content_type' => 'shop_product',
        'content_key' => $itemId,
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
    // The store is written into content.* deliberately. The legacy version of
    // this fixture built a site.shop_brands row and never backfilled it, so
    // brandMap() saw NOTHING and selection() answered null because the user had
    // no store at all — a weaker fact than the one the name promises. With the
    // store really present and merely empty, primaryWithProducts() returning
    // null is the behaviour actually under test.
    $store = shopPayloadStore($user, 'b', ['url' => 'https://b', 'position' => 0]);

    expect(DB::table('content.storefronts')->where('external_ref', 'b')->exists())->toBeTrue()
        ->and(DB::table('content.collection_items')->where('collection_id', $store->collectionId)->count())->toBe(0);

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
    foreach (['b1', 'b2', 'b3', 'b4'] as $i => $brandId) {
        $brand = shopPayloadStore($user, $brandId, [
            'url' => "https://{$brandId}.example.com", 'position' => $i,
        ]);
        makeShopStoreProduct($brand, [
            'productId' => "p-{$brandId}", 'handle' => $brandId, 'url' => "https://{$brandId}.example.com/p",
        ]);
    }

    DB::connection('pgsql')->enableQueryLog();
    actingAsUser($user)->getJson('/api/platforms/shop/selection')->assertOk();
    $log = DB::connection('pgsql')->getQueryLog();
    DB::connection('pgsql')->disableQueryLog();

    $rankQueries = array_filter($log, fn ($q) => str_contains($q['query'], 'content_popularity_scores'));
    expect(count($rankQueries))->toBe(1, 'Expected exactly 1 popularity-rank query for 4 brands (one '.
        'brandMap() build — see this test\'s own docblock), not one per brand.');
});
