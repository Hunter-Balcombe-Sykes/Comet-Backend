<?php

use App\Services\WebsiteScan\WebsiteGalleryCandidateExtractor;

function wgceExtract(string $html, string $baseUrl = 'https://example.com'): array
{
    return (new WebsiteGalleryCandidateExtractor)->extract($html, $baseUrl);
}

it('extracts a plain content image', function () {
    $html = '<body><img src="/photos/dining-room.jpg"></body>';

    expect(wgceExtract($html))->toBe(['https://example.com/photos/dining-room.jpg']);
});

it('resolves a relative src against the base URL', function () {
    $html = '<img src="gallery/plate.jpg">';

    expect(wgceExtract($html, 'https://example.com/menu'))->toBe(['https://example.com/gallery/plate.jpg']);
});

it('falls back to data-src for lazy-loaded images', function () {
    $html = '<img data-src="/photos/lazy.jpg">';

    expect(wgceExtract($html))->toBe(['https://example.com/photos/lazy.jpg']);
});

it('excludes images inside header, nav, and footer', function () {
    $html = '<header><img src="/logo.jpg"></header>'
        .'<nav><img src="/nav-icon.jpg"></nav>'
        .'<body><img src="/photos/food.jpg"></body>'
        .'<footer><img src="/footer-badge.jpg"></footer>';

    expect(wgceExtract($html))->toBe(['https://example.com/photos/food.jpg']);
});

it('excludes images whose src/class/alt matches a noise pattern', function () {
    $html = '<img src="/icons/star.png">'
        .'<img src="/brand/logo.png">'
        .'<img src="/img/sprite.png">'
        .'<img class="avatar-thumb" src="/user/pic.jpg">'
        .'<img src="/photos/dish.jpg">';

    expect(wgceExtract($html))->toBe(['https://example.com/photos/dish.jpg']);
});

it('excludes an image with a declared width or height under the minimum', function () {
    $html = '<img src="/photos/tiny.jpg" width="40" height="40">'
        .'<img src="/photos/big.jpg" width="800" height="600">';

    expect(wgceExtract($html))->toBe(['https://example.com/photos/big.jpg']);
});

it('accepts an image with no declared dimensions at all', function () {
    $html = '<img src="/photos/undeclared.jpg">';

    expect(wgceExtract($html))->toBe(['https://example.com/photos/undeclared.jpg']);
});

it('dedupes repeated src values', function () {
    $html = '<img src="/photos/same.jpg"><img src="/photos/same.jpg">';

    expect(wgceExtract($html))->toBe(['https://example.com/photos/same.jpg']);
});

it('excludes a data: URI image', function () {
    $html = '<img src="data:image/png;base64,iVBORw0KGgo=">';

    expect(wgceExtract($html))->toBe([]);
});

it('resolves a protocol-relative src against the base scheme', function () {
    $html = '<img src="//cdn.example.com/photos/plate.jpg">';

    expect(wgceExtract($html))->toBe(['https://cdn.example.com/photos/plate.jpg']);
});

it('returns an empty list for a page with no images', function () {
    expect(wgceExtract('<p>No photos here.</p>'))->toBe([]);
});

it('returns an empty list for empty html', function () {
    expect(wgceExtract(''))->toBe([]);
});
