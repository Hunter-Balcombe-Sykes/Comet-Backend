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

// ── Fix round 2, D1: the per-variant image ────────────────────────────────
//
// The blob's variant entries carry `image` — the picture the sitepage swaps
// to when a shopper picks that choice (#84). It was dropped on the way in,
// which only became visible once the public wire started being served from
// content.* rather than the legacy blob. Migration 20260813100003 gave
// content.item_variants a column for it; these two pin both directions,
// because a source publishing no image is the common case, not an error.

it('carries a variant image through to the projection', function () use ($blob) {
    $p = ShopProductProjection::fromBlob($blob([
        'variants' => [['id' => 'v1', 'title' => 'Grey', 'price' => '35.50', 'available' => true, 'image' => 'https://cdn.test/grey.jpg']],
    ]), 'AUD');

    expect($p['variants'][0]['image_url'])->toBe('https://cdn.test/grey.jpg');
});

it('projects a variant with no image as null, not as an absent key', function () use ($blob) {
    // Three shapes that must all land as null: explicitly null (the sample
    // data's own single-variant shape), absent entirely, and an empty string
    // — the last must never reach the column as a fake URL.
    $cases = [
        ['id' => 'v1', 'title' => 'Grey', 'image' => null],
        ['id' => 'v1', 'title' => 'Grey'],
        ['id' => 'v1', 'title' => 'Grey', 'image' => ''],
    ];

    foreach ($cases as $variant) {
        $p = ShopProductProjection::fromBlob($blob(['variants' => [$variant]]), 'AUD');
        expect($p['variants'][0])->toHaveKey('image_url')
            ->and($p['variants'][0]['image_url'])->toBeNull();
    }
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

it('maps a minimal blob — url only — without error, with no offer and no variants', function () {
    // Fix round 1, Finding 1: every other key fromBlob() reads (title,
    // productId, price, image, currency) used a direct array access with no
    // ?? — a live scrape blob missing any of them threw ErrorException
    // (Laravel promotes PHP warnings to exceptions), not a graceful null.
    $p = ShopProductProjection::fromBlob(['url' => 'https://s.test/minimal'], null);

    expect($p['offers'])->toBe([])
        ->and($p['variants'])->toBe([])
        ->and($p['media'])->toBe([])
        ->and($p['facets']['f_link']['url'])->toBe('https://s.test/minimal')
        ->and($p)->not->toHaveKey('headline');
});

it('writes handle, vendor and the default variantId to f_catalog, and description to f_text.body', function () use ($blob) {
    // Fix round 1, Finding 3: these were previously never emitted at all
    // (the spec's original claim that they went to items.facets_cache was
    // wrong — that column is a derived, read-only cache of facet TYPES, not
    // a data payload). variant_ref carries the top-level variantId, not a
    // per-variant sku — variants() drops the Default Title placeholder
    // entirely, so this is the only surviving home for that id.
    $p = ShopProductProjection::fromBlob($blob(['variantId' => '478113']), 'AUD');

    expect($p['facets']['f_catalog']['sku'])->toBe('8961996521650')
        ->and($p['facets']['f_catalog']['handle'])->toBe('slick-smooth')
        ->and($p['facets']['f_catalog']['vendor'])->toBe('Natalie Anne')
        ->and($p['facets']['f_catalog']['variant_ref'])->toBe('478113')
        ->and($p['facets']['f_text']['body'])->toBe('Six pieces.');
});

it('drops f_catalog/f_text keys the blob omits, without emitting null noise', function () {
    $p = ShopProductProjection::fromBlob(['url' => 'https://s.test/bare', 'productId' => 'p1'], null);

    expect($p['facets']['f_catalog'])->toBe(['sku' => 'p1'])
        ->and($p['facets'])->not->toHaveKey('f_text');
});

it('writes createdAt to f_published.published_from, verbatim', function () use ($blob) {
    // Fix round 2, Finding 1: createdAt is not cosmetic — ShopCatalog::
    // syncLatest() sorts on it to pick a latest-mode store's newest
    // products, so a lossy round-trip silently changes what a store shows.
    // Passed through unchanged, matching every other f_published-writing
    // projector (they hand the source's own date string straight to
    // published_from; the timestamptz cast on write does the parsing).
    $p = ShopProductProjection::fromBlob($blob(), 'AUD');

    expect($p['facets']['f_published'])->toBe(['published_from' => '2026-08-04T13:16:08+10:00']);
});

it('writes no f_published row when createdAt is absent', function () use ($blob) {
    $p = ShopProductProjection::fromBlob($blob(['createdAt' => null]), 'AUD');

    expect($p['facets'])->not->toHaveKey('f_published');
});

it('writes no f_published row when createdAt is unparseable, rather than a null/epoch timestamp', function () use ($blob) {
    // published_from is a real timestamptz column — an unparseable string
    // must never reach it (Postgres would either reject it outright or, worse,
    // silently misparse it into something that isn't the source's actual date).
    $p = ShopProductProjection::fromBlob($blob(['createdAt' => 'not-a-date']), 'AUD');

    expect($p['facets'])->not->toHaveKey('f_published');
});

it('derives a coord from the url and is stable across calls', function () {
    expect(ShopProductProjection::coordFor('https://x.test/p'))
        ->toBe('manual:'.sha1('https://x.test/p'))
        ->and(ShopProductProjection::coordFor('https://x.test/p'))
        ->toBe(ShopProductProjection::coordFor('https://x.test/p'));
});
