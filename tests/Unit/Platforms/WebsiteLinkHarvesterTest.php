<?php

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\WebsiteLinkHarvester;

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
    ['https://www.pinterest.com/acme', 'pinterest', 'Pinterest'],
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
