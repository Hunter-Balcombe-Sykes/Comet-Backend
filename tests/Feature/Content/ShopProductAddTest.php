<?php

use App\Jobs\Platforms\ConnectStoreFromProductJob;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\ShopProviderDetector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Store-first product add on the pool lane (owner, 2026-08-17): a product
// page pasted into POST /content/pools/shop/items is read as a PRODUCT
// (price, images), written through the shop lane's projection, PINNED, and
// — when its origin is a store we can read — the store connects off-thread.

beforeEach(function () {
    Queue::fake();
});

it('reads a product page, pins the product, and keeps the checked words as overrides', function () {
    [$user] = makeShopUser(withSite: true);

    mockShopService(GenericShopScraper::class, function ($m) {
        $m->shouldReceive('readProductPage')->with('https://s.test/products/hat')->andReturn([
            'outcome' => GenericShopScraper::OUTCOME_PRODUCT,
            'storeUrl' => null,
            'product' => [
                'productId' => 'hat', 'title' => 'The Hat', 'url' => 'https://s.test/products/hat',
                'price' => '45.00', 'currency' => 'AUD', 'available' => true,
                'description' => 'A hat.', 'image' => 'https://s.test/hat.jpg', 'images' => ['https://s.test/hat.jpg', 'https://s.test/hat-2.jpg'],
            ],
        ]);
    });
    // Origin probe: not a store we connect (generic) → no job.
    mockShopService(ShopProviderDetector::class, function ($m) {
        $m->shouldReceive('detectDetailed')->andReturn(['detected' => null, 'failure' => 'unsupported']);
    });

    $res = actingAsUser($user)->postJson('/api/content/pools/shop/items', [
        'url' => 'https://s.test/products/hat',
        'title' => 'My Hat',
        'description' => 'A hat.',
    ])->assertCreated();

    $item = $res->json('selection.0');
    expect($item['kind'])->toBe('product')
        ->and($item['headline'])->toBe('My Hat')          // the checked title, as an override
        ->and($item['headlineEdited'])->toBeTrue()
        ->and($item['price']['amountMinor'])->toBe(4500)
        ->and($item['thumbnail'])->toBe('https://s.test/hat.jpg')
        ->and($item['selected'] ?? true)->toBeTrue();

    // Pinned into the shop section — the whole point of the pool lane.
    expect(DB::table('site.section_items')->where('state', 'pinned')->count())->toBe(1);
    Queue::assertNotPushed(ConnectStoreFromProductJob::class);
});

it('connects a Shopify origin off-thread when the owner has not', function () {
    [$user] = makeShopUser(withSite: true);

    mockShopService(GenericShopScraper::class, function ($m) {
        $m->shouldReceive('readProductPage')->andReturn([
            'outcome' => GenericShopScraper::OUTCOME_PRODUCT,
            'storeUrl' => null,
            'product' => ['productId' => 'p1', 'title' => 'One', 'url' => 'https://shop.test/products/p1', 'price' => '10.00'],
        ]);
    });
    mockShopService(ShopProviderDetector::class, function ($m) {
        $m->shouldReceive('detectDetailed')->with('https://shop.test')->andReturn([
            'detected' => ['provider' => ShopProviderDetector::PROVIDER_SHOPIFY, 'origin' => 'https://shop.test', 'sourceUrl' => 'https://shop.test', 'page' => null, 'store' => null, 'meta' => null],
            'failure' => null,
        ]);
    });

    actingAsUser($user)->postJson('/api/content/pools/shop/items', ['url' => 'https://shop.test/products/p1'])->assertCreated();

    Queue::assertPushed(ConnectStoreFromProductJob::class, fn ($job) => $job->userId === (string) $user->id
        && $job->detected['origin'] === 'https://shop.test');
});

it('refuses a store homepage as a product', function () {
    [$user] = makeShopUser(withSite: true);
    mockShopService(GenericShopScraper::class, function ($m) {
        $m->shouldReceive('readProductPage')->andReturn([
            'outcome' => GenericShopScraper::OUTCOME_STORE_PAGE, 'product' => null, 'storeUrl' => 'https://shop.test',
        ]);
    });

    actingAsUser($user)->postJson('/api/content/pools/shop/items', ['url' => 'https://shop.test'])->assertStatus(422);
});

it('falls back to the link card when the page carries no product markup', function () {
    [$user] = makeShopUser(withSite: true);
    mockShopService(GenericShopScraper::class, function ($m) {
        $m->shouldReceive('readProductPage')->andReturn([
            'outcome' => GenericShopScraper::OUTCOME_NO_PRODUCT, 'product' => null, 'storeUrl' => null,
        ]);
    });

    $res = actingAsUser($user)->postJson('/api/content/pools/shop/items', [
        'url' => 'https://blog.test/thing', 'title' => 'A thing', 'logo' => 'https://blog.test/og.png',
    ])->assertCreated();

    expect($res->json('selection.0.headline'))->toBe('A thing')
        ->and($res->json('selection.0.thumbnail'))->toBe('https://blog.test/og.png')
        ->and($res->json('selection.0.price'))->toBeNull();
});
