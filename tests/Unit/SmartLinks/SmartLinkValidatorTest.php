<?php

use App\Services\SmartLinks\ResolvedSmartLinkData;
use App\Services\SmartLinks\SmartLinkValidator;

function product(array $meta, ?string $image = 'https://cdn/x.jpg', ?string $brand = 'ACME'): ResolvedSmartLinkData
{
    return new ResolvedSmartLinkData(title: 'Widget', imageUrl: $image, faviconUrl: 'https://shop/favicon.ico', brandName: $brand, metadata: $meta);
}

it('passes a product with price + image + brand', function () {
    $r = (new SmartLinkValidator)->validate('commerce.product', product(['price' => 35.0]));
    expect($r['valid'])->toBeTrue();
});

it('fails a product missing a price', function () {
    $r = (new SmartLinkValidator)->validate('commerce.product', product([]));
    expect($r['valid'])->toBeFalse()->and($r['reason'])->not->toBeNull();
});

it('fails a product missing an image', function () {
    $r = (new SmartLinkValidator)->validate('commerce.product', product(['price' => 10], image: null));
    expect($r['valid'])->toBeFalse();
});

it('passes an event with name + date + image', function () {
    $d = new ResolvedSmartLinkData(title: 'Gig', imageUrl: 'https://cdn/e.jpg', metadata: ['startsAt' => '2026-06-01T19:00:00Z']);
    expect((new SmartLinkValidator)->validate('commerce.event', $d)['valid'])->toBeTrue();
});

it('fails an event with no date', function () {
    $d = new ResolvedSmartLinkData(title: 'Gig', imageUrl: 'https://cdn/e.jpg', metadata: []);
    expect((new SmartLinkValidator)->validate('commerce.event', $d)['valid'])->toBeFalse();
});

it('passes music with cover + title', function () {
    $d = new ResolvedSmartLinkData(title: 'Song', imageUrl: 'https://cdn/c.jpg');
    expect((new SmartLinkValidator)->validate('content.music.track', $d)['valid'])->toBeTrue();
});

it('passes a video with thumbnail + title + channel', function () {
    $d = new ResolvedSmartLinkData(title: 'Vid', imageUrl: 'https://cdn/t.jpg', metadata: ['channelName' => 'GSN']);
    expect((new SmartLinkValidator)->validate('content.video', $d)['valid'])->toBeTrue();
});

it('passes a brand with favicon + name', function () {
    $d = new ResolvedSmartLinkData(faviconUrl: 'https://shop/favicon.ico', brandName: 'ACME');
    expect((new SmartLinkValidator)->validate('commerce.brand', $d)['valid'])->toBeTrue();
});
