<?php

use App\Http\Middleware\EnsureAffiliateAccount;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\Store\BrandCatalogService;
use App\Services\Store\CustomPhotoPermissionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $sqlite = config('database.connections.sqlite');
    config([
        'database.default' => 'sqlite',
        'database.connections.pgsql' => array_merge($sqlite, ['database' => ':memory:']),
    ]);

    DB::purge('pgsql');
    DB::reconnect('pgsql');

    $conn = DB::connection('pgsql');

    foreach (['core', 'site', 'brand', 'commerce'] as $schema) {
        try {
            $conn->statement("ATTACH DATABASE ':memory:' AS {$schema}");
        } catch (\Throwable) {
        }
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professionals (
        id TEXT PRIMARY KEY,
        auth_user_id TEXT,
        handle TEXT,
        handle_lc TEXT,
        display_name TEXT,
        bio TEXT,
        first_name TEXT,
        last_name TEXT,
        phone TEXT,
        primary_email TEXT,
        public_contact_number TEXT,
        public_contact_email TEXT,
        professional_type TEXT DEFAULT "professional",
        account_type TEXT NULL,
        status TEXT DEFAULT "active",
        onboarding_step INTEGER DEFAULT 0,
        country_code TEXT,
        timezone TEXT,
        location_street_address TEXT,
        location_city TEXT,
        location_state TEXT,
        location_postcode TEXT,
        location_country TEXT,
        stripe_connect_account_id TEXT,
        stripe_customer_id TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS site.sites (
        id TEXT PRIMARY KEY,
        professional_id TEXT,
        subdomain TEXT,
        theme_id TEXT,
        is_published INTEGER DEFAULT 0,
        settings TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS site.site_media (
        id TEXT PRIMARY KEY,
        site_id TEXT NOT NULL,
        pool TEXT NOT NULL DEFAULT "gallery",
        path TEXT,
        alt_text TEXT,
        sort_order INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        media_type TEXT DEFAULT "image",
        processing_state TEXT DEFAULT "pending",
        processing_error TEXT,
        original_mime TEXT,
        original_size_bytes INTEGER,
        duration_ms INTEGER,
        poster_path TEXT,
        product_gid TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS site.media_variants (
        id TEXT PRIMARY KEY,
        media_id TEXT NOT NULL,
        variant_key TEXT NOT NULL,
        artifact_type TEXT NOT NULL,
        disk TEXT,
        path TEXT,
        mime TEXT,
        width INTEGER,
        height INTEGER,
        bitrate_kbps INTEGER,
        file_size_bytes INTEGER,
        duration_ms INTEGER,
        metadata TEXT,
        content_hash TEXT,
        url TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_partner_links (
        id TEXT PRIMARY KEY,
        affiliate_professional_id TEXT NOT NULL,
        brand_professional_id TEXT NOT NULL,
        slot INTEGER NOT NULL DEFAULT 0,
        custom_photos_enabled INTEGER,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NOT NULL,
        provider TEXT NOT NULL,
        external_account_id TEXT,
        access_token TEXT,
        refresh_token TEXT,
        expires_at TEXT,
        catalog_latest_time TEXT,
        last_catalog_sync_at TEXT,
        last_catalog_sync_error TEXT,
        provider_metadata TEXT,
        shopify_shop_domain TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS commerce.affiliate_product_selections (
        id TEXT PRIMARY KEY,
        affiliate_professional_id TEXT NOT NULL,
        shopify_product_gid TEXT NOT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT,
        updated_at TEXT
    )');

    Storage::fake('media');

    $cacheService = Mockery::mock(SiteCacheService::class);
    $cacheService->shouldReceive('invalidateSite');
    app()->instance(SiteCacheService::class, $cacheService);
})->group('affiliate-product-photos');

function setupBrandAndAffiliate(array $brandMetadata = []): array
{
    $brandId = (string) Str::uuid();
    $affiliateId = (string) Str::uuid();
    $brandSiteId = (string) Str::uuid();
    $affiliateSiteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $brandId,
        'handle' => 'testbrand',
        'handle_lc' => 'testbrand',
        'display_name' => 'Test Brand',
        'primary_email' => 'brand@test.com',
        'professional_type' => 'brand',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $affiliateId,
        'handle' => 'testaffiliate',
        'handle_lc' => 'testaffiliate',
        'display_name' => 'Test Affiliate',
        'primary_email' => 'affiliate@test.com',
        'professional_type' => 'professional',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        ['id' => $brandSiteId, 'professional_id' => $brandId, 'subdomain' => 'testbrand', 'settings' => '{}', 'created_at' => $now, 'updated_at' => $now],
        ['id' => $affiliateSiteId, 'professional_id' => $affiliateId, 'subdomain' => 'testaffiliate', 'settings' => '{}', 'created_at' => $now, 'updated_at' => $now],
    ]);

    DB::connection('pgsql')->table('brand.brand_partner_links')->insert([
        'id' => (string) Str::uuid(),
        'affiliate_professional_id' => $affiliateId,
        'brand_professional_id' => $brandId,
        'slot' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $metadata = array_merge(['custom_photos_enabled' => true], $brandMetadata);

    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $brandId,
        'provider' => 'shopify',
        'external_account_id' => 'test.myshopify.com',
        'provider_metadata' => json_encode($metadata),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $brand = Professional::find($brandId);
    $affiliate = Professional::find($affiliateId);

    return [$brand, $affiliate, $affiliateSiteId];
}

function addProductSelection(string $affiliateId, string $gid = 'gid://shopify/Product/123'): void
{
    AffiliateProductSelection::create([
        'affiliate_professional_id' => $affiliateId,
        'shopify_product_gid' => $gid,
        'sort_order' => 0,
    ]);
}

function insertProductPhoto(string $siteId, string $gid, int $sortOrder = 0): SiteMedia
{
    return SiteMedia::create([
        'site_id' => $siteId,
        'pool' => SiteMedia::POOL_PRODUCT,
        'product_gid' => $gid,
        'path' => 'images/test/original.jpg',
        'alt_text' => 'Custom photo',
        'sort_order' => $sortOrder,
        'is_active' => true,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => SiteMedia::PROCESSING_STATE_READY,
    ]);
}

// --- Permission Service Tests ---

it('allows custom photos when global is true', function () {
    [$brand] = setupBrandAndAffiliate(['custom_photos_enabled' => true]);

    $service = app(CustomPhotoPermissionService::class);
    expect($service->isAllowed($brand->id))->toBeTrue();
});

it('blocks custom photos when global is false', function () {
    [$brand] = setupBrandAndAffiliate(['custom_photos_enabled' => false]);

    $service = app(CustomPhotoPermissionService::class);
    expect($service->isAllowed($brand->id))->toBeFalse();
});

it('per-product opt-out blocks upload when global is on', function () {
    [$brand] = setupBrandAndAffiliate(['custom_photos_enabled' => true]);
    $gid = 'gid://shopify/Product/999';

    $catalog = Mockery::mock(BrandCatalogService::class);
    $catalog->shouldReceive('fetchProductCustomPhotosMetafield')
        ->with(Mockery::any(), $gid)
        ->andReturn(false);
    app()->instance(BrandCatalogService::class, $catalog);

    $service = app(CustomPhotoPermissionService::class);
    expect($service->isAllowed($brand->id, $gid))->toBeFalse();
});

it('per-product opt-out is ignored when global is off', function () {
    [$brand] = setupBrandAndAffiliate(['custom_photos_enabled' => false]);
    $gid = 'gid://shopify/Product/999';

    $catalog = Mockery::mock(BrandCatalogService::class);
    // Should not reach per-product check since global is off first
    $catalog->shouldNotReceive('fetchProductCustomPhotosMetafield');
    app()->instance(BrandCatalogService::class, $catalog);

    $service = app(CustomPhotoPermissionService::class);
    expect($service->isAllowed($brand->id, $gid))->toBeFalse();
});

it('per-product unset falls through to global', function () {
    [$brand] = setupBrandAndAffiliate(['custom_photos_enabled' => true]);
    $gid = 'gid://shopify/Product/999';

    $catalog = Mockery::mock(BrandCatalogService::class);
    $catalog->shouldReceive('fetchProductCustomPhotosMetafield')
        ->with(Mockery::any(), $gid)
        ->andReturn(null);
    app()->instance(BrandCatalogService::class, $catalog);

    $service = app(CustomPhotoPermissionService::class);
    expect($service->isAllowed($brand->id, $gid))->toBeTrue();
});

it('defaults to true when no integration exists', function () {
    $service = app(CustomPhotoPermissionService::class);
    expect($service->getGlobalSetting((string) Str::uuid()))->toBeTrue();
});

it('returns correct photo position', function () {
    [$brand] = setupBrandAndAffiliate(['custom_photo_position' => 'mixed']);

    $service = app(CustomPhotoPermissionService::class);
    expect($service->getPhotoPosition($brand->id))->toBe('mixed');
});

it('defaults photo position to after', function () {
    [$brand] = setupBrandAndAffiliate([]);

    $service = app(CustomPhotoPermissionService::class);
    expect($service->getPhotoPosition($brand->id))->toBe('after');
});

// --- Controller Tests ---

it('lists product photos for a selected product', function () {
    [$brand, $affiliate, $siteId] = setupBrandAndAffiliate();
    $gid = 'gid://shopify/Product/123';

    addProductSelection($affiliate->id, $gid);
    insertProductPhoto($siteId, $gid, 0);
    insertProductPhoto($siteId, $gid, 1);

    $controller = app(\App\Http\Controllers\Api\Professional\Store\AffiliateProductPhotoController::class);

    $request = \Illuminate\Http\Request::create("/api/affiliate/products/{$gid}/photos", 'GET');
    $request->attributes->set('professional', $affiliate);

    $response = $controller->index($request, $gid);

    expect($response->status())->toBe(200);
    $data = $response->getData(true);
    expect($data['images'])->toHaveCount(2);
    expect($data['product_gid'])->toBe($gid);
    expect($data['limit'])->toBe(1);
});

it('returns 403 for brand accounts via affiliate.only middleware', function () {
    // The controller no longer contains inline isBrand() checks; the
    // EnsureAffiliateAccount middleware is the authoritative gate.
    $brand = new Professional(['professional_type' => 'brand']);
    $request = Request::create('/api/affiliate/products/gid://shopify/Product/123/photos', 'GET');
    $request->attributes->set('professional', $brand);

    $response = (new EnsureAffiliateAccount)->handle($request, fn ($req) => new Response('ok', 200));

    expect($response->getStatusCode())->toBe(403);
});

it('returns 422 for invalid GID format', function () {
    [, $affiliate] = setupBrandAndAffiliate();

    $controller = app(\App\Http\Controllers\Api\Professional\Store\AffiliateProductPhotoController::class);

    $request = \Illuminate\Http\Request::create('/api/affiliate/products/invalid-gid/photos', 'GET');
    $request->attributes->set('professional', $affiliate);

    $response = $controller->index($request, 'invalid-gid');

    expect($response->status())->toBe(422);
});

// --- Config Tests ---

it('has product_custom pool limit of 1', function () {
    expect(config('partna.image_pools.product_custom.max'))->toBe(1);
});

it('SiteMedia has product_gid in fillable', function () {
    expect(in_array('product_gid', (new SiteMedia)->getFillable()))->toBeTrue();
});

it('BrandPartnerLink has custom_photos_enabled in fillable', function () {
    expect(in_array('custom_photos_enabled', (new BrandPartnerLink)->getFillable()))->toBeTrue();
});
