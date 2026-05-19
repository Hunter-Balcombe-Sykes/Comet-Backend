<?php

use App\Http\Controllers\Api\Professional\Store\ShopifyResyncController;
use App\Jobs\Shopify\SyncShopifyBrandDesignJob;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Shopify\ShopifyDataResyncService;
use App\Services\Shopify\ShopProfileAutoFillService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
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
        first_name TEXT,
        last_name TEXT,
        primary_email TEXT,
        phone TEXT,
        professional_type TEXT DEFAULT "professional",
        account_type TEXT NULL,
        status TEXT DEFAULT "active",
        country_code TEXT,
        timezone TEXT,
        location_street_address TEXT,
        location_city TEXT,
        location_state TEXT,
        location_postcode TEXT,
        location_country TEXT,
        created_at TEXT,
        updated_at TEXT,
        deleted_at TEXT
    )');

    $conn->statement('CREATE TABLE IF NOT EXISTS brand.brand_profiles (
        id TEXT PRIMARY KEY,
        professional_id TEXT UNIQUE,
        business_website TEXT,
        setup_complete INTEGER DEFAULT 0,
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
        disconnected_at TEXT,
        webhook_registration_state TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    RateLimiter::clear('shopify-resync:int-resync-1');
})->group('shopify-resync');

// Fake shop.json payload. Override individual keys to test empty-field behavior.
function resyncFakeShopPayload(array $overrides = []): array
{
    return array_merge([
        'id' => 123456,
        'name' => 'Fresh Brand Name',
        'email' => 'fresh@shop.example',
        'phone' => '+61400000000',
        'address1' => '100 Fresh Street',
        'city' => 'Sydney',
        'province' => 'NSW',
        'zip' => '2000',
        'country_name' => 'Australia',
        'country_code' => 'AU',
        'iana_timezone' => 'Australia/Sydney',
        'domain' => 'freshbrand.myshopify.com',
        'currency' => 'AUD',
    ], $overrides);
}

// Seed a brand + brand_profile + integration. Accepts overrides on the
// Professional row so tests can simulate manually-edited DB state.
function createResyncBrand(array $professionalAttrs = [], array $brandAttrs = [], array $integrationMetadata = []): array
{
    $brandId = 'brand-resync-1';
    $now = now()->toDateTimeString();

    DB::connection('pgsql')->table('core.professionals')->insert(array_merge([
        'id' => $brandId,
        'handle' => 'resyncbrand',
        'handle_lc' => 'resyncbrand',
        'display_name' => 'Original Name',
        'primary_email' => 'original@example.com',
        'phone' => '+61411111111',
        'location_street_address' => '1 Original Rd',
        'location_city' => 'Melbourne',
        'location_state' => 'VIC',
        'location_postcode' => '3000',
        'location_country' => 'Australia',
        'country_code' => 'AU',
        'timezone' => 'Australia/Melbourne',
        'professional_type' => 'brand',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ], $professionalAttrs));

    DB::connection('pgsql')->table('brand.brand_profiles')->insert(array_merge([
        'id' => (string) Str::uuid(),
        'professional_id' => $brandId,
        'business_website' => 'original.example.com',
        'setup_complete' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ], $brandAttrs));

    $metadata = array_merge([
        'shop_domain' => 'resyncbrand.myshopify.com',
        'shop_currency' => 'USD',
    ], $integrationMetadata);

    $integration = new ProfessionalIntegration([
        'professional_id' => $brandId,
        'provider' => 'shopify',
        'external_account_id' => 'resyncbrand.myshopify.com',
        'access_token' => 'shpat_test_token',
        'provider_metadata' => $metadata,
    ]);
    $integration->id = 'int-resync-1';
    $integration->save();

    $brand = Professional::find($brandId);
    $brandProfile = BrandProfile::where('professional_id', $brandId)->first();

    return [$brand, $brandProfile, $integration];
}

function makeResyncRequest(string $type = 'brand', ?Professional $pro = null): Request
{
    if ($pro === null) {
        $pro = new Professional([
            'professional_type' => $type,
            'status' => 'active',
        ]);
        $pro->id = 'brand-resync-1';
    }

    $request = Request::create('/api/store/shopify/resync', 'POST');
    $request->attributes->set('professional', $pro);

    return $request;
}

// ---------- ShopProfileAutoFillService::resyncFromShopData unit tests ----------
// Option B: Shopify wins when it has a value; local is preserved only when
// Shopify returns empty. No "was this manually edited" check — local DB is
// treated as a cache, Shopify is the source of truth.

it('overwrites all Shopify-sourced fields when Shopify has values', function () {
    [$brand, $brandProfile, $integration] = createResyncBrand();

    $service = app(ShopProfileAutoFillService::class);
    $result = $service->resyncFromShopData($integration, resyncFakeShopPayload());

    expect($result['updated'])->toContain(
        'display_name',
        'primary_email',
        'phone',
        'location_street_address',
        'location_city',
        'location_state',
        'location_postcode',
        'location_country',
        'country_code',
        'timezone',
        'business_website',
        'shop_currency',
    );
    expect($result['preserved'])->toBeEmpty();

    $brand->refresh();
    expect($brand->display_name)->toBe('Fresh Brand Name');
    expect($brand->primary_email)->toBe('fresh@shop.example');
    expect($brand->phone)->toBe('+61400000000');
    expect($brand->location_city)->toBe('Sydney');
    expect($brand->country_code)->toBe('AU');

    $brandProfile->refresh();
    expect($brandProfile->business_website)->toBe('freshbrand.myshopify.com');

    $integration->refresh();
    expect($integration->provider_metadata['shop_currency'])->toBe('AUD');
});

it('overwrites a manually edited field — local edits do not block Shopify resync', function () {
    // Core Option B behavior: local value differs from what Shopify will
    // return, and resync still overwrites. This is the whole point of the change.
    [$brand, , $integration] = createResyncBrand([
        'display_name' => 'User Edited Name',
        'phone' => '+61499999999',
    ]);

    $service = app(ShopProfileAutoFillService::class);
    $result = $service->resyncFromShopData($integration, resyncFakeShopPayload());

    expect($result['updated'])->toContain('display_name', 'phone');
    expect($result['preserved'])->not->toContain('display_name');
    expect($result['preserved'])->not->toContain('phone');

    $brand->refresh();
    expect($brand->display_name)->toBe('Fresh Brand Name');
    expect($brand->phone)->toBe('+61400000000');
});

it('preserves a field when Shopify returns empty string', function () {
    // Shopify has no phone set. Local phone must survive.
    [$brand, , $integration] = createResyncBrand();

    $service = app(ShopProfileAutoFillService::class);
    $result = $service->resyncFromShopData($integration, resyncFakeShopPayload(['phone' => '']));

    expect($result['preserved'])->toContain('phone');
    expect($result['updated'])->not->toContain('phone');

    $brand->refresh();
    expect($brand->phone)->toBe('+61411111111');
});

it('preserves a field when Shopify omits it entirely (missing key)', function () {
    [$brand, , $integration] = createResyncBrand();

    // Remove `city` from the payload entirely — not just empty-string.
    $payload = resyncFakeShopPayload();
    unset($payload['city']);

    $service = app(ShopProfileAutoFillService::class);
    $result = $service->resyncFromShopData($integration, $payload);

    expect($result['preserved'])->toContain('location_city');

    $brand->refresh();
    expect($brand->location_city)->toBe('Melbourne');
});

it('fills a previously empty DB field from Shopify', function () {
    [$brand, , $integration] = createResyncBrand(['phone' => null]);

    $service = app(ShopProfileAutoFillService::class);
    $result = $service->resyncFromShopData($integration, resyncFakeShopPayload());

    expect($result['updated'])->toContain('phone');

    $brand->refresh();
    expect($brand->phone)->toBe('+61400000000');
});

it('preserves shop_currency when Shopify returns empty currency', function () {
    // shop_currency lives in provider_metadata, not a DB column. Same rule applies.
    [, , $integration] = createResyncBrand();

    $service = app(ShopProfileAutoFillService::class);
    $result = $service->resyncFromShopData($integration, resyncFakeShopPayload(['currency' => '']));

    expect($result['preserved'])->toContain('shop_currency');

    $integration->refresh();
    expect($integration->provider_metadata['shop_currency'])->toBe('USD');
});

it('normalizes currency and country_code to uppercase', function () {
    [$brand, , $integration] = createResyncBrand();

    $service = app(ShopProfileAutoFillService::class);
    $service->resyncFromShopData($integration, resyncFakeShopPayload([
        'currency' => 'aud',
        'country_code' => 'au',
    ]));

    $brand->refresh();
    expect($brand->country_code)->toBe('AU');

    $integration->refresh();
    expect($integration->provider_metadata['shop_currency'])->toBe('AUD');
});

// ---------- shopify_sync_locked_fields tests ----------

it('preserves a locked field when brand has opted out of Shopify overwrite for it', function () {
    [$brand, , $integration] = createResyncBrand(
        ['display_name' => 'My Custom Name'],
        [],
        ['shopify_sync_locked_fields' => ['display_name']],
    );

    $service = app(ShopProfileAutoFillService::class);
    $result = $service->resyncFromShopData($integration, resyncFakeShopPayload());

    expect($result['preserved'])->toContain('display_name');
    expect($result['updated'])->not->toContain('display_name');

    $brand->refresh();
    expect($brand->display_name)->toBe('My Custom Name');
});

it('syncs non-locked fields while preserving locked ones', function () {
    [$brand, , $integration] = createResyncBrand(
        ['display_name' => 'My Custom Name'],
        [],
        ['shopify_sync_locked_fields' => ['display_name']],
    );

    $service = app(ShopProfileAutoFillService::class);
    $result = $service->resyncFromShopData($integration, resyncFakeShopPayload());

    expect($result['preserved'])->toContain('display_name');
    expect($result['updated'])->toContain('phone', 'location_city');

    $brand->refresh();
    expect($brand->display_name)->toBe('My Custom Name');
    expect($brand->phone)->toBe('+61400000000');
    expect($brand->location_city)->toBe('Sydney');
});

it('preserves multiple locked fields across professional and brand_profile targets', function () {
    [$brand, $brandProfile, $integration] = createResyncBrand(
        ['display_name' => 'My Custom Name', 'phone' => '+61422222222'],
        ['business_website' => 'my-custom-site.com'],
        ['shopify_sync_locked_fields' => ['display_name', 'phone', 'business_website']],
    );

    $service = app(ShopProfileAutoFillService::class);
    $result = $service->resyncFromShopData($integration, resyncFakeShopPayload());

    expect($result['preserved'])->toContain('display_name', 'phone', 'business_website');
    expect($result['updated'])->not->toContain('display_name', 'phone', 'business_website');

    $brand->refresh();
    expect($brand->display_name)->toBe('My Custom Name');
    expect($brand->phone)->toBe('+61422222222');

    $brandProfile->refresh();
    expect($brandProfile->business_website)->toBe('my-custom-site.com');
});

// ---------- ShopifyDataResyncService integration tests ----------

it('dispatches the unified brand-design job and records last_resynced_at', function () {
    Bus::fake([SyncShopifyBrandDesignJob::class]);

    Http::fake([
        '*/admin/api/*/shop.json' => Http::response(['shop' => resyncFakeShopPayload()], 200),
    ]);

    [, , $integration] = createResyncBrand();

    $service = app(ShopifyDataResyncService::class);
    $result = $service->resync($integration);

    Bus::assertDispatched(SyncShopifyBrandDesignJob::class);

    expect($result['jobs_dispatched'])->toBe(['brand_design']);
    expect($result['last_resynced_at'])->toBeString();

    $integration->refresh();
    expect($integration->provider_metadata['last_resynced_at'])->toBe($result['last_resynced_at']);
});

it('throws when Shopify API call fails so DB is never partially written', function () {
    Bus::fake([SyncShopifyBrandDesignJob::class]);
    Http::fake([
        '*/admin/api/*/shop.json' => Http::response('boom', 503),
    ]);

    [$brand, , $integration] = createResyncBrand();

    $service = app(ShopifyDataResyncService::class);
    expect(fn () => $service->resync($integration))->toThrow(RuntimeException::class);

    Bus::assertNotDispatched(SyncShopifyBrandDesignJob::class);

    $brand->refresh();
    expect($brand->display_name)->toBe('Original Name');
});

it('preserves sibling provider_metadata keys across a resync (concurrency regression)', function () {
    Bus::fake([SyncShopifyBrandDesignJob::class]);
    Http::fake([
        '*/admin/api/*/shop.json' => Http::response(['shop' => resyncFakeShopPayload()], 200),
    ]);

    [, , $integration] = createResyncBrand();

    // Sibling keys written by parallel onboarding jobs — must survive the resync.
    // (webhook_registration_state is no longer a JSONB key post-DATA-2; metafield
    // definitions state stays the canonical example of a per-step JSONB flag.)
    $integration->refresh();
    $meta = $integration->provider_metadata;
    $meta['storefront_access_token'] = 'tok_existing';
    $meta['metafield_definitions_state'] = 'registered';
    $meta['publication_id'] = '999';
    $integration->provider_metadata = $meta;
    $integration->save();

    $service = app(ShopifyDataResyncService::class);
    $result = $service->resync($integration);

    $integration->refresh();
    $fresh = $integration->provider_metadata;

    expect($fresh['storefront_access_token'])->toBe('tok_existing');
    expect($fresh['metafield_definitions_state'])->toBe('registered');
    expect($fresh['publication_id'])->toBe('999');

    expect($fresh['last_resynced_at'])->toBe($result['last_resynced_at']);
    expect($fresh['shop_currency'])->toBe('AUD');
});

it('rolls back multi-model writes when a post-API save fails', function () {
    Bus::fake([SyncShopifyBrandDesignJob::class]);
    Http::fake([
        '*/admin/api/*/shop.json' => Http::response(['shop' => resyncFakeShopPayload()], 200),
    ]);

    [$brand, , $integration] = createResyncBrand();

    // Fake autofill that mutates the Professional and then throws mid-transaction.
    // The DB::transaction in ShopifyDataResyncService should roll the mutation back.
    app()->bind(ShopProfileAutoFillService::class, function () use ($brand) {
        return new class($brand->id) extends ShopProfileAutoFillService
        {
            public function __construct(private string $brandId) {}

            public function resyncFromShopData(ProfessionalIntegration $integration, array $shopData): array
            {
                $pro = Professional::find($this->brandId);
                $pro->display_name = 'Half-Written Name';
                $pro->save();

                throw new RuntimeException('simulated mid-resync failure');
            }
        };
    });

    $service = app(ShopifyDataResyncService::class);
    expect(fn () => $service->resync($integration))->toThrow(RuntimeException::class);

    $brand->refresh();
    expect($brand->display_name)->toBe('Original Name');

    Bus::assertNotDispatched(SyncShopifyBrandDesignJob::class);
});

// ---------- ShopifyResyncController HTTP-layer tests ----------

// Non-brand access is now rejected by the `brand.only` middleware (EnsureBrandAccount)
// before the controller is reached — see tests/Unit/Middleware/EnsureBrandAccountTest.php.

it('returns 404 when the brand has no Shopify integration', function () {
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => 'brand-no-integration',
        'handle' => 'noint',
        'handle_lc' => 'noint',
        'display_name' => 'No Integration',
        'professional_type' => 'brand',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $pro = new Professional([
        'professional_type' => 'brand',
        'status' => 'active',
    ]);
    $pro->id = 'brand-no-integration';

    $controller = app(ShopifyResyncController::class);
    $response = $controller(makeResyncRequest('brand', $pro));

    expect($response->status())->toBe(404);
});

it('rate limits to 1 request per 60 seconds per integration', function () {
    Bus::fake();
    Http::fake([
        '*/admin/api/*/shop.json' => Http::response(['shop' => resyncFakeShopPayload()], 200),
    ]);

    [, , $integration] = createResyncBrand();

    $controller = app(ShopifyResyncController::class);

    $first = $controller(makeResyncRequest());
    expect($first->status())->toBe(200);

    $second = $controller(makeResyncRequest());
    expect($second->status())->toBe(429);
    expect($second->headers->get('Retry-After'))->not->toBeNull();
});

it('returns 502 when Shopify API fails', function () {
    Http::fake([
        '*/admin/api/*/shop.json' => Http::response('boom', 503),
    ]);

    [, , $integration] = createResyncBrand();

    $controller = app(ShopifyResyncController::class);
    $response = $controller(makeResyncRequest());

    expect($response->status())->toBe(502);
});

it('returns 409 when the integration has an empty access_token', function () {
    $brandId = 'brand-no-token';
    $now = now()->toDateTimeString();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $brandId,
        'handle' => 'notoken',
        'handle_lc' => 'notoken',
        'display_name' => 'No Token',
        'professional_type' => 'brand',
        'status' => 'active',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    // Empty token must go through the model so the encrypted cast wraps it.
    // The controller's 409 guard decrypts and checks for ''.
    $integration = new ProfessionalIntegration([
        'professional_id' => $brandId,
        'provider' => 'shopify',
        'external_account_id' => 'notoken.myshopify.com',
        'access_token' => '',
        'provider_metadata' => ['shop_domain' => 'notoken.myshopify.com'],
    ]);
    $integration->id = 'int-no-token';
    $integration->save();

    $pro = new Professional(['professional_type' => 'brand', 'status' => 'active']);
    $pro->id = $brandId;

    $controller = app(ShopifyResyncController::class);
    $response = $controller(makeResyncRequest('brand', $pro));

    expect($response->status())->toBe(409);

    $body = json_encode($response->getData(true));
    expect($body)->not->toContain('access_token');
    expect($body)->not->toContain('shpat_');
});

it('does not leak access tokens or shop domain in the response', function () {
    Bus::fake();
    Http::fake([
        '*/admin/api/*/shop.json' => Http::response(['shop' => resyncFakeShopPayload()], 200),
    ]);

    [, , $integration] = createResyncBrand();

    $controller = app(ShopifyResyncController::class);
    $response = $controller(makeResyncRequest());

    $body = $response->getData(true);
    $json = json_encode($body);

    expect($body)->toHaveKeys(['fields_updated', 'fields_preserved', 'jobs_dispatched', 'last_resynced_at']);
    expect($json)->not->toContain('shpat_test_token');
    expect($json)->not->toContain('resyncbrand.myshopify.com');
});
