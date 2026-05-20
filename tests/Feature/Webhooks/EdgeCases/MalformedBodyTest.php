<?php

use App\Jobs\Shopify\ProcessShopifyOrderWebhookJob;
use App\Jobs\Square\SyncSquareCatalogDeltaJob;
use App\Models\Core\Professional\ProfessionalIntegration;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    Bus::fake();
    Cache::flush();
    setupProfessionalIntegrationsTable();

    Config::set('services.shopify.webhook_secret', 'shop-secret');
    Config::set('services.square.webhook_signature_key', 'square-key');
    Config::set('services.square.webhook_notification_url', 'http://localhost/api/webhooks/square');
    Config::set('partna.features.square_sync', true);
});

it('shopify orders/paid — empty body with valid HMAC returns 422 so Shopify retries', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_token',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $body = '';
    $sig = signShopifyBody($body, 'shop-secret');

    $this->call('POST', '/api/webhooks/shopify/orders-paid', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_HMAC_SHA256' => $sig,
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'brand-a.myshopify.com',
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh-empty-1',
    ], $body)->assertStatus(422);

    Bus::assertNotDispatched(ProcessShopifyOrderWebhookJob::class);
});

it('shopify orders/paid — malformed JSON with valid HMAC returns 422 so Shopify retries', function () {
    $proId = (string) Str::uuid();
    DB::table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $proId,
        'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
        'shopify_shop_domain' => 'brand-a.myshopify.com',
        'access_token' => 'shpat_token',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $body = '{not valid json';
    $sig = signShopifyBody($body, 'shop-secret');

    $this->call('POST', '/api/webhooks/shopify/orders-paid', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_HMAC_SHA256' => $sig,
        'HTTP_X_SHOPIFY_SHOP_DOMAIN' => 'brand-a.myshopify.com',
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'wh-malformed-1',
    ], $body)->assertStatus(422);

    Bus::assertNotDispatched(ProcessShopifyOrderWebhookJob::class);
});

it('square — payload that is not an array is gracefully ignored', function () {
    $body = '"plain string"';  // valid JSON, but not an object
    $sig = signSquareBody('http://localhost/api/webhooks/square', $body, 'square-key');

    $this->call('POST', '/api/webhooks/square', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SQUARE_HMACSHA256_SIGNATURE' => $sig,
    ], $body)->assertOk();

    Bus::assertNotDispatched(SyncSquareCatalogDeltaJob::class);
});
