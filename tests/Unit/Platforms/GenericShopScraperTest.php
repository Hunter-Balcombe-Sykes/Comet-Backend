<?php

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\GenericShopScraper;

afterEach(function () {
    Mockery::close();
});

function genericScraperWith(string $html, int $status = 200): GenericShopScraper
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->andReturn([
        'status' => $status, 'body' => $html, 'finalUrl' => 'https://shop.example/store', 'contentType' => 'text/html',
    ]);

    return new GenericShopScraper($fetcher);
}

it('extracts products from an ItemList of Product JSON-LD nodes', function () {
    $ld = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'item' => [
                '@type' => 'Product',
                'name' => 'Ceramic Mug',
                'sku' => 'MUG-1',
                'url' => '/products/ceramic-mug',
                'image' => ['https://shop.example/img/mug.jpg'],
                'offers' => ['@type' => 'Offer', 'price' => '29.00', 'priceCurrency' => 'aud', 'availability' => 'https://schema.org/InStock'],
            ]],
            ['@type' => 'ListItem', 'position' => 2, 'item' => [
                '@type' => 'Product',
                'name' => 'Sold Out Vase',
                'url' => 'https://shop.example/products/vase',
                'image' => ['@type' => 'ImageObject', 'url' => 'https://shop.example/img/vase.jpg'],
                'offers' => [['price' => '120', 'priceCurrency' => 'AUD', 'availability' => 'https://schema.org/OutOfStock']],
            ]],
        ],
    ]);
    $html = '<html><head><meta property="og:site_name" content="Example Ceramics">'
        .'<script type="application/ld+json">'.$ld.'</script></head><body></body></html>';

    $page = genericScraperWith($html)->fetchPage('https://shop.example/store');

    expect($page)->not->toBeNull();
    expect($page['brand']['name'])->toBe('Example Ceramics');
    expect($page['brand']['id'])->toBe('shop-example');
    expect($page['brand']['currency'])->toBe('AUD');

    expect($page['products'])->toHaveCount(2);
    expect($page['products'][0])->toMatchArray([
        'productId' => 'MUG-1',
        'title' => 'Ceramic Mug',
        'image' => 'https://shop.example/img/mug.jpg',
        'price' => '29.00',
        'currency' => 'AUD',
        'available' => true,
        'url' => 'https://shop.example/products/ceramic-mug',
    ]);
    expect($page['products'][1]['available'])->toBeFalse();
    expect($page['products'][1]['image'])->toBe('https://shop.example/img/vase.jpg');
});

// ── images[] gallery + description sanitization ─────────────────────────

it('extracts the full image array and a sanitized description from JSON-LD', function () {
    $ld = json_encode([
        '@type' => 'Product',
        'name' => 'Ceramic Bowl',
        'sku' => 'BOWL-1',
        'url' => '/products/ceramic-bowl',
        'image' => ['https://shop.example/img/bowl1.jpg', 'https://shop.example/img/bowl2.jpg'],
        'description' => "<p>Hand &amp; <em>wheel</em>\nthrown.</p>",
        'offers' => ['price' => '45.00', 'priceCurrency' => 'AUD', 'availability' => 'https://schema.org/InStock'],
    ]);
    $html = '<html><head><script type="application/ld+json">'.$ld.'</script></head></html>';

    $product = genericScraperWith($html)->fetchPage('https://shop.example/store')['products'][0];

    expect($product['image'])->toBe('https://shop.example/img/bowl1.jpg');
    expect($product['images'])->toBe(['https://shop.example/img/bowl1.jpg', 'https://shop.example/img/bowl2.jpg']);
    expect($product['description'])->toBe('Hand & wheel thrown.');
});

it('inserts a space at former block-element boundaries in a JSON-LD description, instead of gluing blocks together (B4)', function () {
    // Shared PlatformScraper::sanitizeDescription() fix — strip_tags() alone
    // glues "<p>Hello</p><p>world</p>" into "Helloworld" with no boundary space.
    $ld = json_encode([
        '@type' => 'Product',
        'name' => 'Glued Text Check',
        'sku' => 'GLUED-1',
        'url' => '/products/glued-text-check',
        'description' => '<p>Hello</p><p>world</p><ul><li>One</li><li>Two</li></ul>',
        'offers' => ['price' => '10.00', 'priceCurrency' => 'AUD'],
    ]);
    $html = '<html><head><script type="application/ld+json">'.$ld.'</script></head></html>';

    $product = genericScraperWith($html)->fetchPage('https://shop.example/store')['products'][0];

    expect($product['description'])->toBe('Hello world One Two');
});

it('falls back to the original html when the boundary-space preg_replace engine-errors, instead of silently degrading to empty (fix-round P4)', function () {
    // preg_replace returns null (not an exception) on a PCRE engine error.
    // Earlier this test forced that via `ini_set('pcre.backtrack_limit', 1)`
    // — a process-global mutation. On PHP 8.4, restoring the ini value in a
    // `finally` isn't enough to contain the blast radius: while the limit is
    // suppressed, an UNGUARDED preg_replace deeper in the same call
    // (Str::squish()) also engine-errors, its null return trips a PHP 8.1+
    // deprecation (mb_strwidth(null, ...)) inside Str::limit(), and PHPUnit's
    // deprecation handler runs *while still nested inside the mutated
    // window* — calling Pest's own Testable::getPrintableTestCaseName(),
    // whose preg_replace ALSO engine-errors under the still-active limit=1,
    // violating its `: string` return type and crashing the whole runner
    // (not just this test). Any regex, however trivial, fails once the
    // limit is set that low — there's no safe margin to carve out for
    // "everything downstream of the one call under test."
    //
    // Fix: trigger a REAL, organic engine error instead — an unclosed tag
    // whose attribute run is long enough to exceed PCRE's *default*
    // backtrack_limit (1_000_000) with no ini mutation at all. This regex
    // has no nested quantifiers (no catastrophic/exponential backtracking),
    // so the cost is linear in input length; ~20M characters gives a
    // comfortable multiple of the ~4-5M needed here, and still runs in
    // milliseconds. Because pcre.backtrack_limit is never touched, nothing
    // else in the process — Str::squish(), Pest's own regexes, any other
    // test — can be affected. True test isolation, not a restore-and-hope.
    $scraper = new class(Mockery::mock(SafeUrlFetcher::class)) extends GenericShopScraper
    {
        public function sanitize(mixed $html): ?string
        {
            return $this->sanitizeDescription($html);
        }
    };

    // Well-formed content first (this is what the assertion below checks),
    // then a dangling unclosed <table tag with no closing '>' anywhere —
    // strip_tags() on the untouched fallback html strips that whole trailing
    // run since it never finds a closing '>', leaving only "Helloworld".
    $html = '<p>Hello</p><p>world</p><table '.str_repeat('x', 20_000_000);

    $result = $scraper->sanitize($html);

    // Without the `?? $html` fallback, (string) null === '' would silently
    // collapse this to an empty/null description before strip_tags() even
    // runs. strip_tags() on the untouched original html still yields text —
    // the boundary-space enhancement is degraded (no inserted space, same as
    // pre-B4 behavior), but the description itself survives non-empty.
    expect($result)->toBe('Helloworld');
});

it('resolves an images array of ImageObjects and yields a null description when absent', function () {
    $ld = json_encode([
        '@type' => 'Product',
        'name' => 'Linen Shirt',
        'sku' => 'SHIRT-1',
        'url' => '/products/linen-shirt',
        'image' => [['@type' => 'ImageObject', 'url' => 'https://shop.example/img/shirt1.jpg']],
        'offers' => ['price' => '80.00', 'priceCurrency' => 'AUD'],
    ]);
    $html = '<html><head><script type="application/ld+json">'.$ld.'</script></head></html>';

    $product = genericScraperWith($html)->fetchPage('https://shop.example/store')['products'][0];

    expect($product['images'])->toBe(['https://shop.example/img/shirt1.jpg']);
    expect($product['description'])->toBeNull();
});

it('caps the JSON-LD image array at 25', function () {
    $images = array_map(fn ($i) => "https://shop.example/img/{$i}.jpg", range(1, 30));
    $ld = json_encode([
        '@type' => 'Product', 'name' => 'Many', 'sku' => 'MANY-1', 'url' => '/products/many',
        'image' => $images, 'offers' => ['price' => '10.00', 'priceCurrency' => 'AUD'],
    ]);
    $html = '<html><head><script type="application/ld+json">'.$ld.'</script></head></html>';

    $product = genericScraperWith($html)->fetchPage('https://shop.example/store')['products'][0];

    expect($product['images'])->toHaveCount(25);
});

it('returns null when the page has no Product JSON-LD', function () {
    $html = '<html><head><script type="application/ld+json">{"@type":"Organization","name":"X"}</script></head></html>';

    expect(genericScraperWith($html)->fetchPage('https://shop.example/store'))->toBeNull();
});

it('returns null on a non-200 page', function () {
    expect(genericScraperWith('', 404)->fetchPage('https://shop.example/store'))->toBeNull();
});

/** Live-site fixture HTML saved under tests/fixtures/shop (WS-B1). */
function shopFixture(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/recorded/shop/'.$name);
}

// Same canned-fetcher builder, but with a controllable finalUrl — the
// store-homepage classification keys off the URL path.
function genericScraperAt(string $html, string $finalUrl, int $status = 200): GenericShopScraper
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->andReturn([
        'status' => $status, 'body' => $html, 'finalUrl' => $finalUrl, 'contentType' => 'text/html',
    ]);

    return new GenericShopScraper($fetcher);
}

// ── readProductPage() outcomes (WS-B1.1) ──────────────────────────────────────

it('classifies a storefront homepage as store_page — live abovetheground.co fixture', function () {
    // The regression: a Shopify brand homepage (og:type=website, JSON-LD
    // Organization only, no Product markup) was accepted as a "product" off
    // its og:title. It must instead come back as a distinct store_page signal.
    $html = shopFixture('abovetheground-homepage.html');

    $out = genericScraperAt($html, 'https://abovetheground.co/')->readProductPage('https://abovetheground.co');

    expect($out['outcome'])->toBe(GenericShopScraper::OUTCOME_STORE_PAGE);
    expect($out['storeUrl'])->toBe('https://abovetheground.co');
    expect($out['product'])->toBeNull();
    expect(genericScraperAt($html, 'https://abovetheground.co/')->fetchSingleProduct('https://abovetheground.co'))->toBeNull();
});

it('no longer fabricates a product from og:title alone', function () {
    $html = '<html><head><meta property="og:type" content="website">'
        .'<meta property="og:title" content="Some Personal Site"></head><body></body></html>';

    // Deep URL, no storefront markers → plain no_product (never store_page:
    // a real product page that fails extraction must not be false-blocked).
    $out = genericScraperAt($html, 'https://brand.example/about')->readProductPage('https://brand.example/about');

    expect($out['outcome'])->toBe(GenericShopScraper::OUTCOME_NO_PRODUCT);
    expect($out['product'])->toBeNull();
});

it('accepts an OpenGraph product page via og:type=product', function () {
    $html = '<html><head><meta property="og:type" content="product">'
        .'<meta property="og:title" content="Bulwark Jacket">'
        .'<meta property="og:image" content="/img/jacket.jpg"></head></html>';

    $out = genericScraperAt($html, 'https://store.example/product/bulwark-jacket')
        ->readProductPage('https://store.example/product/bulwark-jacket');

    expect($out['outcome'])->toBe(GenericShopScraper::OUTCOME_PRODUCT);
    expect($out['product']['title'])->toBe('Bulwark Jacket');
    expect($out['product']['image'])->toBe('https://store.example/img/jacket.jpg');
});

it('accepts an OpenGraph product page via an explicit price meta', function () {
    $html = '<html><head><meta property="og:title" content="Swim Short">'
        .'<meta property="product:price:amount" content="100.00">'
        .'<meta property="product:price:currency" content="aud"></head></html>';

    $out = genericScraperAt($html, 'https://store.example/product/swim-short')
        ->readProductPage('https://store.example/product/swim-short');

    expect($out['outcome'])->toBe(GenericShopScraper::OUTCOME_PRODUCT);
    expect($out['product']['price'])->toBe('100.00');
    expect($out['product']['currency'])->toBe('AUD');
});

it('classifies a root page carrying a multi-product JSON-LD list as store_page', function () {
    $ld = json_encode(['@type' => 'ItemList', 'itemListElement' => [
        ['@type' => 'ListItem', 'item' => ['@type' => 'Product', 'name' => 'One', 'url' => '/products/one']],
        ['@type' => 'ListItem', 'item' => ['@type' => 'Product', 'name' => 'Two', 'url' => '/products/two']],
    ]]);
    $html = '<html><head><script type="application/ld+json">'.$ld.'</script></head></html>';

    $out = genericScraperAt($html, 'https://shop.example/')->readProductPage('https://shop.example');

    expect($out['outcome'])->toBe(GenericShopScraper::OUTCOME_STORE_PAGE);
    expect($out['storeUrl'])->toBe('https://shop.example');
});

it('keeps a deep page with Product JSON-LD as a product even when a related list is present', function () {
    $ld = json_encode(['@type' => 'Product', 'name' => 'Ceramic Mug', 'sku' => 'MUG-1',
        'offers' => ['price' => '29.00', 'priceCurrency' => 'AUD']]);
    $html = '<html><head><script type="application/ld+json">'.$ld.'</script></head></html>';

    $out = genericScraperAt($html, 'https://shop.example/products/ceramic-mug')
        ->readProductPage('https://shop.example/products/ceramic-mug');

    expect($out['outcome'])->toBe(GenericShopScraper::OUTCOME_PRODUCT);
    expect($out['product']['productId'])->toBe('MUG-1');
});

it('reports unreachable when the product page cannot be fetched', function () {
    $out = genericScraperAt('', 'https://x.example/p', 403)->readProductPage('https://x.example/p');

    expect($out['outcome'])->toBe(GenericShopScraper::OUTCOME_UNREACHABLE);
});

it('extracts the real bluelane.co product page as a product (live fixture)', function () {
    // The head surface (title + meta + JSON-LD) of the real product page,
    // captured through the production egress — WS-B1.3 product-add proof.
    $html = shopFixture('bluelane-product-page.html');

    $out = genericScraperAt($html, 'https://bluelane.co/product/lobster-swim-short-pink/')
        ->readProductPage('https://bluelane.co/product/lobster-swim-short-pink/');

    expect($out['outcome'])->toBe(GenericShopScraper::OUTCOME_PRODUCT);
    // The site's own JSON-LD product name (SEO-prefixed), entities decoded.
    expect($out['product']['title'])->toBe('Blue Lane Co • Pink Lobster Swim Short');
    expect($out['product']['price'])->toBe('100.00');
    expect($out['product']['currency'])->toBe('AUD');
    expect($out['product']['url'])->toBe('https://bluelane.co/product/lobster-swim-short-pink/');
});

it('extracts the real fearnoevil.com.au product page as a product (live fixture)', function () {
    $html = shopFixture('fearnoevil-product-page.html');

    $out = genericScraperAt($html, 'https://fearnoevil.com.au/product/bulwark-jacket/')
        ->readProductPage('https://fearnoevil.com.au/product/bulwark-jacket/');

    expect($out['outcome'])->toBe(GenericShopScraper::OUTCOME_PRODUCT);
    expect($out['product']['title'])->toBe('FEAR NO EVIL • Bulwark Jacket');
    expect($out['product']['price'])->toBe('280.00');
    expect($out['product']['available'])->toBeTrue();
});

// ── fetchPageDetailed() discriminators (WS-B1.2) ──────────────────────────────

it('reports reachable + storefront markers on a WooCommerce homepage without JSON-LD products — live fearnoevil fixture', function () {
    $html = shopFixture('fearnoevil-homepage-head.html');

    $out = genericScraperAt($html, 'https://fearnoevil.com.au/')->fetchPageDetailed('https://fearnoevil.com.au');

    expect($out)->toMatchArray(['page' => null, 'reachable' => true, 'storefrontMarkers' => true]);
});

it('reports reachable without storefront markers on a plain website', function () {
    $html = '<html><head><title>My Portfolio</title></head><body><p>Hi, I paint.</p></body></html>';

    $out = genericScraperAt($html, 'https://portfolio.example/')->fetchPageDetailed('https://portfolio.example');

    expect($out)->toMatchArray(['page' => null, 'reachable' => true, 'storefrontMarkers' => false]);
});

it('reports unreachable when the page cannot be fetched at all', function () {
    $out = genericScraperAt('', 'https://blocked.example/', 403)->fetchPageDetailed('https://blocked.example');

    expect($out)->toMatchArray(['page' => null, 'reachable' => false, 'storefrontMarkers' => false]);
});

/** Same as genericScraperWith(), but the page is a site ROOT (a homepage). */
function genericScraperAtRoot(string $html, string $finalUrl = 'https://shop.example/'): GenericShopScraper
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->andReturn([
        'status' => 200, 'body' => $html, 'finalUrl' => $finalUrl, 'contentType' => 'text/html',
    ]);

    return new GenericShopScraper($fetcher);
}

/**
 * N-C (2026-08-18 Instagram wave) — a storefront HOMEPAGE carrying exactly ONE
 * Product node is still a homepage, not a product page. The list guard only
 * fires at count >= 2, so a shop featuring a single item had $products[0]
 * taken and published as though the homepage were that product's page.
 *
 * Premise correction (R7, 2026-08-18): this test was written believing its
 * live case was paytherent.net.au and that the site was WooCommerce. It is
 * neither a shop nor WooCommerce — the live page carries NO storefront marker
 * of any kind, so this guard never fired for it and the fixture below is a
 * synthetic WooCommerce homepage, not that site. The real paytherent case is
 * covered by the name-only tests further down; this one covers a genuine
 * single-product storefront homepage, which is still worth guarding.
 */
it('treats a single-product storefront homepage as a store page, not a product', function () {
    $ld = json_encode([
        '@context' => 'http://schema.org',
        '@type' => 'Product',
        'name' => 'Private: Demo',
        'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => '5', 'reviewCount' => '1'],
    ]);

    $read = genericScraperAtRoot(
        '<html><head><script type="application/ld+json">'.$ld.'</script></head>'
        .'<body class="home woocommerce-page"><link href="/wp-content/plugins/woocommerce/assets/x.css"></body></html>'
    )->readProductPage('https://shop.example/');

    expect($read['outcome'])->toBe(GenericShopScraper::OUTCOME_STORE_PAGE)
        ->and($read['product'])->toBeNull()
        ->and($read['storeUrl'])->toBe('https://shop.example');
});

/**
 * The guard must be keyed on the page being a storefront ROOT, not on "any
 * root". A single-product node on a root URL with no storefront tech markers
 * still yields the product — that path is unchanged.
 */
it('still extracts a product from a root url that carries no storefront markers', function () {
    $ld = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Handmade Print',
        'offers' => ['@type' => 'Offer', 'price' => '40.00', 'priceCurrency' => 'AUD'],
    ]);

    $read = genericScraperAtRoot(
        '<html><head><script type="application/ld+json">'.$ld.'</script></head><body>a plain page</body></html>'
    )->readProductPage('https://artist.example/');

    expect($read['outcome'])->toBe(GenericShopScraper::OUTCOME_PRODUCT)
        ->and($read['product']['title'])->toBe('Handmade Print');
});

/**
 * A DEEP product URL on a WooCommerce site is the normal, valuable case and
 * must keep working — the guard is about homepages only.
 */
it('still extracts a product from a deep url on a storefront', function () {
    $ld = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Ceramic Mug',
        'offers' => ['@type' => 'Offer', 'price' => '29.00', 'priceCurrency' => 'AUD'],
    ]);

    $read = genericScraperWith(
        '<html><head><script type="application/ld+json">'.$ld.'</script></head>'
        .'<body class="woocommerce"><link href="/wp-content/plugins/woocommerce/assets/x.css"></body></html>'
    )->readProductPage('https://shop.example/store');

    expect($read['outcome'])->toBe(GenericShopScraper::OUTCOME_PRODUCT)
        ->and($read['product']['title'])->toBe('Ceramic Mug');
});

/**
 * R7 (2026-08-18 Instagram wave) — a schema.org Product node carrying NOTHING
 * but a name is not a product.
 *
 * Live case: paytherent.net.au/ is a rent-payment campaign site, not a shop.
 * Its entire JSON-LD is one node emitted by a WordPress reviews plugin, which
 * wraps its ratings in a dummy Product purely because rich results require a
 * rating to hang off a Product-ish type:
 *
 *   {"@type":"Product","name":"Private: Demo",
 *    "aggregateRating":{"ratingValue":"5","reviewCount":"3"},"review":[]}
 *
 * No offers, no price, no sku, no image, no url. The extractor asked only for
 * a non-empty `name`, so the plugin's demo row became a `product` item on a
 * real user's site (crucibletattooco, 2026-08-18).
 *
 * The rule mirrors productFromOpenGraph()'s existing one (og:title alone is
 * ANY webpage): a node needs a deterministic commerce signal, not just a name.
 */
$ratingWidgetOnlyHtml = '<html><head><title>Pay The Rent</title>'
    .'<script type="application/ld+json">'.json_encode([
        '@context' => 'http://schema.org',
        '@type' => 'Product',
        'name' => 'Private: Demo',
        'aggregateRating' => ['@type' => 'AggregateRating', 'bestRating' => '5', 'ratingValue' => '5', 'worstRating' => '1', 'reviewCount' => '3'],
        'review' => [],
    ]).'</script></head><body>Saying sorry is not enough</body></html>';

it('does not read a name-only Product node as a product', function () use ($ratingWidgetOnlyHtml) {
    $read = genericScraperAtRoot($ratingWidgetOnlyHtml)->readProductPage('https://paytherent.example/');

    expect($read['outcome'])->toBe(GenericShopScraper::OUTCOME_NO_PRODUCT);
});

it('returns no product payload for a name-only Product node', function () use ($ratingWidgetOnlyHtml) {
    $read = genericScraperAtRoot($ratingWidgetOnlyHtml)->readProductPage('https://paytherent.example/');

    expect($read['product'])->toBeNull();
});

/** The same node must not make the site a storefront for the brand-connect path either. */
it('does not treat a name-only Product node as a shop page', function () use ($ratingWidgetOnlyHtml) {
    $page = genericScraperAtRoot($ratingWidgetOnlyHtml)->fetchPage('https://paytherent.example/');

    expect($page)->toBeNull();
});

/**
 * Non-vacuity: the filter drops the rating carrier and keeps the real product
 * on a page that carries both — it is not "reject everything on this page".
 */
it('keeps real products alongside a rating-carrier node', function () {
    $ld = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'itemListElement' => [
            ['@type' => 'Product', 'name' => 'Private: Demo', 'aggregateRating' => ['ratingValue' => '5', 'reviewCount' => '3']],
            ['@type' => 'Product', 'name' => 'Ceramic Mug', 'sku' => 'MUG-1', 'offers' => ['price' => '29.00', 'priceCurrency' => 'AUD']],
        ],
    ]);

    $page = genericScraperWith('<html><head><script type="application/ld+json">'.$ld.'</script></head><body></body></html>')
        ->fetchPage('https://shop.example/store');

    expect(array_column($page['products'], 'title'))->toBe(['Ceramic Mug']);
});

/** An image is signal enough — the rule is not "must carry offers". */
it('accepts a Product node whose only commerce signal is an image', function () {
    $ld = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Untitled Study',
        'image' => 'https://shop.example/img/study.jpg',
    ]);

    $read = genericScraperWith('<html><head><script type="application/ld+json">'.$ld.'</script></head><body></body></html>')
        ->readProductPage('https://shop.example/store');

    expect($read['outcome'])->toBe(GenericShopScraper::OUTCOME_PRODUCT);
});

/** ...and so is a product URL distinct from the page it was read off. */
it('accepts a Product node whose only commerce signal is its own url', function () {
    $ld = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Untitled Study',
        'url' => 'https://shop.example/products/untitled-study',
    ]);

    $read = genericScraperWith('<html><head><script type="application/ld+json">'.$ld.'</script></head><body></body></html>')
        ->readProductPage('https://shop.example/store');

    expect($read['outcome'])->toBe(GenericShopScraper::OUTCOME_PRODUCT);
});

/**
 * N3 (2026-08-11 Instagram wave, remaining half) — R7 closed the JSON-LD door
 * and left the OpenGraph one open on the same rule it had just disowned.
 *
 * `@type":"Product"` is self-declared by whatever plugin wrote the markup —
 * that was R7's finding. `og:type=product` is self-declared by the same class
 * of plugin, and productFromOpenGraph() accepted it as sufficient on its own:
 * the gate was `og:type contains product OR a price meta exists`, so a page
 * with a product-ish og:type, no price, no image and no description passed.
 *
 * Two separable guards, per the finding: substance (a title is not a product)
 * and placeholder titles (WordPress prefixes `Private:`/`Protected:` on
 * non-published posts — the literal "Private: Demo" that landed on
 * crucibletattooco).
 */
it('does not read an og:type=product page carrying neither price nor image as a product', function () {
    $html = '<html><head><meta property="og:type" content="product">'
        .'<meta property="og:title" content="Demo"></head><body></body></html>';

    $out = genericScraperAt($html, 'https://paytherent.example/shop/demo')
        ->readProductPage('https://paytherent.example/shop/demo');

    expect($out['outcome'])->toBe(GenericShopScraper::OUTCOME_NO_PRODUCT);
});

it('returns no product payload for an og:type=product page carrying neither price nor image', function () {
    $html = '<html><head><meta property="og:type" content="product">'
        .'<meta property="og:title" content="Demo"></head><body></body></html>';

    $out = genericScraperAt($html, 'https://paytherent.example/shop/demo')
        ->readProductPage('https://paytherent.example/shop/demo');

    expect($out['product'])->toBeNull();
});

/** WordPress prefixes a non-published post's title. Substance alone won't catch it. */
it('does not read a Private:-prefixed OpenGraph title as a product', function () {
    $html = '<html><head><meta property="og:type" content="product">'
        .'<meta property="og:title" content="Private: Demo">'
        .'<meta property="og:image" content="/img/demo.jpg"></head></html>';

    $out = genericScraperAt($html, 'https://paytherent.example/shop/demo')
        ->readProductPage('https://paytherent.example/shop/demo');

    expect($out['outcome'])->toBe(GenericShopScraper::OUTCOME_NO_PRODUCT);
});

it('does not read a Protected:-prefixed OpenGraph title as a product', function () {
    $html = '<html><head><meta property="og:title" content="Protected: Members Only">'
        .'<meta property="product:price:amount" content="49.00"></head></html>';

    $out = genericScraperAt($html, 'https://store.example/p/members')
        ->readProductPage('https://store.example/p/members');

    expect($out['outcome'])->toBe(GenericShopScraper::OUTCOME_NO_PRODUCT);
});

/** The same rule must hold at the JSON-LD door, which R7 left title-blind. */
it('does not read a Private:-prefixed JSON-LD Product node as a product', function () {
    $ld = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'Product',
        'name' => 'Private: Demo',
        'sku' => 'DEMO-1',
        'offers' => ['price' => '10.00', 'priceCurrency' => 'AUD'],
    ]);

    $read = genericScraperAt(
        '<html><head><script type="application/ld+json">'.$ld.'</script></head></html>',
        'https://paytherent.example/shop/demo',
    )->readProductPage('https://paytherent.example/shop/demo');

    expect($read['outcome'])->toBe(GenericShopScraper::OUTCOME_NO_PRODUCT);
});

/** Non-vacuity: "draft" as a WORD in a real title is not a placeholder. */
it('still reads a product whose title merely contains the word draft', function () {
    $html = '<html><head><meta property="og:type" content="product">'
        .'<meta property="og:title" content="Draft Beer Glass">'
        .'<meta property="og:image" content="/img/glass.jpg"></head></html>';

    $out = genericScraperAt($html, 'https://store.example/p/draft-beer-glass')
        ->readProductPage('https://store.example/p/draft-beer-glass');

    expect($out['outcome'])->toBe(GenericShopScraper::OUTCOME_PRODUCT);
    expect($out['product']['title'])->toBe('Draft Beer Glass');
});

/** Non-vacuity: the substance gate accepts an image with no price at all. */
it('still reads an og:type=product page whose only substance is an image', function () {
    $html = '<html><head><meta property="og:type" content="product">'
        .'<meta property="og:title" content="Bulwark Jacket">'
        .'<meta property="og:image" content="/img/jacket.jpg"></head></html>';

    $out = genericScraperAt($html, 'https://store.example/p/bulwark')
        ->readProductPage('https://store.example/p/bulwark');

    expect($out['outcome'])->toBe(GenericShopScraper::OUTCOME_PRODUCT);
});

/** A WordPress draft with an empty title renders the bare prefix — still a placeholder. */
it('does not read a bare Private: title as a product', function () {
    $html = '<html><head><meta property="og:type" content="product">'
        .'<meta property="og:title" content="Private:">'
        .'<meta property="og:image" content="/img/demo.jpg"></head></html>';

    $out = genericScraperAt($html, 'https://paytherent.example/shop/demo')
        ->readProductPage('https://paytherent.example/shop/demo');

    expect($out['outcome'])->toBe(GenericShopScraper::OUTCOME_NO_PRODUCT);
});
