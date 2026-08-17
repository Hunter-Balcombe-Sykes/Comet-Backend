<?php

// FOUND-25 fix: a 'shop' IntegrationConnection's payload is a static
// lifecycle marker — stores and their products live in their own rows
// (content.storefronts + content.collection_items, formerly the relational
// ShopBrand/ShopProduct pair), decoupled from connect
// (ShopController::addBrand stores a brand with zero products; the picker
// runs any time after). Before this
// fix, presentPageIds() treated any active 'shop' connection as enough to
// advertise the Shop page, so a brand added with no products yet (or ever)
// showed an empty Shop page to site visitors. Bandcamp also maps to the Shop
// page but isn't FOUND-25 (its connection payload carries real scraped
// content directly), so it's asserted separately to confirm it's untouched.

use App\Catalog\LegacyPlatformMap;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\PublicSite\SitepageDataResolverService;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable(); // also creates site.platform_connections
    // Slice 5a Task 8 moved the shop presence gate onto content.*; slice 5b
    // Task 8 moved it one step further, onto PoolResolver::hasSelection() —
    // the same question `profile.pools.shop` answers. Fixtures still land in
    // content.* through the production writer rather than by hand.
    setupIngestTables();
    setupContentTables();
    // hasSelection() provisions the pool's page/section on first ask.
    setupSectionsTables();
});

function spConnection(User $user, string $platform, array $payload = []): string
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'surface_key' => LegacyPlatformMap::surfaceFor($platform),
        'routing_class' => LegacyPlatformMap::routingClassFor(LegacyPlatformMap::surfaceFor($platform)),
        'resource_id' => 'res-'.Str::random(6),
        'payload' => json_encode($payload),
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

/**
 * The store, as addBrand() writes it — one upsertStore() call in the same
 * locked block. It used to write a site.shop_brands row first and mirror it
 * into content.*; the re-home dropped that table, so content.collections +
 * content.storefronts IS the store. Returns the content.collections id, which
 * is what a product hangs off.
 */
function spBrand(User $user, ?string $connectStatus = null): string
{
    return app(ShopContentWriter::class)->upsertStore(new StoreRecord(
        externalRef: 'b1',
        provider: 'shopify',
        url: 'https://b1.example.com',
        connectStatus: $connectStatus,
    ), (string) $user->id);
}

function spProduct(User $user, string $collectionId): void
{
    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [[
        'productId' => 'p1', 'title' => 'Product 1', 'url' => 'https://b1.example.com/p1',
        'price' => '10.00', 'currency' => 'AUD', 'available' => true,
        'image' => null, 'images' => [], 'variants' => [],
    ]], 'AUD');
}

it('drops the shop page from presence when a brand is connected with zero products', function () {
    $pro = createTenant('shop-empty-brand');
    spConnection($pro, 'shop', ['storage' => 'relational']);
    spBrand($pro); // no products

    $pages = app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($pages)->not->toContain('shop');
});

it('keeps the shop page present once the connected brand has a chosen product', function () {
    $pro = createTenant('shop-with-product');
    spConnection($pro, 'shop', ['storage' => 'relational']);
    $collectionId = spBrand($pro);
    spProduct($pro, $collectionId);

    $pages = app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($pages)->toContain('shop');
});

// ── W9 P2 review fix, RE-BASED by slice 5b (2026-08-13) ───────────────────
//
// GET /brands/{id}/products and PUT …/selection both work during the pending
// window BY DESIGN (plan §3e), so a brand can already have a saved product
// selection while still connect_status='pending'. W9 therefore made
// page-presence reject a pending brand, because
// PublicIntegrationConnectionResource::filterPayload() rejected it too:
// without the exclusion, presence said "shop" was present while the payload
// shipped empty — an empty Shop page, CDN-cached, indefinitely.
//
// Slice 5b retired that payload. Presence is the pool's answer now, and the
// pool has no notion of connect_status, so a pending store's products both
// COUNT and RENDER. The two sides still agree — which was always the actual
// requirement — but they now agree on "present" rather than "absent". A
// stranded pending brand shows its products instead of an empty page, which
// is the better failure mode of the two.

it('keeps the shop page present for a pending brand with a saved product — the pool renders it', function () {
    $pro = createTenant('shop-pending-with-product');
    spConnection($pro, 'shop', ['storage' => 'relational']);
    $collectionId = spBrand($pro, 'pending');
    spProduct($pro, $collectionId);

    $pages = app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($pages)->toContain('shop');
});

it('keeps the shop page present for a failed brand with a chosen product — failed is deliberately public (plan §3g)', function () {
    $pro = createTenant('shop-failed-with-product');
    spConnection($pro, 'shop', ['storage' => 'relational']);
    $collectionId = spBrand($pro, 'failed');
    spProduct($pro, $collectionId);

    $pages = app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($pages)->toContain('shop');
});

// ── Slice 5a Task 8, fix round 1 (C3): an active connection is not a store ──
//
// This test used to drive the UN-BACKFILLED state: a site.shop_brands row with
// a full site.shop_products selection and nothing in content.*, pinning the
// honest consequence of Task 8 deleting the hybrid read — such a brand
// advertised no shop page until ShopBackfiller ran. That premise is now
// unbuildable: the re-home dropped both legacy tables and deleted the
// backfiller with them, so there is no second store of truth left to be out of
// step with, and no backfill to sequence a deploy around.
//
// The GUARANTEE it protected is still live, and still reachable by another
// route: an ACTIVE shop connection with no content.storefronts row — what a
// store retired by removeBrand(), or a connect that armed its anchor before
// upsertStore() landed, leaves behind. Presence must answer on content.*, never
// on the bare existence of an active connection, which is precisely the
// FOUND-25 bug this file's header describes. So the assertion moves onto that
// state rather than going with the tables.
it('drops the shop page for an active shop connection with no content.* store', function () {
    $pro = createTenant('shop-connection-no-store');
    spConnection($pro, 'shop', ['storage' => 'relational']);

    // Non-vacuous: the connection genuinely exists and is active, so a presence
    // gate reading is_active alone would answer "shop" here...
    expect(DB::connection('pgsql')->table('site.platform_connections')
        ->where('user_id', $pro->id)->where('is_active', 1)->count())->toBe(1)
        ->and(DB::table('content.storefronts')->count())->toBe(0);

    $pages = app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    // ...and the sitepage advertises nothing, because content.* is empty.
    expect($pages)->not->toContain('shop');
});

it('keeps the shop page present for an active bandcamp connection with no store products', function () {
    // Bandcamp isn't FOUND-25 — its connection payload carries real content
    // directly, so it must keep the blanket is_active signal untouched by
    // the shop-specific product gate.
    $pro = createTenant('shop-bandcamp');
    spConnection($pro, 'bandcamp', ['name' => 'Some Artist', 'tracks' => [['title' => 'Track 1']]]);

    $pages = app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($pages)->toContain('shop');
});
