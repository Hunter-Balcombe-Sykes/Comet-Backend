<?php

// TEST-5 — PublicShopifyStorefrontController coverage.
//
// GET /api/public/shopify/storefront-config
//
// Covers:
//   - shop_domain resolution (primary lookup strategy)
//   - brand_slug resolution (secondary / backward-compat strategy)
//   - 202 "pending" response when storefront token is absent + dispatch dedup
//   - 404 when no integration matches
//   - 422 when neither shop_domain nor brand_slug is supplied

use App\Jobs\Shopify\CreateStorefrontAccessTokenJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    setupSitesTable();
    setupProfessionalIntegrationsTable();
    setupBrandProfilesTable();
    Bus::fake();
    Cache::flush();
    Config::set('partna.throttle.enabled', false);
});

// ── Helpers ──────────────────────────────────────────────────────────────────

function seedStorefrontIntegration(string $shopDomain, ?string $storefrontToken, string $brandSlug = 'acme'): array
{
    $proId = (string) Str::uuid();
    $siteId = (string) Str::uuid();

    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => $brandSlug,
        'handle_lc' => strtolower($brandSlug),
        'display_name' => 'Acme Brand',
        'professional_type' => 'brand',
        'account_type' => 'brand',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'professional_id' => $proId,
        'subdomain' => $brandSlug,
        'settings' => json_encode([]),
        'is_published' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Insert via raw DB with pre-encrypted values so shopify_shop_domain (not in
    // $fillable) can be set and the encrypted casts are honoured.
    $integrationId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => $integrationId,
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => $shopDomain,
        // The model uses 'encrypted' cast which calls Crypt::encryptString (no serialization).
        // Raw DB inserts must use the same method to ensure the cast can decrypt on read.
        'access_token' => Crypt::encryptString('shpat_test_token'),
        'storefront_token' => $storefrontToken !== null ? Crypt::encryptString($storefrontToken) : null,
        'provider_metadata' => json_encode([
            'shop_domain' => $shopDomain,
            'default_collection_handle' => 'partna-default-products',
        ]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return compact('proId', 'siteId', 'integrationId');
}

// ── shop_domain resolution (primary strategy) ─────────────────────────────────

it('resolves by shop_domain and returns storefront config', function () {
    seedStorefrontIntegration('acme.myshopify.com', 'sfat_test_storefront_token');

    $res = $this->getJson('/api/public/shopify/storefront-config?shop_domain=acme.myshopify.com')
        ->assertOk();

    expect($res->json('shop_domain'))->toBe('acme.myshopify.com');
    expect($res->json('storefront_access_token'))->toBe('sfat_test_storefront_token');
    expect($res->json('default_collection_handle'))->toBe('partna-default-products');
});

it('normalizes shop_domain (strips https:// prefix) before lookup', function () {
    seedStorefrontIntegration('acme.myshopify.com', 'sfat_normalised');

    // Controller uses NormalizesShopDomain — accepts https://acme.myshopify.com
    $this->getJson('/api/public/shopify/storefront-config?shop_domain=https://acme.myshopify.com')
        ->assertOk()
        ->assertJsonFragment(['storefront_access_token' => 'sfat_normalised']);
});

// ── brand_slug resolution (secondary strategy) ────────────────────────────────

it('resolves by brand_slug when shop_domain is absent', function () {
    seedStorefrontIntegration('slug-brand.myshopify.com', 'sfat_slug_token', 'slug-brand');

    $res = $this->getJson('/api/public/shopify/storefront-config?brand_slug=slug-brand')
        ->assertOk();

    expect($res->json('storefront_access_token'))->toBe('sfat_slug_token');
});

it('brand_slug lookup is case-insensitive', function () {
    seedStorefrontIntegration('casebrand.myshopify.com', 'sfat_case_token', 'casebrand');

    $this->getJson('/api/public/shopify/storefront-config?brand_slug=CaseBrand')
        ->assertOk()
        ->assertJsonFragment(['storefront_access_token' => 'sfat_case_token']);
});

// ── 202 pending + dedup behaviour ────────────────────────────────────────────

it('returns 202 and dispatches token-creation job when storefront token is missing', function () {
    $ids = seedStorefrontIntegration('pending-brand.myshopify.com', null, 'pending-brand');

    $this->getJson('/api/public/shopify/storefront-config?shop_domain=pending-brand.myshopify.com')
        ->assertStatus(202)
        ->assertJsonFragment(['status' => 'pending']);

    Bus::assertDispatched(CreateStorefrontAccessTokenJob::class);
});

it('does not dispatch a second job when dedup cache key is already set', function () {
    $ids = seedStorefrontIntegration('dedup-brand.myshopify.com', null, 'dedup-brand');

    // First call — dispatches job and sets cache key.
    $this->getJson('/api/public/shopify/storefront-config?shop_domain=dedup-brand.myshopify.com')
        ->assertStatus(202);

    Bus::assertDispatched(CreateStorefrontAccessTokenJob::class, 1);

    // Second call — cache key present; job must NOT be dispatched again.
    $this->getJson('/api/public/shopify/storefront-config?shop_domain=dedup-brand.myshopify.com')
        ->assertStatus(202);

    Bus::assertDispatched(CreateStorefrontAccessTokenJob::class, 1);
});

// ── 404 cases ─────────────────────────────────────────────────────────────────

it('returns 404 when shop_domain does not match any integration', function () {
    $this->getJson('/api/public/shopify/storefront-config?shop_domain=notfound.myshopify.com')
        ->assertNotFound();
});

it('returns 404 when brand_slug does not match any site', function () {
    $this->getJson('/api/public/shopify/storefront-config?brand_slug=ghost-brand')
        ->assertNotFound();
});

it('returns 404 when integration has no access_token (disconnected)', function () {
    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'disconnected',
        'handle_lc' => 'disconnected',
        'display_name' => 'Disconnected',
        'professional_type' => 'brand',
        'account_type' => 'brand',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    // Integration with NULL access_token — should not be returned by the query
    // (controller uses `whereNotNull('access_token')`). Insert via raw DB so we
    // can store a literal NULL without the encrypted cast wrapping it.
    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => 'shopify',
        'shopify_shop_domain' => 'disconnected.myshopify.com',
        'access_token' => null,
        'storefront_token' => null,
        'provider_metadata' => json_encode(['shop_domain' => 'disconnected.myshopify.com']),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $this->getJson('/api/public/shopify/storefront-config?shop_domain=disconnected.myshopify.com')
        ->assertNotFound();
});

it('returns 404 when the integration has an empty shop_domain in provider_metadata', function () {
    // Integration is found by the shopify_shop_domain column, but provider_metadata
    // carries no usable shop_domain — the controller's $shopDomain === '' guard 404s.
    $proId = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $proId,
        'handle' => 'empty-meta',
        'handle_lc' => 'empty-meta',
        'display_name' => 'Empty Meta',
        'professional_type' => 'brand',
        'account_type' => 'brand',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => 'shopify',
        'shopify_shop_domain' => 'empty-meta.myshopify.com',
        'access_token' => Crypt::encryptString('shpat_test_token'),
        'storefront_token' => Crypt::encryptString('sfat_token'),
        'provider_metadata' => json_encode([]),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $this->getJson('/api/public/shopify/storefront-config?shop_domain=empty-meta.myshopify.com')
        ->assertNotFound();
});

// ── populated BrandProfile branch ─────────────────────────────────────────────

it('returns brand_status and business_website from the BrandProfile when one exists', function () {
    $ids = seedStorefrontIntegration('branded.myshopify.com', 'sfat_branded', 'branded');

    DB::connection('pgsql')->table('brand.brand_profiles')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $ids['proId'],
        'brand_status' => 'ready_for_affiliates',
        'business_website' => 'https://branded.example.com',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $res = $this->getJson('/api/public/shopify/storefront-config?shop_domain=branded.myshopify.com')
        ->assertOk();

    expect($res->json('brand_status'))->toBe('ready_for_affiliates');
    expect($res->json('business_website'))->toBe('https://branded.example.com');
});

// ── 422 validation ────────────────────────────────────────────────────────────

it('returns 422 when neither shop_domain nor brand_slug is supplied', function () {
    $this->getJson('/api/public/shopify/storefront-config')
        ->assertUnprocessable();
});
