<?php

use App\Http\Controllers\Api\Professional\Brand\BrandGalleryController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use App\Services\Media\ImageVariantService;
use Illuminate\Http\Request;
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

    foreach (['core', 'site', 'brand'] as $schema) {
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
        has_historical_partner_links INTEGER NULL,
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

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_profiles (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        brand_status TEXT NULL DEFAULT "building",
        setup_complete INTEGER NULL,
        business_website TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_store_settings (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        default_commission_rate TEXT NULL,
        payout_hold_days INTEGER NULL,
        theme_id INTEGER NULL,
        oxygen_deployment_token TEXT NULL,
        oxygen_storefront_id TEXT NULL,
        hydrogen_install_confirmed INTEGER NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS core.professional_integrations (
        id TEXT PRIMARY KEY,
        professional_id TEXT NULL,
        provider TEXT NULL,
        external_account_id TEXT NULL,
        access_token TEXT NULL,
        refresh_token TEXT NULL,
        expires_at TEXT NULL,
        catalog_latest_time TEXT NULL,
        last_catalog_sync_at TEXT NULL,
        last_catalog_sync_error TEXT NULL,
        provider_metadata TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    Storage::fake('media');

    // Mock SiteCacheService to avoid querying tables not in test schema
    $cacheService = Mockery::mock(SiteCacheService::class);
    $cacheService->shouldReceive('invalidateSite');
    app()->instance(SiteCacheService::class, $cacheService);
})->group('brand-gallery');

function createBrandWithSite(string $handle = 'gallbrand'): array
{
    $brandId = (string) Str::uuid();
    $siteId = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $brandId,
        'auth_user_id' => 'auth-'.Str::random(8),
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'primary_email' => "{$handle}@example.com",
        'professional_type' => 'brand',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $brandId,
        'subdomain' => $handle,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $pro = Professional::find($brandId);
    $site = Site::find($siteId);

    return [$pro, $site];
}

function createNonBrand(string $handle = 'affiliate1'): Professional
{
    $id = (string) Str::uuid();
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'auth_user_id' => 'auth-'.Str::random(8),
        'handle' => $handle,
        'handle_lc' => strtolower($handle),
        'display_name' => ucfirst($handle),
        'primary_email' => "{$handle}@example.com",
        'professional_type' => 'professional',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $id,
        'subdomain' => $handle,
        'settings' => json_encode([]),
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return Professional::find($id);
}

function insertBrandGalleryImage(string $siteId, int $sortOrder = 0, string $state = 'ready'): SiteMedia
{
    $media = SiteMedia::create([
        'site_id' => $siteId,
        'pool' => SiteMedia::POOL_BRAND_GALLERY,
        'path' => 'images/test/original.jpg',
        'alt_text' => 'Test image',
        'sort_order' => $sortOrder,
        'is_active' => true,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => $state,
        'original_mime' => 'image/jpeg',
        'original_size_bytes' => 1024,
    ]);

    return $media;
}

function makeBrandGalleryRequest(Professional $pro, string $method = 'GET', array $params = []): Request
{
    $request = Request::create('/api/brand/gallery', $method, $params);
    $request->attributes->set('professional', $pro);

    return $request;
}

// --- Index Tests ---

it('returns brand gallery images ordered by sort_order', function () {
    [$brand, $site] = createBrandWithSite();

    insertBrandGalleryImage($site->id, 1);
    insertBrandGalleryImage($site->id, 0);
    insertBrandGalleryImage($site->id, 2);

    $mediaService = Mockery::mock(ImageVariantService::class);
    $controller = new BrandGalleryController($mediaService);

    $request = makeBrandGalleryRequest($brand);
    $response = $controller->index($request);

    expect($response->status())->toBe(200);

    $data = $response->getData(true);
    expect($data['images'])->toHaveCount(3);
    expect($data['images'][0]['sort_order'])->toBe(0);
    expect($data['images'][1]['sort_order'])->toBe(1);
    expect($data['images'][2]['sort_order'])->toBe(2);
    expect($data['limit'])->toBe(5);
});

// Non-brand access is now rejected by the `brand.only` middleware (EnsureBrandAccount)
// before the controller is reached — see tests/Unit/Middleware/EnsureBrandAccountTest.php.

// --- Delete Tests ---

it('deletes a brand gallery image', function () {
    [$brand, $site] = createBrandWithSite('delbrand');

    $media = insertBrandGalleryImage($site->id);

    $mediaService = Mockery::mock(ImageVariantService::class);
    $mediaService->shouldReceive('deleteVariants')->once();

    $controller = new BrandGalleryController($mediaService);

    $request = makeBrandGalleryRequest($brand, 'DELETE');
    $response = $controller->destroy($request, $media->id);

    expect($response->status())->toBe(200);
    expect(SiteMedia::find($media->id))->toBeNull();
});

it('returns 404 when deleting image from another brand', function () {
    [$brand1, $site1] = createBrandWithSite('brand1');
    [$brand2, $site2] = createBrandWithSite('brand2');

    $media = insertBrandGalleryImage($site1->id);

    $mediaService = Mockery::mock(ImageVariantService::class);
    $controller = new BrandGalleryController($mediaService);

    $request = makeBrandGalleryRequest($brand2, 'DELETE');
    $response = $controller->destroy($request, $media->id);

    expect($response->status())->toBe(404);
});

it('returns 404 when deleting non-brand-gallery pool image', function () {
    [$brand, $site] = createBrandWithSite('poolbrand');

    // Create a regular gallery image, not brand_gallery
    $media = SiteMedia::create([
        'site_id' => $site->id,
        'pool' => SiteMedia::POOL_GALLERY,
        'path' => 'images/test/original.jpg',
        'sort_order' => 0,
        'is_active' => true,
        'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
        'processing_state' => 'ready',
    ]);

    $mediaService = Mockery::mock(ImageVariantService::class);
    $controller = new BrandGalleryController($mediaService);

    $request = makeBrandGalleryRequest($brand, 'DELETE');
    $response = $controller->destroy($request, $media->id);

    expect($response->status())->toBe(404);
});

// --- Pool Constant ---

it('has brand_gallery pool constant', function () {
    expect(SiteMedia::POOL_BRAND_GALLERY)->toBe('brand_gallery');
});

it('has brand_gallery config limit of 5', function () {
    expect(config('partna.image_pools.brand_gallery.max'))->toBe(5);
});
