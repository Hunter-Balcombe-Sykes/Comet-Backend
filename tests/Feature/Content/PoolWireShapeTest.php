<?php

use App\Models\Core\Site\Site;
use App\Site\Actions\ActionCandidates;
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
    // Person-scoping (2026-08-28) joins ingest.sources for partna review
    // candidates, so the mirror must exist.
    setupIngestTables();
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
//
// Regression fix (audit-fix p1 sweep, 2026-08-24): an item whose f_link.url
// override is unsafe used to be emitted with url=>null, which silently
// un-dropped it from ActionCandidates (the emit-path guard reads the DIRTY
// value as its drop signal — see PoolResolver::itemPayloads()). PoolResolver
// now drops such an item from the pool selection entirely instead of nulling
// its url, so this item lives on its OWN fixture (2a) with no other poisoning
// riding along, and its own item_links poisoning check moved to a SEPARATE,
// unpoisoned item (2b) — the dropped item can no longer be inspected for its
// links[] shape once it is absent from the payload.
it('never publishes a non-http(s) url anywhere on the pool wire (#SEC-2)', function () {
    [$pro, $siteId] = poolTenant();

    // 1. content.manual_overrides poisoning an item's f_link.url — the whole
    // item is dropped from the pool, not emitted with url=>null.
    $overriddenItemId = poolItem($pro->id, poolSource($pro->id, poolConnection($pro->id)), 'video', 'A video', now()->toDateTimeString());
    DB::table('content.manual_overrides')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $overriddenItemId,
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

    // 4. A SEPARATE item — carrying its own SAFE source link (so its top-level
    // url/primary is unaffected by the drop-the-whole-item rule above) plus a
    // directly-inserted item_links row (bypassing ItemLinkController's
    // url:https validation) alongside a legitimate https sibling. linkSet()
    // must drop the poisoned entry from links[] and keep the safe one.
    $linkedSourceId = poolSource($pro->id, poolConnection($pro->id));
    $linkedItemId = poolItem($pro->id, $linkedSourceId, 'video', 'Another video', now()->toDateTimeString());
    DB::table('content.f_link')->insert([
        'item_id' => $linkedItemId, 'source_id' => $linkedSourceId,
        'url' => 'https://example.com/primary', 'updated_at' => now(),
    ]);
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

    $overriddenItem = collect($watchOut['library'])->firstWhere('id', $overriddenItemId);
    expect($overriddenItem)->toBeNull();

    $linkedItem = collect($watchOut['library'])->firstWhere('id', $linkedItemId);
    expect($linkedItem)->not->toBeNull();

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
    [$pro, $siteId] = poolBusinessTenant();
    $connectionId = poolConnection($pro->id, 'google_business.listing');
    $sourceId = poolSource($pro->id, $connectionId);

    $itemId = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'review',
        'headline_cache' => null, 'facets_cache' => '["f_review"]',
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

// Regression fix (audit-fix p1 sweep, 2026-08-24): PoolResolver used to null
// an unsafe item url instead of dropping the item, which silently un-dropped
// it from ActionCandidates (that guard reads the DIRTY url as its own drop
// signal and cannot tell "no url" apart from "null url" once PoolResolver has
// already sanitised it). This pins the fix at the pool-wire seam directly —
// pools.<pool>.items on the public wire is PoolResolver's `selection` array,
// mapped 1:1 (see PoolWire::forSite) — rather than only at the actions layer.
it('drops an item whose f_link.url override is unsafe from the pool selection entirely, a safe sibling survives', function () {
    [$pro, $siteId] = poolTenant();

    $poisonedId = poolItem($pro->id, poolSource($pro->id, poolConnection($pro->id)), 'video', 'Poisoned', now()->toDateTimeString());
    DB::table('content.manual_overrides')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $poisonedId,
        'facet' => 'f_link', 'column_name' => 'url',
        'value' => json_encode('javascript:alert(1)'),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    poolPin($siteId, 'watch', $poisonedId);

    $safeSourceId = poolSource($pro->id, poolConnection($pro->id));
    $safeId = poolItem($pro->id, $safeSourceId, 'video', 'Safe', now()->toDateTimeString());
    DB::table('content.f_link')->insert([
        'item_id' => $safeId, 'source_id' => $safeSourceId,
        'url' => 'https://example.com/safe-clip', 'updated_at' => now(),
    ]);
    poolPin($siteId, 'watch', $safeId);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'watch');
    $ids = collect($out['selection'])->pluck('id')->all();

    expect($ids)->not->toContain($poisonedId);
    expect($ids)->toContain($safeId);
});

// THE defect this rewrite exists to fix. The first uncommitted attempt read
// only the item's raw TOP-PRIORITY stored url as its drop signal, so a
// high-priority javascript: row deleted the item even though a lower-priority
// https row was sitting right there as a perfectly good destination —
// linkSet() (the code that actually computes the served url) walks every
// source row in priority order and would have served the fallback. This test
// pins that a two-source item keeps the item and serves the survivor.
it('keeps an item and serves its low-priority fallback when only the high-priority link is unsafe', function () {
    [$pro, $siteId] = poolTenant();
    // Two sources means two CONNECTIONS: idx_content_sources_connection is UNIQUE on
    // connection_id, so a single connection can never own two content.sources rows.
    // This fixture used to share one connection id, which the SQLite stand-in accepted
    // only because it was missing that index (added with #W1-LIFE-2).
    $highConnectionId = poolConnection($pro->id);
    $lowConnectionId = poolConnection($pro->id);

    $highSourceId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $highSourceId, 'user_id' => $pro->id, 'kind' => 'connection',
        'connection_id' => $highConnectionId, 'priority' => 200,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $lowSourceId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $lowSourceId, 'user_id' => $pro->id, 'kind' => 'connection',
        'connection_id' => $lowConnectionId, 'priority' => 50,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $itemId = poolItem($pro->id, $lowSourceId, 'video', 'Multi-source video', now()->toDateTimeString());
    // A second source_items row: the SAME item, also discovered by the
    // higher-priority source — this is what makes sourceLinks carry two rows
    // for one item instead of one.
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'source_id' => $highSourceId,
        'coord' => 'x:'.Str::random(8), 'item_id' => $itemId, 'kind' => 'video',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    DB::table('content.f_link')->insert([
        'item_id' => $itemId, 'source_id' => $highSourceId,
        'url' => 'javascript:alert(1)', 'updated_at' => now(),
    ]);
    DB::table('content.f_link')->insert([
        'item_id' => $itemId, 'source_id' => $lowSourceId,
        'url' => 'https://example.com/safe-fallback', 'updated_at' => now(),
    ]);

    poolPin($siteId, 'watch', $itemId);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'watch');
    $item = collect($out['selection'])->firstWhere('id', $itemId);

    expect($item)->not->toBeNull();
    expect($item['url'])->toBe('https://example.com/safe-fallback');
});

// The over-correction guard: a fix that drops every item with a null/absent
// url (instead of only a PRESENT-but-unsafe one) would pass every test above
// and delete every page-anchor item from every pool. An item with no stored
// outbound link at all must still be emitted with url=>null, AND that null
// must still let ActionCandidates fall through to a page-anchor candidate —
// the real downstream behaviour this whole fix exists to preserve.
it('an item with no stored outbound url survives and still anchors to its page', function () {
    [$pro, $siteId] = poolTenant();

    $anchorId = poolItem($pro->id, poolSource($pro->id, poolConnection($pro->id)), 'video', 'No link at all', now()->toDateTimeString());
    poolPin($siteId, 'watch', $anchorId);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'watch');
    $item = collect($resolved['selection'])->firstWhere('id', $anchorId);

    expect($item)->not->toBeNull();
    expect($item['url'])->toBeNull();

    $candidates = collect(ActionCandidates::fromPools(['watch' => ['items' => [$item]]]))->keyBy('id');
    expect($candidates->has('item:'.$anchorId))->toBeTrue();
    expect($candidates['item:'.$anchorId]['url'])->toBe('/watch#'.$anchorId);
});
