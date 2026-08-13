<?php

use App\Models\Core\Site\Site;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Slice 5b §3.6/§3.7: the pool payload has to carry everything the legacy
// /integrations shop wire carried before Task 8 deletes it — description,
// vendor, variants, gallery frames, the composed outbound URL, the live
// popularity rank and the store cards the sitepage rebuilds its layout from.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    setupMediaTables();
    setupContentPopularityScoresTable();
    Storage::fake('media');
    Queue::fake();
});

function shopStore(string $userId, array $overrides = []): string
{
    $collectionId = (string) Str::uuid();
    DB::table('content.collections')->insert([
        'id' => $collectionId, 'user_id' => $userId, 'kind' => 'storefront',
        'label' => $overrides['label'] ?? 'Test Store', 'is_user_created' => false,
        'position' => $overrides['position'] ?? 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.storefronts')->insert(array_merge([
        'collection_id' => $collectionId, 'external_ref' => 'ext-'.Str::random(6),
        'provider' => 'shopify', 'url' => 'https://store.example.com',
        'currency' => 'AUD', 'discount_code' => null, 'referral_query' => '',
        'is_individual' => false, 'logo_url' => 'https://cdn.example.com/logo.png',
        'favicon_url' => 'https://cdn.example.com/fav.ico',
        'created_at' => now(), 'updated_at' => now(),
    ], array_diff_key($overrides, ['label' => 1, 'position' => 1])));

    return $collectionId;
}

function shopProduct(string $userId, string $collectionId, string $title, int $position = 0): string
{
    // Reuse the user's manual source if one exists: idx_content_sources_manual
    // (20260727140000) allows exactly ONE manual source per user, so a second
    // poolSource($userId, null) is a unique violation, not a second store.
    $sourceId = DB::table('content.sources')
        ->where('user_id', $userId)->where('kind', 'manual')->value('id')
        ?? poolSource($userId, null);
    $itemId = poolItem($userId, $sourceId, 'product', $title, '2026-08-01T00:00:00Z');
    DB::table('content.collection_items')->insert([
        'collection_id' => $collectionId, 'item_id' => $itemId,
        'source_id' => null, 'position' => $position,
    ]);
    DB::table('content.f_link')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'url' => 'https://store.example.com/products/'.Str::slug($title), 'updated_at' => now(),
    ]);
    DB::table('content.f_catalog')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'handle' => Str::slug($title), 'vendor' => 'A Vendor',
        'variant_ref' => '44073715368070', 'updated_at' => now(),
    ]);
    DB::table('content.f_text')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'headline' => $title, 'body' => 'A description.', 'updated_at' => now(),
    ]);

    return $itemId;
}

it('carries description, vendor and collectionIds on a product', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    $itemId = shopProduct($pro->id, $store, 'Bulwark Jacket');

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect($item['description'])->toBe('A description.')
        ->and($item['vendor'])->toBe('A Vendor')
        ->and($item['collectionIds'])->toBe([$store]);
});

it('leaves the new keys null or empty on a non-shop kind', function () {
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);
    $itemId = poolItem($pro->id, $sourceId, 'video', 'A video', '2026-08-01T00:00:00Z');

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'watch');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect($item['description'])->toBeNull()
        ->and($item['vendor'])->toBeNull()
        ->and($item['variants'])->toBe([])
        ->and($item['collectionIds'])->toBe([])
        ->and($item['popularityRank'])->toBeNull();
});

it('ships variants with their own price and availability', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    $itemId = shopProduct($pro->id, $store, 'Tee');
    $sourceId = DB::table('content.source_items')->where('item_id', $itemId)->value('source_id');

    // No updated_at: content.item_variants has no such column
    // (20260727140000_content_schema.sql:404 + the 100003 image_url add).
    DB::table('content.item_variants')->insert([
        ['id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
            'label' => 'Small', 'sku' => 'sku-s', 'position' => 0, 'image_url' => null],
        ['id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
            'label' => 'Large', 'sku' => 'sku-l', 'position' => 1, 'image_url' => null],
    ]);
    DB::table('content.offers')->insert([
        ['id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
            'amount_minor' => 3000, 'currency' => 'AUD', 'qualifier' => 'exact',
            'availability' => 'in_stock', 'variant_label' => 'Small', 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
            'amount_minor' => 3500, 'currency' => 'AUD', 'qualifier' => 'exact',
            'availability' => 'out_of_stock', 'variant_label' => 'Large', 'updated_at' => now()],
    ]);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect($item['variants'])->toBe([
        ['label' => 'Small', 'sku' => 'sku-s', 'imageUrl' => null, 'availability' => 'in_stock',
            'price' => ['amountMinor' => 3000, 'amountMaxMinor' => null, 'currency' => 'AUD', 'qualifier' => 'exact']],
        ['label' => 'Large', 'sku' => 'sku-l', 'imageUrl' => null, 'availability' => 'out_of_stock',
            'price' => ['amountMinor' => 3500, 'amountMaxMinor' => null, 'currency' => 'AUD', 'qualifier' => 'exact']],
    ]);

    // The item-level price stays the CHEAPEST offer — unchanged behaviour.
    expect($item['price']['amountMinor'])->toBe(3000);
});

it('composes the outbound URL into url and leaves links bare', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id, ['discount_code' => 'ALEX10', 'referral_query' => 'ref=abc']);
    $itemId = shopProduct($pro->id, $store, 'Hat');

    DB::table('site.sites')->where('id', $siteId)->update(['shop_link_mode' => 'checkout']);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect($item['url'])->toBe('https://store.example.com/cart/44073715368070:1?discount=ALEX10&ref=abc')
        // The referral suffix must appear in exactly ONE place on the wire.
        ->and($item['links'][0]['url'])->toBe('https://store.example.com/products/hat')
        ->and(json_encode($item['links']))->not->toContain('ref=abc');
});

it('keeps referralQuery, linkMode, sourceUrl and connectStatus off the wire', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id, ['referral_query' => 'ref=abc', 'source_url' => 'https://scrape.example.com', 'connect_status' => 'connected']);
    shopProduct($pro->id, $store, 'Hat');

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');
    $json = json_encode($out);

    // Non-vacuity: the store IS on the wire and its suffix DID compose, so the
    // absences below are real absences, not an empty payload.
    expect($out['collections'])->not->toBe([])
        ->and($out['selection'][0]['url'])->toContain('ref=abc');

    // str_contains + toBeFalse, not ->not->toContain: the Pest negated matcher
    // is the known false-pass trap.
    //
    // BOTH cases matter. The camelCase names catch a hand-written key; the
    // snake_case ones catch the actual leak vector — a row spread would emit
    // the DB column names, and a camelCase-only list would wave
    // referral_query and connect_status straight through.
    $forbiddenNeedles = [
        'referralQuery', 'referral_query',
        'linkMode', 'link_mode', 'shop_link_mode',
        'sourceUrl', 'source_url',
        'connectStatus', 'connect_status', 'connect_error',
        'scrape.example.com',
    ];
    foreach ($forbiddenNeedles as $forbidden) {
        expect(str_contains($json, $forbidden))->toBeFalse("{$forbidden} must not reach the public wire");
    }
});

it('publishes the collections map beside the items', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id, ['label' => 'Above the Ground', 'external_ref' => '75102060779', 'discount_code' => 'ALEX10']);
    shopProduct($pro->id, $store, 'Hat');

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');

    expect($out['collections'])->toHaveKey($store)
        ->and($out['collections'][$store])->toBe([
            'externalRef' => '75102060779',
            'provider' => 'shopify',
            'url' => 'https://store.example.com',
            'name' => 'Above the Ground',
            'currency' => 'AUD',
            'favicon' => 'https://cdn.example.com/fav.ico',
            'logo' => 'https://cdn.example.com/logo.png',
            'discountCode' => 'ALEX10',
            'position' => 0,
        ]);
});

it('returns an empty collections map for a pool with no products', function () {
    [$pro, $siteId] = poolTenant();
    $sourceId = poolSource($pro->id, null);
    poolItem($pro->id, $sourceId, 'video', 'A video', '2026-08-01T00:00:00Z');

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'watch');

    expect($out['collections'])->toBe([]);
});

// f_catalog and f_text are both PK (item_id, source_id), so a product carried
// by two sources has TWO rows and keyBy keeps the LAST. Unordered that is
// arbitrary scan order: vendor and description flip between reads, and
// variant_ref can be taken from store B while $primaryStore is store A —
// composing a dead cart URL onto a CDN-cached page.
it('takes the freshest source row when two sources describe one product', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    $itemId = shopProduct($pro->id, $store, 'Hat');
    $staleSource = poolSource($pro->id, poolConnection($pro->id));

    // Written AFTER the fresh row but stamped a year older: in insertion order
    // this row lands last, which is exactly what keyBy would have kept.
    DB::table('content.f_catalog')->insert([
        'item_id' => $itemId, 'source_id' => $staleSource,
        'handle' => 'hat-stale', 'vendor' => 'Stale Vendor',
        'variant_ref' => '999', 'updated_at' => now()->subYear(),
    ]);
    DB::table('content.f_text')->insert([
        'item_id' => $itemId, 'source_id' => $staleSource,
        'headline' => 'Hat', 'body' => 'A stale description.', 'updated_at' => now()->subYear(),
    ]);

    $item = collect(app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop')['library'])
        ->firstWhere('id', $itemId);

    expect($item['vendor'])->toBe('A Vendor')
        ->and($item['description'])->toBe('A description.')
        // The sharp edge: the composed checkout link must carry the FRESH
        // variant_ref, or the page ships a cart URL that 404s at the store.
        ->and($item['url'])->toBe('https://store.example.com/cart/44073715368070:1');
});

// popularityRank is the one field here whose loss is completely silent: the
// next task deletes the legacy wire that carries it, 34 live dev ranks drop to
// null, and nothing errors. So it is pinned positively, not just on its null
// path — the join is analytics.content_popularity_scores.content_key ==
// f_catalog.handle, NOT the item id.
it('serves the live shop_product rank for a product, keyed by its catalog handle', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    $ranked = shopProduct($pro->id, $store, 'Bulwark Jacket');   // handle bulwark-jacket
    $unranked = shopProduct($pro->id, $store, 'Plain Cap');      // handle plain-cap

    DB::table('analytics.content_popularity_scores')->insert([
        ['id' => (string) Str::uuid(), 'site_id' => $siteId, 'content_type' => 'shop_product',
            'content_key' => 'bulwark-jacket', 'score' => 12.5, 'rank' => 3, 'computed_at' => now()],
        // Same handle as the unranked product but a DIFFERENT bucket: only the
        // shop_product bucket may reach a product, or a video's rank would
        // leak onto a tee that shares its slug.
        ['id' => (string) Str::uuid(), 'site_id' => $siteId, 'content_type' => 'watch_item',
            'content_key' => 'plain-cap', 'score' => 99.0, 'rank' => 1, 'computed_at' => now()],
    ]);

    $items = collect(app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop')['library'])
        ->keyBy('id');

    expect($items[$ranked]['popularityRank'])->toBe(3)
        ->and($items[$unranked]['popularityRank'])->toBeNull();
});

it('leaves popularityRank null when the score belongs to another site', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    $itemId = shopProduct($pro->id, $store, 'Bulwark Jacket');

    [, $otherSiteId] = poolTenant();
    DB::table('analytics.content_popularity_scores')->insert([
        'id' => (string) Str::uuid(), 'site_id' => $otherSiteId, 'content_type' => 'shop_product',
        'content_key' => 'bulwark-jacket', 'score' => 12.5, 'rank' => 3, 'computed_at' => now(),
    ]);

    $item = collect(app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop')['library'])
        ->firstWhere('id', $itemId);

    expect($item['popularityRank'])->toBeNull();
});

// Constraint: the shop reads are set-wide AND gated on the resolved set
// containing a product, so watch / listen / media / events pay nothing for
// them. str_contains + toBeFalse rather than ->not->toContain, and the shop
// half of the pair is asserted POSITIVELY so the negative half cannot pass
// vacuously on a mis-spelled table name.
it('issues the shop-only queries for a shop pool and none of them for a watch pool', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    $productId = shopProduct($pro->id, $store, 'Hat');
    // Reuse the product's source: idx_content_sources_manual allows exactly
    // one manual source per user.
    $sourceId = DB::table('content.source_items')->where('item_id', $productId)->value('source_id');
    poolItem($pro->id, $sourceId, 'video', 'A video', '2026-08-01T00:00:00Z');
    $site = Site::query()->findOrFail($siteId);

    $shopSql = queryLogFor(fn () => app(PoolResolver::class)->resolve($site, 'shop'));
    $watchSql = queryLogFor(fn () => app(PoolResolver::class)->resolve($site, 'watch'));

    foreach (['storefronts', 'collection_items', 'f_catalog', 'item_variants'] as $table) {
        expect(str_contains($shopSql, $table))->toBeTrue("shop pool must read {$table}")
            ->and(str_contains($watchSql, $table))->toBeFalse("watch pool must not read {$table}");
    }
});

function queryLogFor(Closure $fn): string
{
    $pg = DB::connection('pgsql');
    $pg->flushQueryLog();
    $pg->enableQueryLog();
    $fn();
    $sql = collect($pg->getQueryLog())->pluck('query')->implode(' ');
    $pg->disableQueryLog();

    return $sql;
}

it('populates frames for a product from its item_media', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    $itemId = shopProduct($pro->id, $store, 'Hat');
    $sourceId = DB::table('content.source_items')->where('item_id', $itemId)->value('source_id');

    $cover = frameAsset($pro->id, ['source_url' => 'https://cdn.example.com/cover.jpg', 'width' => 800, 'height' => 600]);
    $gallery = frameAsset($pro->id, ['source_url' => 'https://cdn.example.com/g1.jpg', 'width' => 640, 'height' => 480]);
    frameRow($itemId, $sourceId, $cover, 'cover', 0);
    frameRow($itemId, $sourceId, $gallery, 'gallery', 1);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect($item['frames'])->toHaveCount(2)
        ->and($item['frames'][0]['url'])->toBe('https://cdn.example.com/cover.jpg')
        ->and($item['thumbnail'])->toBe('https://cdn.example.com/cover.jpg');
});
