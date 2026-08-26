<?php

use App\Services\Platforms\MenuApifyScraper;
use App\Services\Platforms\SquareMenuDriver;
use Illuminate\Support\Facades\Http;

// SquareMenuDriver — first-party HTTP transport (2026-08-26, menu deep-links
// plan B2/A0.1). The fixtures mirror the live shapes verified against
// order.fat-tuna.com: ids in the page's bootstrap state, products from the
// editmysite API with absolute_site_link / inventory flags / price.high.

function sqdPageHtml(): string
{
    return '<html><head><title>FAT TUNA</title></head><body><script>'
        .'window.__x = {"user":{"id":139318758,"properties":{}},"site_id":833850756789237813,"currency":"AUD"};'
        .'</script></body></html>';
}

function sqdProduct(array $overrides = []): array
{
    return array_merge([
        'id' => 'IK2KD23G6W5PNGDDGD5BOGI7',
        'name' => 'RIVER DANCER',
        'short_description' => '<p>Salmon sashimi, kale, carrots</p>',
        'price' => ['high' => 21.9],
        'site_link' => 'product/bowls/river-dancer/1',
        'absolute_site_link' => 'https://order.fat-tuna.com/product/bowls/river-dancer/1',
        'inventory' => ['enabled' => false, 'all_variations_sold_out' => false, 'marked_sold_out_at_all_existing_locations' => false],
        'images' => ['data' => [['absolute_url' => 'https://139318758.cdn6.editmysite.com/uploads/river.jpg']]],
        'category' => ['data' => ['name' => 'CHOOSE YOUR BOWL']],
    ], $overrides);
}

function sqdFake(array $products): void
{
    Http::fake([
        'order.fat-tuna.com/*' => Http::response(sqdPageHtml()),
        'cdn5.editmysite.com/app/store/api/v28/editor/users/139318758/sites/833850756789237813/products*' => Http::response([
            'data' => $products,
            'meta' => ['pagination' => ['total' => count($products)]],
        ]),
    ]);
}

it('fetches and normalizes a Square Online menu — ids from the page, catalog from the API', function () {
    sqdFake([
        sqdProduct(),
        sqdProduct([
            'id' => 'V7S4BTANEL2RYY647KIVGLJ5',
            'name' => 'DARK CHOC-CHIP COOKIE',
            'price' => ['high' => 3.0],
            'absolute_site_link' => 'https://order.fat-tuna.com/product/dark-choc-chip-cookie/101',
            'category' => ['data' => ['name' => 'SWEETS']],
            'inventory' => ['enabled' => true, 'all_variations_sold_out' => true, 'marked_sold_out_at_all_existing_locations' => false],
        ]),
    ]);

    $menu = app(SquareMenuDriver::class)->fetchMenu('https://order.fat-tuna.com/');

    expect($menu)->not->toBeNull();
    expect($menu['store']['name'])->toBe('FAT TUNA');
    expect($menu['store']['currency'])->toBe('AUD');
    expect(collect($menu['categories'])->pluck('name')->all())->toBe(['CHOOSE YOUR BOWL', 'SWEETS']);

    $bowl = $menu['categories'][0]['items'][0];
    expect($bowl['name'])->toBe('River Dancer');
    expect($bowl['price'])->toBe(21.9);
    expect($bowl['externalId'])->toBe('IK2KD23G6W5PNGDDGD5BOGI7');
    expect($bowl['itemUrl'])->toBe('https://order.fat-tuna.com/product/bowls/river-dancer/1');
    expect($bowl['image'])->toBe('https://139318758.cdn6.editmysite.com/uploads/river.jpg');
    expect($bowl['description'])->toBe('Salmon sashimi, kale, carrots');
    // Inventory tracking off → the store makes no stock claim.
    expect($bowl['soldOut'])->toBeNull();

    // Tracking on + all variations gone → sold out.
    $cookie = $menu['categories'][1]['items'][0];
    expect($cookie['soldOut'])->toBeTrue();
});

it('composes the item URL from the store origin when absolute_site_link is absent', function () {
    sqdFake([sqdProduct(['absolute_site_link' => null])]);

    $menu = app(SquareMenuDriver::class)->fetchMenu('https://order.fat-tuna.com/');

    expect($menu['categories'][0]['items'][0]['itemUrl'])
        ->toBe('https://order.fat-tuna.com/product/bowls/river-dancer/1');
});

it('returns null when the page carries no bootstrap ids (not a Square Online store)', function () {
    Http::fake(['example.com/*' => Http::response('<html><body>hello</body></html>')]);

    expect(app(SquareMenuDriver::class)->fetchMenu('https://example.com/'))->toBeNull();
});

it('routes transport=http platforms through fetchMenu in fetchStores, pricing both modes, no token needed', function () {
    config()->set('services.apify.token', null); // http lane must not depend on the Apify token
    sqdFake([sqdProduct()]);

    $out = app(MenuApifyScraper::class)->fetchStores([
        'square' => ['pickupUrl' => null, 'deliveryUrl' => null, 'storeUrl' => 'https://order.fat-tuna.com/', 'modes' => ['pickup', 'delivery']],
    ]);

    expect($out)->toHaveKey('square');
    $item = $out['square']['categories'][0]['items'][0];
    // priced(single, both) — one catalog price serves both modes.
    expect($item['pickupPrice'])->toBe(21.9);
    expect($item['deliveryPrice'])->toBe(21.9);
    expect($item['itemUrl'])->toBe('https://order.fat-tuna.com/product/bowls/river-dancer/1');
});

it('never calls the actor transport methods on an http driver', function () {
    expect(fn () => app(SquareMenuDriver::class)->buildInput('https://x', null))->toThrow(LogicException::class);
    expect(fn () => app(SquareMenuDriver::class)->mapItems([]))->toThrow(LogicException::class);
});
