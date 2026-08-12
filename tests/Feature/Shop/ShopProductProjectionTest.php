<?php

use App\Services\Shop\ShopProductProjection;

$blob = fn (array $over = []) => array_merge([
    'productId' => '8961996521650',
    'title' => 'The Slick & Smooth Edit',
    'url' => 'https://natalieanne.com/products/slick-smooth',
    'price' => '200.00',
    'currency' => 'AUD',
    'available' => true,
    'image' => 'https://cdn.test/a.jpg',
    'images' => ['https://cdn.test/a.jpg', 'https://cdn.test/b.jpg'],
    'variants' => [['id' => '478113', 'title' => 'Default Title', 'price' => '200.00', 'available' => true]],
    'handle' => 'slick-smooth',
    'vendor' => 'Natalie Anne',
    'description' => 'Six pieces.',
    'createdAt' => '2026-08-04T13:16:08+10:00',
], $over);

it('parses price to integer minor units without touching a float', function () use ($blob) {
    $p = ShopProductProjection::fromBlob($blob(), 'AUD');
    expect($p['offers'][0]['amount_minor'])->toBe(20000)
        ->and($p['offers'][0]['qualifier'])->toBe('exact')
        ->and($p['offers'][0]['currency'])->toBe('AUD')
        ->and($p['offers'][0]['availability'])->toBe('in_stock');
});

it('maps a zero price to qualifier free, not exact-with-zero', function () use ($blob) {
    $p = ShopProductProjection::fromBlob($blob(['price' => '0']), 'AUD');
    expect($p['offers'][0]['qualifier'])->toBe('free')
        ->and($p['offers'][0]['amount_minor'])->toBe(0);
});

it('marks an unavailable product out_of_stock', function () use ($blob) {
    $p = ShopProductProjection::fromBlob($blob(['available' => false]), 'AUD');
    expect($p['offers'][0]['availability'])->toBe('out_of_stock');
});

it('drops the Default Title placeholder variant entirely', function () use ($blob) {
    // 17 of the 51 dev rows are exactly this shape. A variant row labelled
    // "Default Title" names no choice.
    expect(ShopProductProjection::fromBlob($blob(), 'AUD')['variants'])->toBe([]);
});

it('keeps a real single variant', function () use ($blob) {
    $p = ShopProductProjection::fromBlob($blob([
        'variants' => [['id' => 'v1', 'title' => '250ml', 'price' => '35.50', 'available' => true]],
    ]), 'AUD');
    expect($p['variants'])->toHaveCount(1)
        ->and($p['variants'][0]['label'])->toBe('250ml')
        ->and($p['variants'][0]['sku'])->toBe('v1');
});

it('emits one offer per real variant, keyed by variant_label', function () use ($blob) {
    $p = ShopProductProjection::fromBlob($blob([
        'variants' => [
            ['id' => 'v1', 'title' => 'Small', 'price' => '10.00', 'available' => true],
            ['id' => 'v2', 'title' => 'Large', 'price' => '12.50', 'available' => false],
        ],
    ]), 'AUD');

    $variantOffers = array_values(array_filter($p['offers'], fn ($o) => $o['variant_label'] !== null));
    expect($variantOffers)->toHaveCount(2)
        ->and($variantOffers[1]['amount_minor'])->toBe(1250)
        ->and($variantOffers[1]['availability'])->toBe('out_of_stock');
});

it('maps image to cover and images to gallery, cover first', function () use ($blob) {
    $media = ShopProductProjection::fromBlob($blob(), 'AUD')['media'];
    expect($media[0]['role'])->toBe('cover')
        ->and($media[0]['url'])->toBe('https://cdn.test/a.jpg')
        ->and($media[1]['role'])->toBe('gallery')
        ->and($media[1]['url'])->toBe('https://cdn.test/b.jpg');
});

it('does not duplicate the cover image into the gallery', function () use ($blob) {
    // images[] on every dev row begins with the same URL as image.
    $urls = array_column(ShopProductProjection::fromBlob($blob(), 'AUD')['media'], 'url');
    expect($urls)->toBe(['https://cdn.test/a.jpg', 'https://cdn.test/b.jpg']);
});

it('stores the bare product url in f_link, uncomposed', function () use ($blob) {
    // link_mode + referral_query composition is 5b's, at read time.
    $p = ShopProductProjection::fromBlob($blob(), 'AUD');
    expect($p['facets']['f_link']['url'])->toBe('https://natalieanne.com/products/slick-smooth');
});

it('falls back to the store currency when the blob has none', function () use ($blob) {
    $p = ShopProductProjection::fromBlob($blob(['currency' => null]), 'AUD');
    expect($p['offers'][0]['currency'])->toBe('AUD');
});

it('derives a coord from the url and is stable across calls', function () {
    expect(ShopProductProjection::coordFor('https://x.test/p'))
        ->toBe('manual:'.sha1('https://x.test/p'))
        ->and(ShopProductProjection::coordFor('https://x.test/p'))
        ->toBe(ShopProductProjection::coordFor('https://x.test/p'));
});
