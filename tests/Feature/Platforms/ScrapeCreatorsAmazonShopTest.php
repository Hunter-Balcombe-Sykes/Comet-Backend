<?php

use App\Services\Platforms\ScrapeCreators\AmazonShopNormalizer;
use App\Services\Shop\ShopProductProjection;
use Tests\Support\Fixtures\Recorded;

// Item 10b (2026-09-01): the Amazon influencer-storefront vendor lane's
// contract, pinned against a RECORDED live payload
// (tests/fixtures/recorded/scrapecreators-amazon-shop.json — the real
// /v1/amazon-shop answer for amazon.com/shop/sydneydelrey, captured
// 2026-09-01). Two properties matter and every test serves one:
//
//  1. When the vendor answers usably, the picks come out as the SAME
//     product-contract blobs every existing shop source feeds
//     ShopContentWriter::syncStore() — proven by reading them straight
//     through ShopProductProjection::fromBlob(), the pool's own reader.
//  2. When the vendor answers any other way — husk, shape drift, empty
//     storefront — the normalizer reads null, never an empty shop.

function scAzFixture(): array
{
    return Recorded::json('scrapecreators-amazon-shop.json');
}

it('normalizes the recorded payload into the product blobs the shop pool reads', function () {
    $page = app(AmazonShopNormalizer::class)->normalize(scAzFixture());

    expect($page)->not->toBeNull()
        ->and($page['name'])->toBe('sydney del rey')
        ->and($page['avatar'])->toStartWith('https://m.media-amazon.com/')
        ->and($page['products'])->toHaveCount(16);

    // The ASIN lives only in the URL path; the affiliate-tagged URL itself is
    // kept verbatim — it is the creator's monetized link.
    expect($page['products'][0])->toBe([
        'url' => 'https://www.amazon.com/shop/sydneydelrey/getProductDetails/B0H87Z7TSV?showRelatedPost=true&tag_override=sydneybertonc-20',
        'image' => 'https://m.media-amazon.com/images/I/418Ij9-X3NL._AC_.jpg',
        'productId' => 'B0H87Z7TSV',
        'price' => '27.98',
    ]);

    // The recorded payload carries one bare-integer price (239) — it must
    // come out in the exact string shape minorUnits() parses.
    expect($page['products'][12]['productId'])->toBe('B0G78BTRS7')
        ->and($page['products'][12]['price'])->toBe('239.00');

    // Nothing billing-shaped survives normalization — credits_* must never
    // travel toward persistence.
    expect(json_encode($page))->not->toContain('credits');
});

it('reads straight through ShopProductProjection::fromBlob — the existing product contract, nothing new', function () {
    $normalizer = app(AmazonShopNormalizer::class);
    $page = $normalizer->normalize(scAzFixture());
    $blob = $normalizer->products($page)[0];

    // fromBlob() is the shop pool's own reader (syncStore() feeds it every
    // stored blob); currency comes from the store row, never this lane.
    $projection = ShopProductProjection::fromBlob($blob, 'USD');

    expect($projection['kind'])->toBe('product')
        ->and($projection['facets']['f_link']['url'])->toBe($blob['url'])
        ->and($projection['facets']['f_catalog']['sku'])->toBe('B0H87Z7TSV')
        ->and($projection['offers'])->toBe([[
            'variant_label' => null,
            'amount_minor' => 2798,
            'currency' => 'USD',
            'qualifier' => 'exact',
            'availability' => 'in_stock',
            'url' => $blob['url'],
        ]])
        ->and($projection['media'])->toBe([['role' => 'cover', 'url' => $blob['image']]]);

    // The vendor sends no product titles — recorded truth, not a bug: the
    // projection simply carries no headline and the photo is the card.
    expect($projection)->not->toHaveKey('headline');
});

it('omits optional keys rather than emitting null, mirroring the vendor', function () {
    $page = app(AmazonShopNormalizer::class)->normalize([
        'trendingPicks' => [[
            // No /getProductDetails/<ASIN> segment → no productId to extract.
            'url' => 'https://www.amazon.com/shop/someone/photo/amzn1.shoppablemedia.v1.abc',
            'image' => 'https://m.media-amazon.com/images/I/x.jpg',
        ]],
    ]);

    expect($page['products'][0])->toBe([
        'url' => 'https://www.amazon.com/shop/someone/photo/amzn1.shoppablemedia.v1.abc',
        'image' => 'https://m.media-amazon.com/images/I/x.jpg',
    ])
        ->and($page)->not->toHaveKey('name')
        ->and($page)->not->toHaveKey('avatar');
});

it('reads every husk shape as a vendor miss, never as an empty storefront', function () {
    $normalizer = app(AmazonShopNormalizer::class);

    // The NotFound quirk: billed, success-shaped, no storefront inside.
    expect($normalizer->normalize(['success' => true, 'credits_charged' => 1]))->toBeNull()
        // Identity without products must fall through too — the shop pool's
        // other lanes settle an empty catalogue, never this one.
        ->and($normalizer->normalize(['name' => 'someone', 'trendingPicks' => []]))->toBeNull()
        // A pick without its photo has no rendering surface (no titles exist).
        ->and($normalizer->normalize(['trendingPicks' => [['url' => 'https://www.amazon.com/shop/a/getProductDetails/B000000001', 'price' => 9.99]]]))->toBeNull()
        ->and($normalizer->normalize(['trendingPicks' => [['url' => 'notaurl', 'image' => 'https://m.media-amazon.com/images/I/x.jpg']]]))->toBeNull()
        ->and($normalizer->normalize(['trendingPicks' => 'unexpected']))->toBeNull();
});

it('keeps a price only when it is a positive number', function () {
    $pick = fn (mixed $price) => [
        'url' => 'https://www.amazon.com/shop/a/getProductDetails/B000000001?tag_override=x',
        'image' => 'https://m.media-amazon.com/images/I/x.jpg',
        'price' => $price,
    ];
    $normalize = fn (mixed $price) => app(AmazonShopNormalizer::class)
        ->normalize(['trendingPicks' => [$pick($price)]])['products'][0];

    expect($normalize(0))->not->toHaveKey('price')
        ->and($normalize(-5))->not->toHaveKey('price')
        ->and($normalize(true))->not->toHaveKey('price')
        ->and($normalize('not a price'))->not->toHaveKey('price')
        // Numeric-string tolerance, same dual-shape posture as the other
        // normalizers — the vendor sends numbers today, but a stringly
        // "30.99" must not silently drop a real offer.
        ->and($normalize('30.99')['price'])->toBe('30.99');
});

it('dedupes picks by ASIN, keeping storefront order', function () {
    $page = app(AmazonShopNormalizer::class)->normalize([
        'trendingPicks' => [
            [
                'url' => 'https://www.amazon.com/shop/a/getProductDetails/B000000001?tag_override=x',
                'image' => 'https://m.media-amazon.com/images/I/first.jpg',
                'price' => 9.99,
            ],
            [
                // Same ASIN resurfacing under different query params — one
                // product, first appearance wins.
                'url' => 'https://www.amazon.com/shop/a/getProductDetails/B000000001?showRelatedPost=true',
                'image' => 'https://m.media-amazon.com/images/I/second.jpg',
                'price' => 8.99,
            ],
            [
                'url' => 'https://www.amazon.com/shop/a/getProductDetails/B000000002?tag_override=x',
                'image' => 'https://m.media-amazon.com/images/I/third.jpg',
            ],
        ],
    ]);

    expect($page['products'])->toHaveCount(2)
        ->and($page['products'][0]['image'])->toBe('https://m.media-amazon.com/images/I/first.jpg')
        ->and($page['products'][1]['productId'])->toBe('B000000002');
});
