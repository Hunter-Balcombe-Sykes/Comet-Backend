<?php

use App\Services\Platforms\Payloads\ShopPayload;

it('ShopPayload preserves the brand map verbatim apart from the provider default', function () {
    $map = [
        'brand-1' => [
            'id' => 'brand-1', 'url' => 'https://b', 'name' => 'B', 'currency' => 'AUD',
            'sourceUrl' => 'https://b/shop', 'fetchMode' => 'client',
            'discountCode' => 'SAVE', 'products' => [['productId' => 'p1', 'url' => 'https://b/p1']],
        ],
    ];

    $out = ShopPayload::fromArray($map)->toArray();

    // provider defaulted in; everything else (incl. sourceUrl, fetchMode, products) verbatim.
    expect($out['brand-1']['provider'])->toBe('shopify');
    expect($out['brand-1']['sourceUrl'])->toBe('https://b/shop');
    expect($out['brand-1']['fetchMode'])->toBe('client');
    expect($out['brand-1']['products'])->toBe([['productId' => 'p1', 'url' => 'https://b/p1']]);
});

it('ShopPayload keeps an explicit provider untouched', function () {
    $out = ShopPayload::fromArray(['b' => ['id' => 'b', 'provider' => 'woocommerce', 'products' => []]])->toArray();

    expect($out['b']['provider'])->toBe('woocommerce');
});

it('ShopPayload returns an empty map for a null / non-array payload', function () {
    expect(ShopPayload::fromArray(null)->toArray())->toBe([]);
    expect(ShopPayload::fromArray('garbage')->toArray())->toBe([]);
    expect(ShopPayload::fromArray([])->all())->toBe([]);
});

it('ShopPayload preserves brand order in all()', function () {
    $payload = ShopPayload::fromArray([
        'b1' => ['id' => 'b1', 'products' => []],
        'b2' => ['id' => 'b2', 'products' => []],
    ]);

    expect(array_column($payload->all(), 'id'))->toBe(['b1', 'b2']);
});

it('ShopPayload leaves a non-array brand entry untouched', function () {
    // Defensive: brandMap() preserves non-array entries as-is (no provider default).
    $out = ShopPayload::fromArray(['b' => ['id' => 'b', 'products' => []], 'junk' => 'not-a-brand'])->toArray();

    expect($out['junk'])->toBe('not-a-brand');
    expect($out['b']['provider'])->toBe('shopify');
});

it('ShopPayload primaryWithProducts returns the first brand with products', function () {
    $payload = ShopPayload::fromArray([
        'empty' => ['id' => 'empty', 'products' => []],
        'full' => ['id' => 'full', 'url' => 'https://f', 'provider' => 'shopify', 'discountCode' => 'X', 'products' => [['productId' => 'p1']]],
    ]);

    expect($payload->primaryWithProducts()['id'])->toBe('full');
});

it('ShopPayload primaryWithProducts is null when no brand has products', function () {
    $payload = ShopPayload::fromArray(['b' => ['id' => 'b', 'products' => []]]);

    expect($payload->primaryWithProducts())->toBeNull();
});
