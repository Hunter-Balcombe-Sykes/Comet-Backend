<?php

use App\Jobs\Shopify\Gdpr\ExportCustomerDataJob;
use App\Jobs\Shopify\Gdpr\RedactCustomerJob;
use App\Jobs\Shopify\Gdpr\RedactShopJob;
use App\Models\Core\Gdpr\GdprRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Bus::fake();
    Config::set('services.shopify.webhook_secret', 'test_shared_secret');

    $conn = DB::connection('pgsql');
    try {
        $conn->statement('ATTACH DATABASE \':memory:\' AS core');
    } catch (\Throwable) {
    }

    $conn->statement('CREATE TABLE IF NOT EXISTS core.gdpr_requests (
        id TEXT PRIMARY KEY,
        topic TEXT NOT NULL,
        shop_domain TEXT NOT NULL,
        shopify_shop_id INTEGER,
        payload_hash TEXT NOT NULL,
        payload TEXT NOT NULL,
        professional_id TEXT,
        status TEXT NOT NULL DEFAULT \'received\',
        error TEXT,
        received_at TEXT,
        completed_at TEXT,
        created_at TEXT,
        updated_at TEXT
    )');

    // SQLite: schema prefix belongs on index name, not table name
    $conn->statement('CREATE UNIQUE INDEX IF NOT EXISTS core.gdpr_requests_payload_hash_unique ON gdpr_requests (payload_hash)');
});

it('returns 202 and dispatches RedactShopJob for a valid shop/redact webhook', function () {
    $payload = ['shop_domain' => 'test-brand.myshopify.com', 'shop_id' => 12345];
    $body = json_encode($payload);

    $response = $this->postJson(
        '/api/webhooks/shopify/gdpr/shop-redact',
        $payload,
        [
            'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test_shared_secret'),
            'X-Shopify-Shop-Domain' => 'test-brand.myshopify.com',
        ]
    );

    $response->assertStatus(202);
    expect(GdprRequest::where('topic', 'shop/redact')->count())->toBe(1);
    Bus::assertDispatched(RedactShopJob::class);
});

it('returns 202 and dispatches RedactCustomerJob for customers/redact', function () {
    $payload = ['shop_domain' => 'test-brand.myshopify.com', 'customer' => ['email' => 'x@example.com']];
    $body = json_encode($payload);

    $this->postJson(
        '/api/webhooks/shopify/gdpr/customers-redact',
        $payload,
        [
            'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test_shared_secret'),
            'X-Shopify-Shop-Domain' => 'test-brand.myshopify.com',
        ]
    )->assertStatus(202);

    Bus::assertDispatched(RedactCustomerJob::class);
});

it('returns 202 and dispatches ExportCustomerDataJob for customers/data_request', function () {
    $payload = ['shop_domain' => 'test-brand.myshopify.com', 'customer' => ['email' => 'x@example.com']];
    $body = json_encode($payload);

    $this->postJson(
        '/api/webhooks/shopify/gdpr/customers-data-request',
        $payload,
        [
            'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test_shared_secret'),
            'X-Shopify-Shop-Domain' => 'test-brand.myshopify.com',
        ]
    )->assertStatus(202);

    Bus::assertDispatched(ExportCustomerDataJob::class);
});

it('returns 401 and does NOT dispatch when HMAC is invalid', function () {
    $payload = ['shop_domain' => 'test-brand.myshopify.com'];

    $response = $this->postJson(
        '/api/webhooks/shopify/gdpr/shop-redact',
        $payload,
        [
            'X-Shopify-Hmac-SHA256' => 'wrong-signature',
            'X-Shopify-Shop-Domain' => 'test-brand.myshopify.com',
        ]
    );

    $response->assertStatus(401);
    expect(GdprRequest::count())->toBe(0);
    Bus::assertNotDispatched(RedactShopJob::class);
});

it('deduplicates identical payloads — no second row, no second dispatch', function () {
    $payload = ['shop_domain' => 'test-brand.myshopify.com', 'shop_id' => 12345];
    $body = json_encode($payload);
    $headers = [
        'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test_shared_secret'),
        'X-Shopify-Shop-Domain' => 'test-brand.myshopify.com',
    ];

    $this->postJson('/api/webhooks/shopify/gdpr/shop-redact', $payload, $headers)->assertStatus(202);
    $this->postJson('/api/webhooks/shopify/gdpr/shop-redact', $payload, $headers)->assertStatus(202);

    expect(GdprRequest::count())->toBe(1);
    Bus::assertDispatchedTimes(RedactShopJob::class, 1);
});

it('persists payload_hash as sha256 of the raw body', function () {
    $payload = ['shop_domain' => 'test-brand.myshopify.com'];
    $body = json_encode($payload);

    $this->postJson(
        '/api/webhooks/shopify/gdpr/shop-redact',
        $payload,
        [
            'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test_shared_secret'),
            'X-Shopify-Shop-Domain' => 'test-brand.myshopify.com',
        ]
    );

    $row = GdprRequest::first();
    expect($row->payload_hash)->toBe(hash('sha256', $body));
});

it('returns 422 and stores nothing when the body is not valid JSON', function () {
    $rawBody = '!!!not-valid-json!!!';
    $response = $this->call(
        'POST',
        '/api/webhooks/shopify/gdpr/shop-redact',
        [], [], [],
        [
            'HTTP_X-Shopify-Hmac-SHA256' => signShopifyBody($rawBody, 'test_shared_secret'),
            'HTTP_X-Shopify-Shop-Domain' => 'test-brand.myshopify.com',
            'CONTENT_TYPE' => 'application/json',
        ],
        $rawBody,
    );

    $response->assertStatus(422);
    expect(GdprRequest::count())->toBe(0);
    Bus::assertNotDispatched(RedactShopJob::class);
});

it('returns 422 and stores nothing when customers/redact payload is missing customer.email', function () {
    $payload = ['shop_domain' => 'test-brand.myshopify.com', 'customer' => ['id' => 42]];
    $body = json_encode($payload);

    $this->postJson(
        '/api/webhooks/shopify/gdpr/customers-redact',
        $payload,
        [
            'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test_shared_secret'),
            'X-Shopify-Shop-Domain' => 'test-brand.myshopify.com',
        ]
    )->assertStatus(422);

    expect(GdprRequest::count())->toBe(0);
    Bus::assertNotDispatched(RedactCustomerJob::class);
});

it('returns 422 and stores nothing when customers/data_request payload is missing customer.email', function () {
    $payload = ['shop_domain' => 'test-brand.myshopify.com'];
    $body = json_encode($payload);

    $this->postJson(
        '/api/webhooks/shopify/gdpr/customers-data-request',
        $payload,
        [
            'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test_shared_secret'),
            'X-Shopify-Shop-Domain' => 'test-brand.myshopify.com',
        ]
    )->assertStatus(422);

    expect(GdprRequest::count())->toBe(0);
    Bus::assertNotDispatched(ExportCustomerDataJob::class);
});

it('SHOP-1: rejects 400 when the body shop_domain disagrees with the header', function () {
    // Body is validly signed and identifies brand-a; the unsigned header is
    // swapped to brand-b. The cross-check must reject before any DB write.
    $payload = ['shop_domain' => 'brand-a.myshopify.com', 'shop_id' => 12345];
    $body = json_encode($payload);

    $this->postJson(
        '/api/webhooks/shopify/gdpr/shop-redact',
        $payload,
        [
            'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test_shared_secret'),
            'X-Shopify-Shop-Domain' => 'brand-b.myshopify.com',
        ]
    )->assertStatus(400);

    expect(GdprRequest::count())->toBe(0);
    Bus::assertNotDispatched(RedactShopJob::class);
});

it('WEBHOOK-4: rejects 422 when X-Shopify-Triggered-At is older than the max-age window', function () {
    $payload = ['shop_domain' => 'test-brand.myshopify.com', 'shop_id' => 12345];
    $body = json_encode($payload);

    $this->postJson(
        '/api/webhooks/shopify/gdpr/shop-redact',
        $payload,
        [
            'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test_shared_secret'),
            'X-Shopify-Shop-Domain' => 'test-brand.myshopify.com',
            'X-Shopify-Triggered-At' => now()->subDays(5)->toIso8601String(),
        ]
    )->assertStatus(422);

    expect(GdprRequest::count())->toBe(0);
    Bus::assertNotDispatched(RedactShopJob::class);
});

it('WEBHOOK-4: a fresh X-Shopify-Triggered-At within the window is processed normally', function () {
    $payload = ['shop_domain' => 'test-brand.myshopify.com', 'shop_id' => 12345];
    $body = json_encode($payload);

    $this->postJson(
        '/api/webhooks/shopify/gdpr/shop-redact',
        $payload,
        [
            'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test_shared_secret'),
            'X-Shopify-Shop-Domain' => 'test-brand.myshopify.com',
            'X-Shopify-Triggered-At' => now()->subMinutes(2)->toIso8601String(),
        ]
    )->assertStatus(202);

    Bus::assertDispatched(RedactShopJob::class);
});

it('WEBHOOK-4: an unparseable X-Shopify-Triggered-At fails open (webhook still processed)', function () {
    $payload = ['shop_domain' => 'test-brand.myshopify.com', 'shop_id' => 12345];
    $body = json_encode($payload);

    $this->postJson(
        '/api/webhooks/shopify/gdpr/shop-redact',
        $payload,
        [
            'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test_shared_secret'),
            'X-Shopify-Shop-Domain' => 'test-brand.myshopify.com',
            'X-Shopify-Triggered-At' => 'not-a-timestamp',
        ]
    )->assertStatus(202);

    Bus::assertDispatched(RedactShopJob::class);
});

it('accepts the request even when shop_domain is unknown (deferred to the job)', function () {
    $payload = ['shop_domain' => 'ghost.myshopify.com'];
    $body = json_encode($payload);

    $this->postJson(
        '/api/webhooks/shopify/gdpr/shop-redact',
        $payload,
        [
            'X-Shopify-Hmac-SHA256' => signShopifyBody($body, 'test_shared_secret'),
            'X-Shopify-Shop-Domain' => 'ghost.myshopify.com',
        ]
    )->assertStatus(202);

    expect(GdprRequest::where('shop_domain', 'ghost.myshopify.com')->count())->toBe(1);
    Bus::assertDispatched(RedactShopJob::class);
});
