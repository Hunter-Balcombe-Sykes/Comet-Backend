<?php

use App\Models\Core\Site\Site;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    setupMediaTables();
    Queue::fake();
});

// The #API-1 equivalent for the pool wire. This fails on ADDITIONS as well as
// removals, which the legacy allowlist could not do — it only ever caught keys
// someone remembered to leave out.
it('emits exactly the declared item keys, no more and no fewer', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    poolPin($siteId, 'shop', shopProduct($pro->id, $store, 'Hat'));

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');

    expect(array_keys($out['library'][0]))->toEqualCanonicalizing(PoolResolver::ITEM_KEYS);
});

it('emits exactly the declared store keys', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    poolPin($siteId, 'shop', shopProduct($pro->id, $store, 'Hat'));

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');
    $card = $out['collections'][$store] ?? null;

    expect($card)->not->toBeNull()
        ->and(array_keys($card))->toEqualCanonicalizing(PoolResolver::STORE_KEYS);
});

it('emits exactly the declared variant keys', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    $itemId = shopProduct($pro->id, $store, 'Tee');
    $sourceId = DB::table('content.source_items')->where('item_id', $itemId)->value('source_id');
    // content.item_variants carries no updated_at column (neither the real
    // migration nor the SQLite stand-in) — the brief's insert included one;
    // dropped here to match the actual schema.
    DB::table('content.item_variants')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId,
        'source_id' => $sourceId, 'label' => 'Small', 'sku' => 'sku-s',
        'position' => 0, 'image_url' => null,
    ]);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect(array_keys($item['variants'][0]))->toEqualCanonicalizing(PoolResolver::VARIANT_KEYS);
});

// The affiliate suffix must never be publicly readable again — composition is
// backend-side now, so referralQuery and linkMode have no reason to ship.
it('never publishes referralQuery or linkMode on a store card', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id, ['referral_query' => 'ref=secret']);
    poolPin($siteId, 'shop', shopProduct($pro->id, $store, 'Hat'));

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');

    expect(json_encode($out['collections']))->not->toContain('ref=secret')
        ->and($out['collections'][$store])->not->toHaveKey('referralQuery')
        ->and($out['collections'][$store])->not->toHaveKey('linkMode');
});

// #SEC-2: the last gate before the CDN. Every URL-shaped field on the pool
// wire must pass UrlSafety::safeHref() — a hand-overridden f_link.url, a
// poisoned store card, a poisoned variant image, and a directly-inserted
// item_links row (bypassing ItemLinkController's url:https validation) all
// reach the same resolver seam, so all four are poisoned here in one pass.
// Assertions are kept as SEPARATE statements (not chained ->and()) per this
// repo's convention: a chained expect() aborts at the first failure, which
// would let a partial fix hide behind an early abort.
it('never publishes a non-http(s) url anywhere on the pool wire (#SEC-2)', function () {
    [$pro, $siteId] = poolTenant();

    // 1. content.manual_overrides poisoning an item's f_link.url.
    $linkedItemId = poolItem($pro->id, poolSource($pro->id, poolConnection($pro->id)), 'video', 'A video', now()->toDateTimeString());
    DB::table('content.manual_overrides')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $linkedItemId,
        'facet' => 'f_link', 'column_name' => 'url',
        'value' => json_encode('javascript:alert(1)'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // 2. A store poisoned on all three of its URL fields.
    $store = shopStore($pro->id, [
        'url' => 'javascript:alert(1)',
        'favicon_url' => 'data:text/html;base64,x',
        'logo_url' => 'vbscript:x',
    ]);
    $productId = shopProduct($pro->id, $store, 'Hat');
    poolPin($siteId, 'shop', $productId);

    // 3. A variant with a poisoned image_url.
    $variantSourceId = DB::table('content.source_items')->where('item_id', $productId)->value('source_id');
    DB::table('content.item_variants')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $productId,
        'source_id' => $variantSourceId, 'label' => 'Small', 'sku' => 'sku-s',
        'position' => 0, 'image_url' => 'data:text/html;base64,x',
    ]);

    // 4. A directly-inserted item_links row (bypassing ItemLinkController's
    // url:https validation) alongside a legitimate https sibling.
    DB::table('content.item_links')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $linkedItemId,
        'platform' => 'evil', 'url' => 'javascript:alert(1)',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.item_links')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $linkedItemId,
        'platform' => 'website', 'url' => 'https://example.com/safe',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $watchOut = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'watch');
    $shopOut = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');

    $linkedItem = collect($watchOut['library'])->firstWhere('id', $linkedItemId);
    expect($linkedItem)->not->toBeNull();
    expect($linkedItem['url'])->toBeNull();

    $linkPlatforms = collect($linkedItem['links'])->pluck('platform')->all();
    expect($linkPlatforms)->not->toContain('evil');
    expect($linkPlatforms)->toContain('website');

    $card = $shopOut['collections'][$store] ?? null;
    expect($card)->not->toBeNull();
    expect($card['url'])->toBeNull();
    expect($card['favicon'])->toBeNull();
    expect($card['logo'])->toBeNull();
    // Still present, key set unchanged — a gated field is null, never absent.
    expect($card)->toHaveKey('url');
    expect($card)->toHaveKey('favicon');
    expect($card)->toHaveKey('logo');

    $product = collect($shopOut['library'])->firstWhere('id', $productId);
    expect($product)->not->toBeNull();
    expect($product['variants'][0]['imageUrl'])->toBeNull();

    // Blanket sweep — none of the three unsafe schemes anywhere on either payload.
    $encoded = json_encode($watchOut).json_encode($shopOut);
    expect($encoded)->not->toContain('javascript:');
    expect($encoded)->not->toContain('vbscript:');
    expect($encoded)->not->toContain('data:text/html');
});

// The over-correction guard: a blanket rejection would pass the case above and
// break every real drag/store/link — this proves the gate is a filter, not a
// blackhole, by round-tripping legitimate https values byte-unchanged.
it('leaves a legitimate https url exactly as stored', function () {
    [$pro, $siteId] = poolTenant();

    $store = shopStore($pro->id, [
        'url' => 'https://store.example.com',
        'favicon_url' => 'https://cdn.example.com/fav.ico',
        'logo_url' => 'https://cdn.example.com/logo.png',
    ]);
    $productId = shopProduct($pro->id, $store, 'Hat');
    poolPin($siteId, 'shop', $productId);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');
    $card = $out['collections'][$store] ?? null;
    $item = collect($out['library'])->firstWhere('id', $productId);

    expect($card)->not->toBeNull();
    expect($card['url'])->toBe('https://store.example.com');
    expect($card['favicon'])->toBe('https://cdn.example.com/fav.ico');
    expect($card['logo'])->toBe('https://cdn.example.com/logo.png');
    expect($item)->not->toBeNull();
    // A product's top-level `url` is the composed outbound (cart) URL, not the
    // bare stored link — ShopPoolPayloadTest pins that composition. The BARE
    // stored link (from content.f_link, untouched by ShopOutboundUrl) is what
    // round-trips byte-identical, and it is the one this guard is about.
    expect($item['url'])->toBe('https://store.example.com/cart/44073715368070:1');
    expect($item['links'][0]['url'])->toBe('https://store.example.com/products/hat');
});

// C7 — reviewer-sourced URLs (author_photo_url, author_uri) are third-party
// data and reach the SAME gate. Fixtures follow ReviewsPoolTest's shape,
// built inline rather than by calling that file's reviewPoolFixture() helper
// — a helper declared in one test file may not be called from another
// (CrossFileTestHelperGuardTest fatals it under --parallel).
it('never publishes a non-http(s) review author url (#SEC-2)', function () {
    [$pro, $siteId] = poolTenant();
    $connectionId = poolConnection($pro->id, 'google_business.listing');
    $sourceId = poolSource($pro->id, $connectionId);

    $itemId = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'review',
        'headline_cache' => null, 'facets_cache' => '["f_review"]', 'eligible_cache' => '[]',
        'first_seen_at' => now()->subDay(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $sourceId,
        'coord' => 'review:'.Str::random(8), 'item_id' => $itemId, 'kind' => 'review',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    DB::table('content.f_review')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'author_name' => 'A Real Person',
        'author_photo_url' => 'javascript:alert(1)',
        'author_uri' => 'javascript:alert(1)',
        'rating' => 5.0, 'text' => 'Nice.', 'reviewed_at' => '2026-07-01T10:00:00Z',
        'updated_at' => now(),
    ]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'reviews');
    $item = collect($resolved['selection'])->firstWhere('id', $itemId);

    expect($item)->not->toBeNull();
    expect($item['review']['authorPhotoUrl'])->toBeNull();
    expect($item['review']['authorUri'])->toBeNull();
    expect($item['review']['authorName'])->toBe('A Real Person');
});
