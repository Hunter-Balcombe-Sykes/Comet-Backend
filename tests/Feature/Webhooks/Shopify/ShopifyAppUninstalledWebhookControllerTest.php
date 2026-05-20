<?php

use App\Enums\BrandStatus;
use App\Jobs\Shopify\PurgeAffiliateProductSelectionsJob;
use App\Models\Commerce\AffiliateProductSelection;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
    setupProfessionalIntegrationsTable();
    setupAffiliateProductSelectionsTable();
    setupBrandStoreSettingsTable();
    setupBrandProfilesTable();
    Config::set('services.shopify.webhook_secret', 'test-shop-secret');
});

/**
 * Seed a brand_profiles row for the given professional. Without this, the
 * controller's BrandProfile::update() silently no-ops on 0 rows, masking
 * regressions on the authoritative brand_status transition.
 */
function seedBrandProfile(string $proId, string $status = 'shopify_linked'): void
{
    DB::table('brand.brand_profiles')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'brand_status' => $status,
        'setup_complete' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function uninstalledPayload(): array
{
    return [
        'id' => 12345678,
        'name' => 'Brand A',
        'myshopify_domain' => 'brand-a.myshopify.com',
        'domain' => 'brand-a.myshopify.com',
    ];
}

it('app/uninstalled — bad HMAC returns 401 and leaves integration intact', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_alive',
        'provider_metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->postJson('/api/webhooks/shopify/app-uninstalled', uninstalledPayload(), [
        'X-Shopify-Hmac-SHA256' => 'bad',
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
    ])->assertStatus(401);

    $row = DB::table('core.professional_integrations')->where('professional_id', $proId)->first();
    expect($row->access_token)->toBe('shpat_alive');
});

it('app/uninstalled — valid HMAC clears access_token, transitions brand to disconnected, and marks disconnected_reason', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_alive',
        'refresh_token' => 'rt_alive',
        'provider_metadata' => json_encode(['some_existing' => 'value']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    seedBrandProfile($proId, 'shopify_configured');

    $payload = uninstalledPayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/app-uninstalled', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
    ])->assertOk();

    $row = DB::table('core.professional_integrations')->where('professional_id', $proId)->first();
    expect($row->access_token)->toBeNull();
    expect($row->refresh_token)->toBeNull();

    $meta = json_decode($row->provider_metadata, true);
    expect($meta['disconnected_reason'])->toBe('app_uninstalled');
    expect($meta['some_existing'])->toBe('value');  // Pre-existing keys preserved.

    // Post-DATA-2: state lives on dedicated columns, not in JSONB.
    expect($row->disconnected_at)->not->toBeNull();
    expect($row->webhook_registration_state)->toBe('uninstalled');
    // The JSONB drawer should no longer carry the duplicated state keys.
    expect($meta)->not->toHaveKey('disconnected_at');
    expect($meta)->not->toHaveKey('webhook_registration_state');
    expect($meta)->not->toHaveKey('webhooks_state');

    // TEST-6: the authoritative state-machine write must actually happen — the
    // BrandProfile::update path was previously untested because no brand_profiles
    // row was seeded, so update(0 rows) was indistinguishable from a correct write.
    $brandRow = DB::table('brand.brand_profiles')->where('professional_id', $proId)->first();
    expect($brandRow->brand_status)->toBe(BrandStatus::Disconnected->value);
    expect((int) $brandRow->setup_complete)->toBe(0);
});

it('app/uninstalled — duplicate delivery (same X-Shopify-Webhook-Id) is rejected by the cache dedup gate', function () {
    // Bus::fake here only proves the job is not re-dispatched as a regression check;
    // PurgeAffiliateProductSelectionsJob is ShouldBeUnique so the queue would dedup
    // the job anyway — the controller dedup is what stops the rest of the mutation
    // path (provider_metadata overwrite, brand_profile update, Log::info).
    Bus::fake([PurgeAffiliateProductSelectionsJob::class]);

    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_alive',
        'provider_metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    seedBrandProfile($proId);

    $payload = uninstalledPayload();
    $body = json_encode($payload);
    $headers = [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => 'wh_duplicate_test_001',
    ];

    // First delivery: processed. Asserting absence of duplicate key on the
    // response — toMissing matches the cache-dedup pattern in HandlesShopifyWebhook.
    // First delivery: processed. Asserting absence of duplicate key on the
    // response — toMissing matches the cache-dedup pattern in HandlesShopifyWebhook.
    $this->postJson('/api/webhooks/shopify/app-uninstalled', $payload, $headers)
        ->assertOk()
        ->assertJsonMissing(['duplicate' => true]);

    // Second delivery: cache claim fails on Cache::add → controller short-circuits
    // with duplicate=true marker (SEC-2 / LIFE-2). Without this gate, the second
    // delivery re-runs the full mutation path and Log::info fires twice.
    $this->postJson('/api/webhooks/shopify/app-uninstalled', $payload, $headers)
        ->assertOk()
        ->assertExactJson(['received' => true, 'duplicate' => true]);

    Bus::assertDispatchedTimes(PurgeAffiliateProductSelectionsJob::class, 1);
});

it('app/uninstalled — second delivery after cache TTL expiry is still a no-op via disconnected_at guard', function () {
    Bus::fake([PurgeAffiliateProductSelectionsJob::class]);

    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => null,  // First delivery already cleared token.
        // Post-DATA-2: disconnected_at is a column; reason label still in JSONB.
        'disconnected_at' => '2026-05-14T00:00:00+00:00',
        'provider_metadata' => json_encode([
            'disconnected_reason' => 'app_uninstalled',
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    seedBrandProfile($proId, BrandStatus::Disconnected->value);

    $payload = uninstalledPayload();
    $body = json_encode($payload);

    // No X-Shopify-Webhook-Id → cache dedup does not apply. The secondary guard
    // (already-disconnected state) must short-circuit the handler.
    $this->postJson('/api/webhooks/shopify/app-uninstalled', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
    ])->assertOk();

    // disconnected_at unchanged — the no-op path returns without overwriting state.
    $row = DB::table('core.professional_integrations')->where('professional_id', $proId)->first();
    expect($row->disconnected_at)->toBe('2026-05-14T00:00:00+00:00');

    Bus::assertNotDispatched(PurgeAffiliateProductSelectionsJob::class);
});

it('app/uninstalled — dispatches PurgeAffiliateProductSelectionsJob (Master Pattern 16)', function () {
    Bus::fake([PurgeAffiliateProductSelectionsJob::class]);

    $brandId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $brandId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_alive',
        'provider_metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('commerce.affiliate_product_selections')->insert([
        ['id' => (string) Str::uuid(), 'brand_professional_id' => $brandId, 'shopify_product_gid' => 'gid://shopify/Product/1', 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'brand_professional_id' => $brandId, 'shopify_product_gid' => 'gid://shopify/Product/2', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $payload = uninstalledPayload();
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/app-uninstalled', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
    ])->assertOk();

    // Webhook hands off to the queue — rows are NOT deleted synchronously now.
    // Master Pattern 16 (DB-F#SCALE-3) avoids holding row locks across a large
    // delete inside the Shopify ack window.
    expect(AffiliateProductSelection::query()
        ->where('brand_professional_id', $brandId)
        ->count())->toBe(2);

    Bus::assertDispatched(
        PurgeAffiliateProductSelectionsJob::class,
        fn (PurgeAffiliateProductSelectionsJob $job) => $job->brandProfessionalId === $brandId
            && $job->queue === 'integrations',
    );
});

it('PurgeAffiliateProductSelectionsJob deletes all selections for the given brand', function () {
    $brandId = (string) Str::uuid();
    $otherBrandId = (string) Str::uuid();

    DB::table('commerce.affiliate_product_selections')->insert([
        ['id' => (string) Str::uuid(), 'brand_professional_id' => $brandId, 'shopify_product_gid' => 'gid://shopify/Product/1', 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'brand_professional_id' => $brandId, 'shopify_product_gid' => 'gid://shopify/Product/2', 'created_at' => now(), 'updated_at' => now()],
        ['id' => (string) Str::uuid(), 'brand_professional_id' => $otherBrandId, 'shopify_product_gid' => 'gid://shopify/Product/3', 'created_at' => now(), 'updated_at' => now()],
    ]);

    (new PurgeAffiliateProductSelectionsJob($brandId))->handle();

    // Target brand's selections gone; other brands untouched.
    expect(AffiliateProductSelection::query()->where('brand_professional_id', $brandId)->count())->toBe(0);
    expect(AffiliateProductSelection::query()->where('brand_professional_id', $otherBrandId)->count())->toBe(1);
});

it('app/uninstalled — releases cache slot when transaction throws so Shopify retry can proceed', function () {
    // Without releasing the cache key on failure, a thrown exception in the
    // mutation path would leave the slot claimed for the TTL window (24h
    // default), silently swallowing every subsequent Shopify retry of the
    // same webhook delivery. Mirrors the HandlesShopifyWebhook trait's
    // try/catch + Cache::forget pattern.
    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_alive',
        'provider_metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    seedBrandProfile($proId);

    $webhookId = 'wh_release_test_'.Str::random(8);
    $cacheKey = 'shopify:webhook:app-uninstalled:'.$webhookId;
    expect(Cache::has($cacheKey))->toBeFalse();

    DB::shouldReceive('transaction')->once()->andThrow(new \RuntimeException('simulated db failure'));

    $payload = uninstalledPayload();
    $body = json_encode($payload);
    $headers = [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => $webhookId,
    ];

    $this->withoutExceptionHandling();
    expect(fn () => $this->postJson('/api/webhooks/shopify/app-uninstalled', $payload, $headers))
        ->toThrow(\RuntimeException::class);

    expect(Cache::has($cacheKey))->toBeFalse();
});

it('app/uninstalled — unknown shop_domain returns 200 without side effects', function () {
    // Body domain must match the header — SHOP-1 cross-check rejects a mismatch
    // with 400 before the unknown-shop lookup is even reached.
    $payload = ['id' => 999, 'name' => 'Ghost', 'myshopify_domain' => 'ghost.myshopify.com', 'domain' => 'ghost.myshopify.com'];
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/app-uninstalled', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'ghost.myshopify.com',
    ])->assertOk();
});

it('app/uninstalled — SHOP-1: body signed for shop A with header for shop B is rejected 400', function () {
    // Brand B owns a connected Shopify store and a valid uninstall webhook for it.
    $brandB = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $brandB,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-b.myshopify.com',
        'access_token' => 'shpat_b_alive',
        'provider_metadata' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    seedBrandProfile($brandB);

    // Attacker holds a webhook validly signed for their own store (brand-a) and
    // swaps the unsigned header to point at brand-b.
    $payload = uninstalledPayload(); // myshopify_domain = brand-a.myshopify.com
    $body = json_encode($payload);

    $this->postJson('/api/webhooks/shopify/app-uninstalled', $payload, [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test-shop-secret'),
        'X-Shopify-Shop-Domain' => 'brand-b.myshopify.com',
    ])->assertStatus(400);

    // Brand B's integration is untouched.
    $row = DB::table('core.professional_integrations')->where('professional_id', $brandB)->first();
    expect($row->access_token)->toBe('shpat_b_alive');
    expect($row->disconnected_at)->toBeNull();
});
