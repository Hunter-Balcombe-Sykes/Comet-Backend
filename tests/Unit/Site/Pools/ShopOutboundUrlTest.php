<?php

use App\Site\Pools\ShopOutboundUrl;

function store(string $provider, string $url = 'https://store.example.com', string $discount = '', string $referral = ''): object
{
    return (object) [
        'provider' => $provider, 'url' => $url,
        'discount_code' => $discount === '' ? null : $discount,
        'referral_query' => $referral,
    ];
}

$bare = 'https://store.example.com/products/hat';

it('returns the bare URL in product mode regardless of provider', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'product', store('shopify'), '123'))->toBe($bare)
        ->and(ShopOutboundUrl::compose($bare, 'product', store('woocommerce'), '123'))->toBe($bare);
});

it('builds the Shopify cart deep link in checkout mode', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'checkout', store('shopify'), '44073715368070'))
        ->toBe('https://store.example.com/cart/44073715368070:1');
});

it('trims a trailing slash off the store URL before the cart path', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'checkout', store('shopify', 'https://store.example.com/'), '123'))
        ->toBe('https://store.example.com/cart/123:1');
});

it('builds the WooCommerce add-to-cart link on the product URL', function () {
    $wooBare = 'https://fearnoevil.com.au/product/bulwark-jacket/';
    expect(ShopOutboundUrl::compose($wooBare, 'checkout', store('woocommerce'), '2595'))
        ->toBe('https://fearnoevil.com.au/product/bulwark-jacket/?add-to-cart=2595');
});

it('falls back to the bare URL when the variant ref is missing', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'checkout', store('shopify'), null))->toBe($bare)
        ->and(ShopOutboundUrl::compose($bare, 'checkout', store('woocommerce'), ''))->toBe($bare);
});

it('falls back to the bare URL for providers with no known deep link', function () use ($bare) {
    foreach (['squarespace', 'bigcartel', 'generic'] as $provider) {
        expect(ShopOutboundUrl::compose($bare, 'checkout', store($provider), '123'))->toBe($bare);
    }
});

it('falls back to the bare URL when there is no storefront at all', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'checkout', null, '123'))->toBe($bare);
});

it('appends the discount code with ? on a URL carrying no query', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'checkout', store('shopify', discount: 'ALEX10'), '123'))
        ->toBe('https://store.example.com/cart/123:1?discount=ALEX10');
});

it('appends the referral query, which is a whole key=value pair', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'checkout', store('shopify', referral: 'ref=abc'), '123'))
        ->toBe('https://store.example.com/cart/123:1?ref=abc');
});

it('joins discount and referral with & in that order', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'checkout', store('shopify', discount: 'ALEX10', referral: 'ref=abc'), '123'))
        ->toBe('https://store.example.com/cart/123:1?discount=ALEX10&ref=abc');
});

// No dev product URL carries a query string (0 of 51), so this case exists
// only here.
it('uses & when the base URL already carries a query', function () {
    $withQuery = 'https://store.example.com/products/hat?variant=9';
    expect(ShopOutboundUrl::compose($withQuery, 'product', store('shopify', referral: 'ref=abc'), '123'))
        ->toBe('https://store.example.com/products/hat?variant=9&ref=abc');
});

// referral_query is affiliate attribution, not a checkout artefact. Omitting
// it in product mode would drop revenue on every non-checkout site.
it('appends the referral query in product mode too', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'product', store('shopify', referral: 'partner=xyz'), '123'))
        ->toBe('https://store.example.com/products/hat?partner=xyz');
});

it('emits no empty params when discount and referral are absent', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'checkout', store('shopify'), '123'))
        ->not->toContain('?')
        ->and(ShopOutboundUrl::compose($bare, 'checkout', store('shopify'), '123'))
        ->toBe('https://store.example.com/cart/123:1');
});

it('treats an unknown link mode as checkout, the column default', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, '', store('shopify'), '123'))
        ->toBe('https://store.example.com/cart/123:1');
});

// Fix round 1, finding 1: a query appended after a #fragment lands inside it
// and never reaches the server, silently dropping the discount/referral.
// GenericShopScraper sources og:url / JSON-LD offers.url unstripped, so a
// fragment-bearing product URL is reachable in production.
it('appends a query param before the URL fragment, not after it', function () {
    expect(ShopOutboundUrl::compose('https://x/hat', 'product', store('shopify', discount: 'A'), '123'))
        ->toBe('https://x/hat?discount=A')
        ->and(ShopOutboundUrl::compose('https://x/hat?v=1', 'product', store('shopify', discount: 'A'), '123'))
        ->toBe('https://x/hat?v=1&discount=A')
        ->and(ShopOutboundUrl::compose('https://x/hat#promo', 'product', store('shopify', discount: 'A'), '123'))
        ->toBe('https://x/hat?discount=A#promo')
        ->and(ShopOutboundUrl::compose('https://x/hat?v=1#promo', 'product', store('shopify', discount: 'A'), '123'))
        ->toBe('https://x/hat?v=1&discount=A#promo');
});

it('keeps discount-then-referral ordering when appending ahead of a fragment', function () {
    expect(ShopOutboundUrl::compose('https://x/hat#promo', 'product', store('shopify', discount: 'A', referral: 'ref=abc'), '123'))
        ->toBe('https://x/hat?discount=A&ref=abc#promo');
});

// Fix round 1, finding 2: content.storefronts.url is nullable, so the
// Shopify arm's empty-storeUrl fallback is a live branch, not dead code.
it('falls back to the bare URL when the shopify store has no url', function () use ($bare) {
    expect(ShopOutboundUrl::compose($bare, 'checkout', store('shopify', ''), '123'))
        ->toBe($bare)
        ->and(ShopOutboundUrl::compose($bare, 'checkout', (object) [
            'provider' => 'shopify', 'url' => null, 'discount_code' => null, 'referral_query' => '',
        ], '123'))
        ->toBe($bare);
});

// Fix round 1, finding 3: discount onto a base URL that already carries a query.
it('appends the discount code with & on a URL carrying an existing query', function () {
    $withQuery = 'https://store.example.com/products/hat?variant=9';
    expect(ShopOutboundUrl::compose($withQuery, 'product', store('shopify', discount: 'ALEX10'), '123'))
        ->toBe('https://store.example.com/products/hat?variant=9&discount=ALEX10');
});
