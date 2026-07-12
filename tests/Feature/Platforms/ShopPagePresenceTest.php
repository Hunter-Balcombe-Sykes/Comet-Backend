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

use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable(); // also creates shop_brands / shop_products / platform_connections
});

function spConnection(User $user, string $platform, array $payload = []): string
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'platform' => $platform,
        'resource_id' => 'res-'.Str::random(6),
        'payload' => json_encode($payload),
        'is_active' => 1,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $id;
}

function spBrand(string $connectionId): string
{
    $brandId = (string) Str::uuid();
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('site.shop_brands')->insert([
        'id' => $brandId,
        'connection_id' => $connectionId,
        'brand_id' => 'b1',
        'provider' => 'shopify',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return $brandId;
}

function spProduct(string $brandId): void
{
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('site.shop_products')->insert([
        'id' => (string) Str::uuid(),
        'brand_id' => $brandId,
        'product_id' => 'p1',
        'position' => 0,
        'data' => json_encode(['price' => '10.00', 'currency' => 'AUD']),
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

it('drops the shop page from presence when a brand is connected with zero products', function () {
    $pro = createTenant('shop-empty-brand');
    $connId = spConnection($pro, 'shop', ['storage' => 'relational']);
    spBrand($connId); // no products

    $pages = app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($pages)->not->toContain('shop');
});

it('keeps the shop page present once the connected brand has a chosen product', function () {
    $pro = createTenant('shop-with-product');
    $connId = spConnection($pro, 'shop', ['storage' => 'relational']);
    $brandId = spBrand($connId);
    spProduct($brandId);

    $pages = app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($pages)->toContain('shop');
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
