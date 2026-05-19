<?php

// §28.17 TEST-1 — HMAC sign-fail coverage for the secondary Shopify webhook
// controllers that didn't previously have a "bad HMAC returns 401" test.
// Covers: orders/edited, orders/cancelled, refunds/create, themes/publish,
// and the three GDPR webhooks. The primary controllers (orders, orders/updated,
// shop/update, app/uninstalled) already had this coverage.
//
// Why one bundled file rather than 7 separate ones: each controller routes
// through the same HandlesShopifyWebhook trait + ValidatesShopifyWebhookHmac
// concern, so the bad-HMAC behavior is structurally identical. One sweep
// catches a refactor regression on any of them.

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;

beforeEach(function () {
    Bus::fake();
    Cache::flush();
    setupProfessionalIntegrationsTable();
    setupWebhookEventsTable();
    Config::set('services.shopify.webhook_secret', 'test-shop-secret');
});

dataset('secondary_shopify_webhooks', [
    'orders/edited' => ['POST', '/api/webhooks/shopify/orders-edited'],
    'orders/cancelled' => ['POST', '/api/webhooks/shopify/orders-cancelled'],
    'refunds/create' => ['POST', '/api/webhooks/shopify/refunds-create'],
    'themes/publish' => ['POST', '/api/webhooks/shopify/themes-publish'],
    'gdpr/customers-data-request' => ['POST', '/api/webhooks/shopify/gdpr/customers-data-request'],
    'gdpr/customers-redact' => ['POST', '/api/webhooks/shopify/gdpr/customers-redact'],
    'gdpr/shop-redact' => ['POST', '/api/webhooks/shopify/gdpr/shop-redact'],
]);

it('secondary webhook returns 401 on bad HMAC and dispatches nothing', function (string $method, string $path) {
    $response = $this->json($method, $path, ['id' => 1, 'placeholder' => true], [
        'X-Shopify-Hmac-SHA256' => 'bogus-not-a-real-signature',
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(401);
    Bus::assertNothingDispatched();
})->with('secondary_shopify_webhooks');

it('secondary webhook returns 401 on missing HMAC header', function (string $method, string $path) {
    $response = $this->json($method, $path, ['id' => 1], [
        'X-Shopify-Shop-Domain' => 'brand-a.myshopify.com',
        'X-Shopify-Webhook-Id' => (string) Str::uuid(),
    ]);

    $response->assertStatus(401);
    Bus::assertNothingDispatched();
})->with('secondary_shopify_webhooks');
