<?php

// FOUND-25 fix: a 'shop' IntegrationConnection's payload is a static
// lifecycle marker — brands/products live relationally (ShopBrand/
// ShopProduct), decoupled from connect (ShopController::addBrand stores a
// brand with zero products; the picker runs any time after). Before this
// fix, presentPageIds() treated any active 'shop' connection as enough to
// advertise the Shop page, so a brand added with no products yet (or ever)
// showed an empty Shop page to site visitors. Bandcamp also maps to the Shop
// page but isn't FOUND-25 (its connection payload carries real scraped
// content directly), so it's asserted separately to confirm it's untouched.

use App\Catalog\LegacyPlatformMap;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\PublicSite\SitepageDataResolverService;
use App\Services\Shop\ShopContentWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable(); // also creates shop_brands / shop_products / platform_connections
    // Slice 5a Task 8, fix round 1 (C2): the shop presence gate reads
    // content.* now — the same rows PublicIntegrationConnectionResource
    // publishes — so these fixtures land there too, through the production
    // writer rather than by hand.
    setupIngestTables();
    setupContentTables();
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
 * The legacy anchor row PLUS its content.* storefront — what addBrand() writes
 * today (ShopController calls upsertStore() in the same locked block). Returns
 * the content.collections id, which is what a product now hangs off.
 */
function spBrand(User $user, string $connectionId, ?string $connectStatus = null): string
{
    $brand = ShopBrand::create([
        'connection_id' => $connectionId,
        'brand_id' => 'b1',
        'provider' => 'shopify',
        'url' => 'https://b1.example.com',
        'connect_status' => $connectStatus,
        'position' => 0,
    ]);

    return app(ShopContentWriter::class)->upsertStore($brand, (string) $user->id);
}

/** The anchor row ALONE — a brand connected before this slice deployed and
 *  never backfilled, so it has no content.storefronts row at all. */
function spLegacyOnlyBrand(string $connectionId): void
{
    ShopBrand::create([
        'connection_id' => $connectionId,
        'brand_id' => 'b1',
        'provider' => 'shopify',
        'url' => 'https://b1.example.com',
        'position' => 0,
    ]);
    DB::connection('pgsql')->table('site.shop_products')->insert([
        'id' => (string) Str::uuid(),
        'brand_id' => DB::connection('pgsql')->table('site.shop_brands')->where('connection_id', $connectionId)->value('id'),
        'product_id' => 'p1',
        'position' => 0,
        'data' => json_encode(['productId' => 'p1', 'price' => '10.00', 'currency' => 'AUD']),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
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
    $connId = spConnection($pro, 'shop', ['storage' => 'relational']);
    spBrand($pro, $connId); // no products

    $pages = app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($pages)->not->toContain('shop');
});

it('keeps the shop page present once the connected brand has a chosen product', function () {
    $pro = createTenant('shop-with-product');
    $connId = spConnection($pro, 'shop', ['storage' => 'relational']);
    $collectionId = spBrand($pro, $connId);
    spProduct($pro, $collectionId);

    $pages = app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($pages)->toContain('shop');
});

// ── W9 P2 review fix — pending/failed brands must agree with
// PublicIntegrationConnectionResource::filterPayload()'s own reject/keep ──
//
// GET /brands/{id}/products and PUT …/selection both work during the pending
// window BY DESIGN (plan §3e), so a brand can already have a saved product
// selection while still connect_status='pending'. Before this fix,
// shop_active_product_exists had no connect_status filter at all, so
// page-presence said "shop" was present while the public payload
// (filterPayload()) rejected the very same pending brand — an empty Shop
// page, CDN-cached, indefinitely (the stale-pending backstop never WRITES the
// row, so a stranded pending brand never un-pends on its own).

it('drops the shop page from presence when the only brand is pending, even though it already has a saved product', function () {
    $pro = createTenant('shop-pending-with-product');
    $connId = spConnection($pro, 'shop', ['storage' => 'relational']);
    $collectionId = spBrand($pro, $connId, 'pending');
    spProduct($pro, $collectionId);

    $pages = app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($pages)->not->toContain('shop');
});

it('keeps the shop page present for a failed brand with a chosen product — failed is deliberately public (plan §3g)', function () {
    $pro = createTenant('shop-failed-with-product');
    $connId = spConnection($pro, 'shop', ['storage' => 'relational']);
    $collectionId = spBrand($pro, $connId, 'failed');
    spProduct($pro, $collectionId);

    $pages = app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($pages)->toContain('shop');
});

// ── Slice 5a Task 8, fix round 1 (C3): the un-backfilled state ────────────
//
// ShopBackfiller has NOT been run on dev (0 content.storefronts rows against
// 9 legacy brands / 51 legacy products at the time of writing), and Task 8
// deleted the hybrid read that used to cover for that. This test pins the
// honest consequence rather than leaving it to be discovered in production:
// a brand connected before this slice deploys, with a full legacy product
// selection, advertises NO shop page until the backfill runs. Backfill first,
// then deploy — see the rollout note in the Task 8 report.
it('drops the shop page for a legacy brand that was never backfilled into content.*', function () {
    $pro = createTenant('shop-never-backfilled');
    $connId = spConnection($pro, 'shop', ['storage' => 'relational']);
    spLegacyOnlyBrand($connId);

    // The legacy tables say this user has a store with a chosen product...
    expect(DB::connection('pgsql')->table('site.shop_products')->count())->toBe(1)
        ->and(DB::table('content.storefronts')->count())->toBe(0);

    $pages = app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    // ...and the sitepage advertises nothing, because content.* is empty.
    expect($pages)->not->toContain('shop');
});

it('keeps the shop page present for an active bandcamp connection with no ShopProduct rows', function () {
    // Bandcamp isn't FOUND-25 — its connection payload carries real content
    // directly, so it must keep the blanket is_active signal untouched by
    // the shop-specific product gate.
    $pro = createTenant('shop-bandcamp');
    spConnection($pro, 'bandcamp', ['name' => 'Some Artist', 'tracks' => [['title' => 'Track 1']]]);

    $pages = app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($pages)->toContain('shop');
});
