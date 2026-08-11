<?php

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\WebsiteLinkHarvester;
use Tests\TestCase;

// Boots the app because classify() now falls back to the compiled catalog for
// hosts its own constants do not cover, and CompiledCatalog::path() resolves
// through base_path(). Same reason and same idiom as RoutingCorpusTest — still
// DB-free, still sub-second.
uses(TestCase::class)->in(__FILE__);

function harvesterFor(string $html): WebsiteLinkHarvester
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->andReturn([
        'status' => 200,
        'body' => $html,
        'finalUrl' => 'https://example.com.au/',
    ]);

    return new WebsiteLinkHarvester($fetcher);
}

it('classifies socials, reservations, ordering and booking links off a homepage', function () {
    $html = <<<'HTML'
    <html><body>
      <a href="https://www.instagram.com/docpizza">IG</a>
      <a href="https://www.facebook.com/docpizzabar">FB</a>
      <a href="https://www.facebook.com/sharer/sharer.php?u=x">share widget</a>
      <a href="https://www.opentable.com.au/r/doc-pizza?rid=12345">Book a table</a>
      <a href="https://www.ubereats.com/au/store/doc-pizza/abc">Order</a>
      <a href="https://www.fresha.com/a/doc-cuts">Book a cut</a>
      <a href="/menu">Menu</a>
      <a href="mailto:hi@example.com">Email</a>
    </body></html>
    HTML;

    $out = harvesterFor($html)->harvest('https://example.com.au');

    expect($out['socials']['instagram'])->toBe('https://www.instagram.com/docpizza')
        ->and($out['socials']['facebook'])->toBe('https://www.facebook.com/docpizzabar')
        ->and($out['reservation']['links'][0]['url'])->toContain('opentable.com.au')
        ->and($out['order']['providers'][0]['name'])->toBe('Uber Eats')
        ->and($out['booking'][0])->toContain('fresha.com');
});

it('ignores share widgets and bare social domains', function () {
    $html = '<a href="https://twitter.com/intent/tweet?x=1">t</a><a href="https://instagram.com/">bare</a>';

    expect(harvesterFor($html)->harvest('https://example.com.au'))->toBe([]);
});

it('returns nothing for a missing or non-http website', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $harvester = new WebsiteLinkHarvester($fetcher);

    expect($harvester->harvest(null))->toBe([])
        ->and($harvester->harvest('not-a-url'))->toBe([]);
});

// ── A3.1: harvestHtml() / allOutboundLinks() — fetch split from parse ────────

it('harvests from a raw html string the same way harvest() does from a url', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $harvester = new WebsiteLinkHarvester($fetcher);

    $html = '<a href="https://www.fresha.com/a/venue-1">Book</a>';
    $out = $harvester->harvestHtml($html, 'https://venue.example');

    expect($out['booking'][0])->toContain('fresha.com');
});

it('allOutboundLinks returns every absolute outbound link, not just categorized ones', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $harvester = new WebsiteLinkHarvester($fetcher);

    $html = '<a href="https://www.fresha.com/a/venue-1">Book</a><a href="https://someblog.example">Blog</a><a href="#anchor">skip</a>';
    $links = $harvester->allOutboundLinks($html, 'https://venue.example');

    expect($links)->toContain('https://www.fresha.com/a/venue-1', 'https://someblog.example');
    expect($links)->toHaveCount(2);
});

it('allOutboundLinks resolves relative hrefs against the given base url', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $harvester = new WebsiteLinkHarvester($fetcher);

    $links = $harvester->allOutboundLinks('<a href="/menu">Menu</a>', 'https://venue.example');

    expect($links)->toBe(['https://venue.example/menu']);
});

it('harvest() still delegates through harvestHtml() with byte-identical behavior (regression)', function () {
    $html = '<a href="https://www.instagram.com/docpizza">IG</a><a href="https://www.fresha.com/a/doc-cuts">Book</a>';
    $out = harvesterFor($html)->harvest('https://example.com.au');

    expect($out['socials']['instagram'])->toBe('https://www.instagram.com/docpizza');
    expect($out['booking'][0])->toContain('fresha.com');
});

// ── classify() — single-URL host classification (BE2: reused by InstagramAutoSync) ──

function classifierHarvester(): WebsiteLinkHarvester
{
    return new WebsiteLinkHarvester(Mockery::mock(SafeUrlFetcher::class));
}

it('classifies each known social host to its platform + label', function (string $url, string $platform, string $label) {
    $out = classifierHarvester()->classify($url);

    expect($out)->toBe(['platform' => $platform, 'category' => 'social', 'label' => $label]);
})->with([
    ['https://www.instagram.com/acme', 'instagram', 'Instagram'],
    ['https://www.facebook.com/acme', 'facebook', 'Facebook'],
    ['https://www.tiktok.com/@acme', 'tiktok', 'TikTok'],
    ['https://twitter.com/acme', 'x', 'X'],
    ['https://x.com/acme', 'x', 'X'],
    ['https://www.linkedin.com/in/acme', 'linkedin', 'LinkedIn'],
    ['https://www.youtube.com/@acme', 'youtube', 'YouTube'],
]);

it('classifies booking hosts to their specific provider platform', function (string $url, string $platform, string $label) {
    expect(classifierHarvester()->classify($url))->toBe(['platform' => $platform, 'category' => 'booking', 'label' => $label]);
})->with([
    ['https://www.fresha.com/a/doc-cuts', 'fresha', 'Fresha'],
    ['https://squareup.com/book/acme', 'square', 'Square'],
    ['https://acme.square.site', 'square', 'Square'],
]);

it('classifies reservation hosts to their specific provider platform', function (string $url, string $platform, string $label) {
    expect(classifierHarvester()->classify($url))->toBe(['platform' => $platform, 'category' => 'reservations', 'label' => $label]);
})->with([
    ['https://www.opentable.com.au/r/doc-pizza', 'opentable', 'OpenTable'],
    ['https://www.resdiary.com/restaurant/doc', 'resdiary', 'ResDiary'],
    ['https://acme.nowbookit.com', 'nowbookit', 'NowBookit'],
]);

it('classifies ordering hosts to the generic online-ordering platform, keeping the provider as the label', function () {
    expect(classifierHarvester()->classify('https://www.ubereats.com/au/store/doc-pizza'))
        ->toBe(['platform' => 'online-ordering', 'category' => 'online-ordering', 'label' => 'Uber Eats']);
    expect(classifierHarvester()->classify('https://www.doordash.com/store/doc-pizza'))
        ->toBe(['platform' => 'online-ordering', 'category' => 'online-ordering', 'label' => 'DoorDash']);
    // Found live 2026-07-20 directly on a real AU restaurant's homepage
    // (errols.com.au's "Order Now" button) — a real AU/NZ ordering platform
    // this list didn't cover yet.
    expect(classifierHarvester()->classify('https://ordermate.online/errols/menu'))
        ->toBe(['platform' => 'online-ordering', 'category' => 'online-ordering', 'label' => 'OrderMate']);
});

it('returns null for an unrecognised host', function () {
    expect(classifierHarvester()->classify('https://linktr.ee/acme'))->toBeNull();
    expect(classifierHarvester()->classify('https://www.acme-personal-site.example'))->toBeNull();
});

it('returns null for a bare social domain or a share/intent widget link', function () {
    expect(classifierHarvester()->classify('https://instagram.com/'))->toBeNull();
    expect(classifierHarvester()->classify('https://www.facebook.com/sharer/sharer.php?u=x'))->toBeNull();
    expect(classifierHarvester()->classify('https://twitter.com/intent/tweet?text=hi'))->toBeNull();
});

it('returns null for a malformed or non-http url', function () {
    expect(classifierHarvester()->classify('not-a-url'))->toBeNull();
    expect(classifierHarvester()->classify('javascript:alert(1)'))->toBeNull();
    expect(classifierHarvester()->classify(''))->toBeNull();
});

// ── classify(): event / event-organiser / shop (signup-v2 C1) ────────────────

it('classifies event-organiser and event urls for both event platforms', function (string $url, string $platform, string $category) {
    $out = classifierHarvester()->classify($url);

    expect($out['platform'])->toBe($platform);
    expect($out['category'])->toBe($category);
})->with([
    ['https://www.eventbrite.com.au/o/melbourne-food-collective-1234', 'eventbrite', 'event-organiser'],
    ['https://www.eventbrite.com/e/winter-tasting-tickets-99887', 'eventbrite', 'event'],
    ['https://events.humanitix.com/host/supper-club', 'humanitix', 'event-organiser'],
    ['https://events.humanitix.com/winter-supper-2026', 'humanitix', 'event'],
]);

it('pins humanitix org-before-event ordering (shared host, /host/ discriminates)', function () {
    // If the event check ran first, /host/ pages would classify as events —
    // NON_EVENT_SLUGS guards it scraper-side, but the ordering must hold too.
    $out = classifierHarvester()->classify('https://events.humanitix.com/host/supper-club');

    expect($out['category'])->toBe('event-organiser');
});

it('classifies decisive store hosts as shop', function (string $url, string $label) {
    $out = classifierHarvester()->classify($url);

    expect($out)->toBe(['platform' => 'shop', 'category' => 'shop', 'label' => $label]);
})->with([
    ['https://acme-goods.myshopify.com/', 'Shopify'],
    ['https://acmegoods.bigcartel.com/products', 'Big Cartel'],
]);

it('classifies marketplace and affiliate hosts as link, never shop', function (string $url, string $platform, string $label) {
    // category 'link' is what keeps these off the probe budget — 'shop' would
    // still spend one (LinkRouter::seedShop), which is the bug this replaced.
    $out = classifierHarvester()->classify($url);

    expect($out)->toBe(['platform' => $platform, 'category' => 'link', 'label' => $label]);
})->with([
    ['https://www.liketoknow.it/creator', 'ltk', 'LTK'],
    ['https://shopltk.com/explore/creator', 'ltk', 'LTK'],
    ['https://www.amazon.com/shop/creator', 'amazon', 'Amazon'],
    ['https://www.amazon.com.au/dp/B01', 'amazon', 'Amazon'],
    ['https://amazon.co.uk/dp/B01', 'amazon', 'Amazon'],
    ['https://amzn.to/3abcDEF', 'amazon', 'Amazon'],
    ['https://poshmark.com/closet/creator', 'poshmark', 'Poshmark'],
    ['https://shopmy.us/collections/12345', 'shopmy', 'ShopMy'],
    ['https://au.pinterest.com/creator', 'pinterest', 'Pinterest'],
    ['https://pin.it/abc123', 'pinterest', 'Pinterest'],
]);

it('leaves maker marketplaces unclassified so their listings keep a probe', function (string $url) {
    // Deliberate boundary, not an oversight: the unclassified arm runs
    // GenericShopScraper::readProductPage(), which reads schema.org product
    // markup off ANY page — so an Etsy listing can become a real product card,
    // and for a maker that is the most valuable thing on their page.
    expect(classifierHarvester()->classify($url))->toBeNull();
})->with([
    'https://www.etsy.com/listing/12345/hand-thrown-mug',
    'https://www.depop.com/products/creator-jacket',
]);

it('keeps the two link-only maps in lockstep', function () {
    // Parallel hand-maintained maps: a label in one and not the other yields
    // 'platform' => null out of classify(), violating its own array shape.
    $consts = (new ReflectionClass(WebsiteLinkHarvester::class))->getConstants();

    expect(array_keys($consts['LINK_ONLY_PLATFORM']))->toBe(array_keys($consts['LINK_ONLY_HOSTS']));
});

it('does not let a marketplace pattern swallow an unrelated host', function (string $url) {
    // The (^|\.) prefix and the $ anchor are load-bearing: without them a
    // business's own domain would be misread as a marketplace and lose the
    // probe that could have found its storefront.
    expect(classifierHarvester()->classify($url))->toBeNull();
})->with([
    'https://notamazon.com/',
    'https://amazon.evilproxy.example/',
    'https://mypinterestagency.com.au/',
    'https://etsy.com.evil.example/',
]);

it('keeps square.site classified as booking, never shop (pinned ambiguity)', function () {
    $out = classifierHarvester()->classify('https://acme.square.site/');

    expect($out['category'])->toBe('booking');
    expect($out['platform'])->toBe('square');
});

it('still returns null for a plain unclassifiable website', function () {
    expect(classifierHarvester()->classify('https://acme-restaurant.example'))->toBeNull();
});
