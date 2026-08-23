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

// shopStore/shopProduct now live in tests/Helpers/PoolTestHelpers.php —
// ShopWireRetirementTest and PoolWireShapeTest call them too, and a helper
// declared here is undefined in any --parallel worker not assigned this file.

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

it('ships variants with their own price, availability and per-variant image', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    $itemId = shopProduct($pro->id, $store, 'Tee');
    $sourceId = DB::table('content.source_items')->where('item_id', $itemId)->value('source_id');

    // The third variant carries a REAL image_url. That end-to-end leg matters
    // more than it looks: content.item_variants had no image column at all
    // until migration 20260813100003 added one, and the field was silently
    // lost for a whole fix round before that. It is what the sitepage swaps the
    // product photo on when a shopper picks a colour, and the only other
    // coverage is a key-presence check (PoolWireShapeTest) plus the two null
    // rows below — neither of which fails if a projector or resolver quietly
    // stops populating the column. Both cases are asserted in one exact-shape
    // toBe(), so a regression on either side cannot hide.
    //
    // No updated_at: content.item_variants has no such column
    // (20260727140000_content_schema.sql:404 + the 100003 image_url add).
    DB::table('content.item_variants')->insert([
        ['id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
            'label' => 'Small', 'sku' => 'sku-s', 'position' => 0, 'image_url' => null],
        ['id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
            'label' => 'Large', 'sku' => 'sku-l', 'position' => 1, 'image_url' => null],
        ['id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
            'label' => 'Navy', 'sku' => 'sku-n', 'position' => 2,
            'image_url' => 'https://cdn.example.com/variants/navy.jpg'],
    ]);
    DB::table('content.offers')->insert([
        ['id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
            'amount_minor' => 3000, 'currency' => 'AUD', 'qualifier' => 'exact',
            'availability' => 'in_stock', 'variant_label' => 'Small', 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
            'amount_minor' => 3500, 'currency' => 'AUD', 'qualifier' => 'exact',
            'availability' => 'out_of_stock', 'variant_label' => 'Large', 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
            'amount_minor' => 3200, 'currency' => 'AUD', 'qualifier' => 'exact',
            'availability' => 'in_stock', 'variant_label' => 'Navy', 'updated_at' => now()],
    ]);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect($item['variants'])->toBe([
        ['label' => 'Small', 'sku' => 'sku-s', 'imageUrl' => null, 'availability' => 'in_stock',
            'price' => ['amountMinor' => 3000, 'amountMaxMinor' => null, 'currency' => 'AUD', 'qualifier' => 'exact']],
        ['label' => 'Large', 'sku' => 'sku-l', 'imageUrl' => null, 'availability' => 'out_of_stock',
            'price' => ['amountMinor' => 3500, 'amountMaxMinor' => null, 'currency' => 'AUD', 'qualifier' => 'exact']],
        ['label' => 'Navy', 'sku' => 'sku-n', 'imageUrl' => 'https://cdn.example.com/variants/navy.jpg',
            'availability' => 'in_stock',
            'price' => ['amountMinor' => 3200, 'amountMaxMinor' => null, 'currency' => 'AUD', 'qualifier' => 'exact']],
    ]);

    // Belt-and-braces on the leg that was lost once: the URL really travels to
    // the serialised wire, not merely into the in-memory array above.
    // JSON_UNESCAPED_SLASHES because json_encode() escapes `/` by default, so a
    // plain needle would never match its own encoding.
    expect(json_encode($out, JSON_UNESCAPED_SLASHES))->toContain('https://cdn.example.com/variants/navy.jpg');

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
    poolPin($siteId, 'shop', shopProduct($pro->id, $store, 'Hat'));

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

// The NAMED half of the store-card name rule — see the unnamed half below.
it('publishes the collections map beside the items', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id, ['label' => 'Above the Ground', 'external_ref' => '75102060779', 'discount_code' => 'ALEX10']);
    poolPin($siteId, 'shop', shopProduct($pro->id, $store, 'Hat'));

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
            // A storefront never ranks — only menu/service categories do (D2).
            'popularityRank' => null,
        ]);
});

// The UNNAMED half. content.collections.label is NOT NULL and upsertStore()
// writes `name ?? brand_id` into it, so a store whose name was never fetched
// stores its own id as its label. ShopContentReader:159 nulls that back out for
// the dashboard; collectionsFor() must apply the IDENTICAL rule, or the two
// disagree and — worse — a raw brand id ("75102060779", "fearnoevil-com-au")
// ships as the public store-card name on a CDN-cached page. Fix round 1,
// Finding 1: this became a live path when slice 5b started rendering
// still-pending stores, which are exactly the ones with no fetched name yet.
it('publishes a null store name when the label is just the external ref', function () {
    [$pro, $siteId] = poolTenant();
    // upsertStore()'s no-name outcome, reproduced exactly: label === external_ref.
    $store = shopStore($pro->id, ['label' => '75102060779', 'external_ref' => '75102060779']);
    poolPin($siteId, 'shop', shopProduct($pro->id, $store, 'Hat'));

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');

    expect($out['collections'][$store]['name'])->toBeNull()
        // The card itself still ships — this is a null NAME, not a dropped store.
        ->and($out['collections'][$store]['externalRef'])->toBe('75102060779');

    // The id must not ride onto the wire as a name by any other route.
    expect(substr_count(json_encode($out['collections']), '75102060779'))->toBe(1);
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

    // The source ids are CHOSEN, not generated, and that is the whole point of
    // this test. Strip the ORDER BY and the fetch falls back to the
    // (item_id, source_id) PK-index scan — which random uuids decide, so the
    // broken state was only caught ~2 runs in 3 and the year-wide updated_at
    // gap could not help (reverted code never reads that column). Pinning
    // stale > fresh puts the STALE row LAST, exactly where keyBy keeps it, so
    // a revert fails EVERY run and the fix passes every run.
    $freshSource = '00000000-0000-4000-8000-0000000f8e54';   // sorts FIRST
    $staleSource = 'ffffffff-ffff-4fff-bfff-ffffff57a1e0';   // sorts LAST

    // The manual source shopProduct() would otherwise mint for itself, with a
    // chosen id — it reuses the user's existing manual source when there is one.
    DB::table('content.sources')->insert([
        'id' => $freshSource, 'user_id' => $pro->id, 'kind' => 'manual',
        'connection_id' => null, 'priority' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $itemId = shopProduct($pro->id, $store, 'Hat');

    // A second, otherwise realistic source genuinely describing the same item:
    // a real connection, its own source_items grain, its own facet rows.
    DB::table('content.sources')->insert([
        'id' => $staleSource, 'user_id' => $pro->id, 'kind' => 'connection',
        'connection_id' => poolConnection($pro->id), 'priority' => 100,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $staleSource,
        'coord' => 'x:stale-hat', 'item_id' => $itemId, 'kind' => 'product',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    // Written AFTER the fresh rows and stamped a year older.
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
// content.items.id (every family keys by item id since 2026-08-23; the
// catalog handle no longer reaches a product).
it('serves the live shop_product rank for a product, keyed by its item id', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    $ranked = shopProduct($pro->id, $store, 'Bulwark Jacket');   // handle bulwark-jacket
    $unranked = shopProduct($pro->id, $store, 'Plain Cap');      // handle plain-cap

    DB::table('analytics.content_popularity_scores')->insert([
        ['id' => (string) Str::uuid(), 'site_id' => $siteId, 'content_type' => 'shop_product',
            'content_key' => $ranked, 'score' => 12.5, 'rank' => 3, 'computed_at' => now()],
        // The legacy handle key must NOT reach the product any more.
        ['id' => (string) Str::uuid(), 'site_id' => $siteId, 'content_type' => 'shop_product',
            'content_key' => 'plain-cap', 'score' => 50.0, 'rank' => 2, 'computed_at' => now()],
        // Same id as the unranked product but a DIFFERENT bucket: only the
        // shop_product bucket may reach a product, or a video's rank would
        // leak onto a tee.
        ['id' => (string) Str::uuid(), 'site_id' => $siteId, 'content_type' => 'watch_item',
            'content_key' => $unranked, 'score' => 99.0, 'rank' => 1, 'computed_at' => now()],
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
        'content_key' => $itemId, 'score' => 12.5, 'rank' => 3, 'computed_at' => now(),
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
