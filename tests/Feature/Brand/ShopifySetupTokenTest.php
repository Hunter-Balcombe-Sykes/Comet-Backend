<?php

use App\Http\Controllers\Api\Shopify\ShopifyAppOAuthController;
use App\Services\Shopify\ShopifySetupTokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

// --- ShopifySetupTokenService Tests ---

it('creates a 64-char hex token and caches encrypted credentials', function () {
    $service = new ShopifySetupTokenService;

    $token = $service->create(
        'test-shop.myshopify.com',
        'shpat_test_access_token',
        ['name' => 'Test Shop', 'email' => 'owner@shop.com'],
        ['read_products', 'read_orders'],
        'owner@shop.com'
    );

    expect($token)->toBeString();
    expect(strlen($token))->toBe(64);
    expect(ctype_xdigit($token))->toBeTrue();

    // Verify data is in cache
    $data = $service->peek($token);
    expect($data)->not->toBeNull();
    expect($data['shop_domain'])->toBe('test-shop.myshopify.com');
    expect($data['access_token'])->toBe('shpat_test_access_token');
    expect($data['shop_data']['name'])->toBe('Test Shop');
    expect($data['scopes'])->toBe(['read_products', 'read_orders']);
    expect($data['shop_email'])->toBe('owner@shop.com');
});

it('peek reads without consuming the token', function () {
    $service = new ShopifySetupTokenService;

    $token = $service->create(
        'peek-shop.myshopify.com',
        'shpat_peek_token',
        ['name' => 'Peek Shop'],
        ['read_products'],
        'peek@shop.com'
    );

    // First peek
    $data1 = $service->peek($token);
    expect($data1)->not->toBeNull();

    // Second peek — still available
    $data2 = $service->peek($token);
    expect($data2)->not->toBeNull();
    expect($data2['shop_domain'])->toBe('peek-shop.myshopify.com');
});

it('consume returns data and deletes the token', function () {
    $service = new ShopifySetupTokenService;

    $token = $service->create(
        'consume-shop.myshopify.com',
        'shpat_consume_token',
        ['name' => 'Consume Shop'],
        ['read_products'],
        'consume@shop.com'
    );

    // Consume
    $data = $service->consume($token);
    expect($data)->not->toBeNull();
    expect($data['shop_domain'])->toBe('consume-shop.myshopify.com');
    expect($data['access_token'])->toBe('shpat_consume_token');

    // Second consume — gone
    $data2 = $service->consume($token);
    expect($data2)->toBeNull();

    // Peek also gone
    $data3 = $service->peek($token);
    expect($data3)->toBeNull();
});

it('returns null for invalid tokens', function () {
    $service = new ShopifySetupTokenService;

    expect($service->peek('nonexistent_token'))->toBeNull();
    expect($service->consume('nonexistent_token'))->toBeNull();
    expect($service->peek(''))->toBeNull();
    expect($service->consume(''))->toBeNull();
});

// --- Setup Prefill Endpoint Tests ---

it('returns shop data for valid setup token via prefill', function () {
    $service = new ShopifySetupTokenService;

    $token = $service->create(
        'prefill-shop.myshopify.com',
        'shpat_prefill_token',
        [
            'name' => 'Prefill Shop',
            'phone' => '+61400000000',
            'address1' => '123 Main St',
            'city' => 'Sydney',
            'province' => 'NSW',
            'zip' => '2000',
            'country_name' => 'Australia',
            'country_code' => 'AU',
            'iana_timezone' => 'Australia/Sydney',
        ],
        ['read_products'],
        'prefill@shop.com'
    );

    $controller = app(ShopifyAppOAuthController::class);
    $request = Request::create("/api/shopify/setup-prefill?token={$token}", 'GET');

    $response = $controller->setupPrefill($request);

    expect($response->status())->toBe(200);

    $data = $response->getData(true);
    expect($data['shop_name'])->toBe('Prefill Shop');
    expect($data['shop_domain'])->toBe('prefill-shop.myshopify.com');
    expect($data['phone'])->toBe('+61400000000');
    expect($data['address']['city'])->toBe('Sydney');
    expect($data['country_code'])->toBe('AU');
    expect($data['timezone'])->toBe('Australia/Sydney');
});

it('returns 404 for invalid token via prefill', function () {
    $controller = app(ShopifyAppOAuthController::class);
    $request = Request::create('/api/shopify/setup-prefill?token=invalid', 'GET');

    $response = $controller->setupPrefill($request);

    expect($response->status())->toBe(404);
});

it('returns 400 for missing token via prefill', function () {
    $controller = app(ShopifyAppOAuthController::class);
    $request = Request::create('/api/shopify/setup-prefill', 'GET');

    $response = $controller->setupPrefill($request);

    expect($response->status())->toBe(400);
});

// --- installFromSignup (signup-context OAuth kickoff) ---

it('creates a 64-char hex token with bound_uid when set', function () {
    $service = new ShopifySetupTokenService;

    $token = $service->create(
        'bound-shop.myshopify.com',
        'shpat_bound_token',
        ['name' => 'Bound Shop'],
        ['read_products'],
        'bound@shop.com',
        boundUid: 'supabase-uid-abc',
    );

    $data = $service->peek($token);
    expect($data)->not->toBeNull();
    expect($data['bound_uid'])->toBe('supabase-uid-abc');
});

it('leaves bound_uid as null when create() is called without it (backward compat)', function () {
    $service = new ShopifySetupTokenService;

    $token = $service->create(
        'unbound-shop.myshopify.com',
        'shpat_unbound_token',
        ['name' => 'Unbound Shop'],
        ['read_products'],
        'unbound@shop.com',
    );

    $data = $service->peek($token);
    expect($data)->not->toBeNull();
    expect($data['bound_uid'])->toBeNull();
});

it('rejects installFromSignup when supabase_uid is missing', function () {
    $controller = app(ShopifyAppOAuthController::class);
    $request = Request::create('/api/shopify/install-from-signup', 'POST', [
        'shop' => 'test-shop.myshopify.com',
    ]);
    // supabase_uid attribute deliberately not set — simulates the middleware
    // not having run (defence in depth; in prod the route is behind supabase.jwt).

    $response = $controller->installFromSignup($request);

    expect($response->status())->toBe(401);
});

it('rejects installFromSignup when shop is missing', function () {
    $controller = app(ShopifyAppOAuthController::class);
    $request = Request::create('/api/shopify/install-from-signup', 'POST', []);
    $request->attributes->set('supabase_uid', 'supabase-uid-abc');

    $response = $controller->installFromSignup($request);

    expect($response->status())->toBe(400);
});

it('rejects installFromSignup when shop domain is invalid', function () {
    $controller = app(ShopifyAppOAuthController::class);
    $request = Request::create('/api/shopify/install-from-signup', 'POST', [
        'shop' => 'evil.example.com',
    ]);
    $request->attributes->set('supabase_uid', 'supabase-uid-abc');

    $response = $controller->installFromSignup($request);

    expect($response->status())->toBe(400);
});

it('installFromSignup returns Shopify authorize URL and caches signup_context meta', function () {
    config()->set('services.shopify.api_key', 'test-api-key');

    $controller = app(ShopifyAppOAuthController::class);
    $request = Request::create('/api/shopify/install-from-signup', 'POST', [
        'shop' => 'good-shop.myshopify.com',
    ]);
    $request->attributes->set('supabase_uid', 'supabase-uid-zzz');

    $response = $controller->installFromSignup($request);

    expect($response->status())->toBe(200);

    $body = $response->getData(true);
    expect($body['install_url'] ?? null)->toBeString();
    expect($body['install_url'])->toContain('https://good-shop.myshopify.com/admin/oauth/authorize');
    expect($body['install_url'])->toContain('client_id=test-api-key');

    $meta = Cache::get('shopify_oauth_nonce_meta_good-shop.myshopify.com');
    expect($meta)->toBeArray();
    expect($meta['signup_context'])->toBeTrue();
    expect($meta['supabase_uid'])->toBe('supabase-uid-zzz');
});

it('does not expose access token in prefill response', function () {
    $service = new ShopifySetupTokenService;

    $token = $service->create(
        'secure-shop.myshopify.com',
        'shpat_secret_token_should_not_leak',
        ['name' => 'Secure Shop'],
        ['read_products', 'write_orders'],
        'secure@shop.com'
    );

    $controller = app(ShopifyAppOAuthController::class);
    $request = Request::create("/api/shopify/setup-prefill?token={$token}", 'GET');

    $response = $controller->setupPrefill($request);
    $json = $response->getContent();

    expect($json)->not->toContain('shpat_secret_token_should_not_leak');
    expect($json)->not->toContain('access_token');
    expect($json)->not->toContain('scopes');
});
