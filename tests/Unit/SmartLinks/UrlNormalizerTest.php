<?php

use App\Services\SmartLinks\UrlNormalizer;

it('strips variant and keeps the canonical product path', function () {
    $p = (new UrlNormalizer)->parse('https://shop.com/products/widget?variant=12345');

    expect($p->canonical)->toBe('https://shop.com/products/widget')
        ->and($p->trackingQuery)->toBeNull()
        ->and($p->pathSegments)->toBe(['products', 'widget']);
});

it('moves affiliate/tracking params to trackingQuery and drops variant', function () {
    $p = (new UrlNormalizer)->parse('https://shop.com/products/widget?variant=9&sca_ref=tobias&utm_source=ig');

    expect($p->canonical)->toBe('https://shop.com/products/widget')
        ->and($p->trackingQuery)->toContain('sca_ref=tobias')
        ->and($p->trackingQuery)->toContain('utm_source=ig');
});

it('preserves essential query params like Apple’s ?i= on the canonical URL', function () {
    $p = (new UrlNormalizer)->parse('https://music.apple.com/us/album/x/12345?i=67890');

    expect($p->canonical)->toContain('i=67890')
        ->and($p->essentialQuery)->toHaveKey('i');
});

it('prepends https when the scheme is missing', function () {
    $p = (new UrlNormalizer)->parse('gymshark.com/products/tee');

    expect($p->scheme)->toBe('https')
        ->and($p->host)->toBe('gymshark.com');
});

it('lowercases the host', function () {
    $p = (new UrlNormalizer)->parse('https://Open.Spotify.com/track/abc');

    expect($p->host)->toBe('open.spotify.com');
});
