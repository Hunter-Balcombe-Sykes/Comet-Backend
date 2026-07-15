<?php

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\ShopifyScraper;

afterEach(function () {
    Mockery::close();
});

// Build a ShopifyScraper whose fetcher returns $meta for /meta.json and $html
// for the homepage. Mirrors SafeUrlFetcher::fetch's {status, body} return shape.
function shopifyScraperWith(string $html, string $meta = '{"id":1,"name":"Shop","currency":"USD"}'): ShopifyScraper
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->andReturnUsing(function (string $url, array $headers = []) use ($html, $meta) {
        return str_contains($url, '/meta.json')
            ? ['status' => 200, 'body' => $meta]
            : ['status' => 200, 'body' => $html];
    });

    return new ShopifyScraper($fetcher);
}

// Regression for motionlab.space: the header logo img has no class, lives under
// the /s/files/ CDN layout, and carries a ?v= cache-buster — and the store
// declares no <link rel="icon"> at all.
it('extracts a Shopify-CDN logo with a cache-buster and a class-less img', function () {
    $html = '<html><head>'
        .'<meta property="og:site_name" content="MOTION">'
        .'<meta property="og:image" content="https://cdn.shopify.com/s/files/1/0281/files/2-removebg.png?v=1738590890" />'
        .'</head><body>'
        .'<a href="/" class="logo"><img src="https://cdn.shopify.com/s/files/1/0281/files/motion_logo3.png?v=1614313159" width="50px"></a>'
        .'</body></html>';

    $brand = shopifyScraperWith($html, '{"id":28194898059,"name":"MOTION","currency":"AUD"}')
        ->fetchBrand('https://www.motionlab.space');

    expect($brand['name'])->toBe('MOTION')
        ->and($brand['logo'])->toContain('motion_logo3.png')
        ->and($brand['favicon'])->toBeNull();
});

it('returns a null favicon (not a /favicon.ico guess) when no icon link is declared', function () {
    $brand = shopifyScraperWith('<html><head></head><body></body></html>')->fetchBrand('https://shop.example.com');

    expect($brand['favicon'])->toBeNull();
});

it('uses a declared <link rel="icon"> as the favicon', function () {
    $html = '<head><link rel="icon" type="image/png" sizes="32x32" href="https://cdn.shopify.com/favicon32.png"></head>';

    $brand = shopifyScraperWith($html)->fetchBrand('https://shop.example.com');

    expect($brand['favicon'])->toBe('https://cdn.shopify.com/favicon32.png');
});

it('falls back to og:image for the logo when no logo signal is present', function () {
    $html = '<head><meta property="og:image" content="https://cdn.shopify.com/s/files/brandbanner.png?v=1"></head>';

    $brand = shopifyScraperWith($html)->fetchBrand('https://shop.example.com');

    expect($brand['logo'])->toBe('https://cdn.shopify.com/s/files/brandbanner.png?v=1');
});

// ── products.json scraping (#84 — variant images + galleries) ──────────

// Build a scraper whose fetcher serves a canned /products.json body (and 200s
// for anything else). Mirrors SafeUrlFetcher::tryFetch's {status, body} shape.
function shopifyScraperWithProducts(string $productsJson): ShopifyScraper
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->andReturnUsing(function (string $url) use ($productsJson) {
        return str_contains($url, '/products.json')
            ? ['status' => 200, 'body' => $productsJson]
            : ['status' => 200, 'body' => ''];
    });

    return new ShopifyScraper($fetcher);
}

it('scrapes the full product gallery and resolves each variant to its own image', function () {
    // Two-image product, two variants: the first carries an inline
    // featured_image (Shopify's usual shape), the second only an image_id that
    // must resolve against images[] by id. This is exactly Allbirds' shape.
    $json = json_encode(['products' => [[
        'id' => 111,
        'title' => 'Wool Runner',
        'handle' => 'wool-runner',
        'vendor' => 'Allbirds',
        'created_at' => '2026-01-01T00:00:00Z',
        'images' => [
            ['id' => 9001, 'src' => 'https://cdn.shopify.com/grey.jpg'],
            ['id' => 9002, 'src' => 'https://cdn.shopify.com/navy.jpg'],
        ],
        'variants' => [
            ['id' => 201, 'title' => 'Grey', 'price' => '95.00', 'available' => true,
                'featured_image' => ['id' => 9001, 'src' => 'https://cdn.shopify.com/grey.jpg']],
            ['id' => 202, 'title' => 'Navy', 'price' => '95.00', 'available' => true, 'image_id' => 9002],
        ],
    ]]]);

    $products = shopifyScraperWithProducts($json)->fetchProducts('https://allbirds.example');

    expect($products)->toHaveCount(1);
    $p = $products[0];

    // Full gallery, ordered, absolute src strings.
    expect($p['images'])->toBe(['https://cdn.shopify.com/grey.jpg', 'https://cdn.shopify.com/navy.jpg']);
    // Compat hero image is the first gallery image.
    expect($p['image'])->toBe('https://cdn.shopify.com/grey.jpg');

    // Per-variant image: inline featured_image AND image_id→images[] both resolve.
    expect($p['variants'][0]['image'])->toBe('https://cdn.shopify.com/grey.jpg');
    expect($p['variants'][1]['image'])->toBe('https://cdn.shopify.com/navy.jpg');
    // Flat compat fields still populated from the first variant.
    expect($p['variantId'])->toBe('201');
    expect($p['price'])->toBe('95.00');
});

it('emits a null variant image when the merchant assigned none', function () {
    // A single-image product whose lone variant has neither featured_image nor
    // image_id — the sitepage falls back to the product hero.
    $json = json_encode(['products' => [[
        'id' => 222,
        'title' => 'Plain Tee',
        'handle' => 'plain-tee',
        'images' => [['id' => 1, 'src' => 'https://cdn.shopify.com/tee.jpg']],
        'variants' => [
            ['id' => 301, 'title' => 'Default Title', 'price' => '20.00', 'available' => true],
        ],
    ]]]);

    $p = shopifyScraperWithProducts($json)->fetchProducts('https://shop.example')[0];

    expect($p['image'])->toBe('https://cdn.shopify.com/tee.jpg');
    expect($p['variants'][0]['image'])->toBeNull();
    expect($p['images'])->toBe(['https://cdn.shopify.com/tee.jpg']);
});

it('yields an empty images array for a product with no images', function () {
    // Imageless product (rare, but real for draft/placeholder products): the
    // gallery is [] and the hero is null — never a broken/partial shape.
    $json = json_encode(['products' => [[
        'id' => 333,
        'title' => 'No Photo',
        'handle' => 'no-photo',
        'variants' => [['id' => 401, 'title' => 'One', 'price' => '5.00', 'available' => true]],
    ]]]);

    $p = shopifyScraperWithProducts($json)->fetchProducts('https://shop.example')[0];

    expect($p['images'])->toBe([]);
    expect($p['image'])->toBeNull();
    expect($p['variants'][0]['image'])->toBeNull();
});

// ── description sanitization (body_html → plain text) ──────────────────

it('sanitizes body_html into a plain-text description', function () {
    $json = json_encode(['products' => [[
        'id' => 555,
        'title' => 'Cotton Tee',
        'handle' => 'cotton-tee',
        'body_html' => "<p>Soft &amp; <strong>breathable</strong>\n\ncotton.</p>",
        'variants' => [['id' => 601, 'title' => 'One', 'price' => '25.00', 'available' => true]],
    ]]]);

    $p = shopifyScraperWithProducts($json)->fetchProducts('https://shop.example')[0];

    expect($p['description'])->toBe('Soft & breathable cotton.');
});

it('yields a null description when body_html is empty or absent', function () {
    $json = json_encode(['products' => [[
        'id' => 556, 'title' => 'No Desc', 'handle' => 'no-desc', 'body_html' => '',
        'variants' => [['id' => 602, 'title' => 'One', 'price' => '10.00', 'available' => true]],
    ], [
        'id' => 558, 'title' => 'Also No Desc', 'handle' => 'also-no-desc',
        'variants' => [['id' => 604, 'title' => 'One', 'price' => '10.00', 'available' => true]],
    ]]]);

    $products = shopifyScraperWithProducts($json)->fetchProducts('https://shop.example');

    expect($products[0]['description'])->toBeNull();
    expect($products[1]['description'])->toBeNull(); // body_html key absent entirely
});

it('caps a very long description at 2000 characters', function () {
    // Unbroken run (no whitespace) so the 2000-char cut point can never land on
    // a space Str::limit's internal rtrim would then trim away.
    $long = str_repeat('a', 2500);
    $json = json_encode(['products' => [[
        'id' => 557, 'title' => 'Long', 'handle' => 'long', 'body_html' => "<p>{$long}</p>",
        'variants' => [['id' => 603, 'title' => 'One', 'price' => '10.00', 'available' => true]],
    ]]]);

    $description = shopifyScraperWithProducts($json)->fetchProducts('https://shop.example')[0]['description'];

    expect(mb_strlen($description))->toBe(2000);
});
