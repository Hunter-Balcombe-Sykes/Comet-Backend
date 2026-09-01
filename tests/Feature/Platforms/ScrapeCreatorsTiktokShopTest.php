<?php

use App\Services\Platforms\ScrapeCreators\TiktokShopProductsNormalizer;
use App\Services\Platforms\ScrapeCreators\TiktokShopReviewsNormalizer;
use App\Services\Shop\ShopProductProjection;

// Item 10b (2026-09-01): the TikTok Shop normalizers, pinned against RECORDED
// live payloads (slimmed /v1/tiktok/shop/products + product-reviews answers
// for the Goli Nutrition US storefront). Two properties, the Wave 1 frame:
//
//  1. When the vendor answers usably, products land in ShopifyScraper's exact
//     catalogue blob contract (proven by feeding a row straight through
//     ShopProductProjection::fromBlob) and reviews land in the exact field
//     vocabulary FreshaConnector::mapReview taught the review projectors.
//  2. When the vendor answers any other way — a billed NotFound husk
//     included — the normalizer returns null and the caller treats it as a
//     vendor miss, never as an empty storefront or a review-less product.
//
// No driver/registry wiring is exercised here on purpose: that lands in the
// Wave 4 central wiring pass; these are the pure adapter contracts.

function scTtShopProductsFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-tiktok-shop-products.json')),
        true
    );
}

function scTtShopReviewsFixture(): array
{
    return json_decode(
        file_get_contents(base_path('tests/fixtures/recorded/scrapecreators-tiktok-shop-product-reviews.json')),
        true
    );
}

// ── (a) Products: the catalogue blob contract ───────────────────────────────

it('normalizes recorded shop products into the exact catalogue blob shape the shop writer consumes', function () {
    $page = app(TiktokShopProductsNormalizer::class)->normalize(scTtShopProductsFixture());

    expect($page['shop'])->toBe([
        'seller_id' => '7495794203056835079',
        'name' => 'Goli Nutrition',
        'url' => 'https://www.tiktok.com/shop/store/goli-nutrition/7495794203056835079',
        'logo' => 'https://p16-oec-general-useast5.ttcdn-us.com/tos-useast5-i-omjb5zjo8w-tx/e7478d3e93d4487a9e772fa74e10f506~tplv-fhlh96nyum-resize-webp:300:300.webp?dr=12185&t=555f072d&ps=933b5bde&shp=905da467&shcp=837c8b87&idc=useast5&from=2422056039',
        'rating' => 4.6,
        'review_count' => 406185,
    ])->and($page['products'])->toHaveCount(4);

    $first = $page['products'][0];
    expect($first['productId'])->toBe('1729527313880355335')
        ->and($first['title'])->toStartWith('Goli Ashwagandha & Vitamin D Gummy')
        ->and($first['handle'])->toStartWith('goli-ashwagandha-vitamin-d-gummy')
        ->and($first['vendor'])->toBe('Goli Nutrition')
        ->and($first['price'])->toBe('14.98')
        ->and($first['currency'])->toBe('USD')
        // variantId carries the default SKU id — f_catalog.variant_ref's home.
        ->and($first['variantId'])->toBe('1729527298861535751')
        ->and($first['url'])->toBe('https://www.tiktok.com/shop/pdp/1729527313880355335')
        ->and($first['image'])->toStartWith('https://')
        ->and($first['images'])->toBe([$first['image']])
        ->and($first['available'])->toBeTrue()
        // No createdAt anywhere → syncLatest keeps the endpoint's own order.
        ->and($first['createdAt'])->toBeNull()
        ->and($first['variants'])->toBe([]);
});

it('feeds a normalized product row through ShopProductProjection without losing a cent', function () {
    $page = app(TiktokShopProductsNormalizer::class)->normalize(scTtShopProductsFixture());

    // The trailing-zero quirk row: sale_price_decimal "30.8" must project as
    // 3080 minor units, not 3008 or a float casualty.
    $trio = collect($page['products'])->firstWhere('productId', '1731194857673101831');
    $projection = ShopProductProjection::fromBlob($trio, null);

    expect($projection['kind'])->toBe('product')
        ->and($projection['headline'])->toStartWith('Zero Sugar Best Seller Trio')
        ->and($projection['facets']['f_link']['url'])->toBe('https://www.tiktok.com/shop/pdp/1731194857673101831')
        ->and($projection['facets']['f_catalog']['sku'])->toBe('1731194857673101831')
        ->and($projection['facets']['f_catalog']['variant_ref'])->toBe('1731194889857176071')
        ->and($projection['offers'])->toHaveCount(1)
        ->and($projection['offers'][0]['amount_minor'])->toBe(3080)
        ->and($projection['offers'][0]['currency'])->toBe('USD')
        ->and($projection['offers'][0]['availability'])->toBe('in_stock')
        ->and($projection['media'])->toBe([['role' => 'cover', 'url' => $trio['image']]]);
});

it('reads a shop-products husk as a vendor miss, never as an empty storefront', function () {
    $normalizer = app(TiktokShopProductsNormalizer::class);

    // The billed NotFound quirk: success:true with nothing usable inside.
    expect($normalizer->normalize(['success' => true, 'credits_charged' => 1, 'products' => []]))->toBeNull()
        ->and($normalizer->normalize(['success' => false, 'message' => 'nope']))->toBeNull()
        // A named shop whose every product row is id-less is still a miss.
        ->and($normalizer->normalize([
            'success' => true,
            'shopInfo' => ['seller_id' => '7495794203056835079', 'shop_name' => 'Goli Nutrition'],
            'products' => [['title' => 'No id'], 'not-a-row'],
        ]))->toBeNull()
        // Products without a named shop cannot mint a storefront — miss.
        ->and($normalizer->normalize([
            'success' => true,
            'products' => [['product_id' => '1', 'title' => 'Orphan']],
        ]))->toBeNull();
});

// ── (b) Reviews: the review-record vocabulary ───────────────────────────────

it('normalizes recorded product reviews into the exact record vocabulary the review projectors read', function () {
    $rows = app(TiktokShopReviewsNormalizer::class)->rows(scTtShopReviewsFixture());

    expect($rows)->toHaveCount(3);

    $full = collect($rows)->firstWhere('review_id', '7505445725870786347');
    expect($full['rating'])->toBe(5.0)
        ->and($full['text'])->toContain('noticed a big difference')
        ->and($full['author'])->toContain('Alessandra')
        ->and($full['author_photo'])->toStartWith('https://')
        // The ms-epoch quirk: "1747497773104" must land in 2025, not 55366.
        ->and($full['publish_time'])->toBe('2025-05-17T16:02:53.000Z')
        ->and($full['verified'])->toBeTrue()
        ->and($full['variant'])->toBe('Item: 1 Bottle')
        // Product-level aggregates ride every row, Fresha's venue_rating way.
        ->and($full['product_rating'])->toBe(4.5)
        ->and($full['product_rating_count'])->toBe(94616);

    // The anonymous-reviewer shape: masked name passes through as the vendor's
    // own public rendering; the absent avatar key lands as no key at all.
    $masked = collect($rows)->firstWhere('review_id', '7462440092775794475');
    expect($masked['author'])->toBe('E**n')
        ->and($masked)->not->toHaveKey('author_photo');

    $fourStar = collect($rows)->firstWhere('review_id', '7456832581015865130');
    expect($fourStar['rating'])->toBe(4.0)
        ->and($fourStar['publish_time'])->toBe('2025-01-06T15:59:05.000Z');
});

it('reads a reviews husk as a vendor miss, never as a review-less product', function () {
    $normalizer = app(TiktokShopReviewsNormalizer::class);

    expect($normalizer->rows(['success' => true, 'credits_charged' => 1, 'product_reviews' => []]))->toBeNull()
        ->and($normalizer->rows(['success' => false, 'message' => 'nope']))->toBeNull()
        // Rows missing an id or a numeric rating are dropped, and a page of
        // only-dropped rows is a miss.
        ->and($normalizer->rows(['product_reviews' => [
            ['review_rating' => 5],
            ['review_id' => '71', 'review_rating' => 'great'],
            'not-a-row',
        ]]))->toBeNull();
});
