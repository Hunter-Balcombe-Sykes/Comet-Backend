<?php

use App\Services\WebsiteScan\WebsiteLogoCandidateExtractor;

it('extracts icon, manifest, and og-image candidates from head tags', function () {
    $html = '<link rel="apple-touch-icon" href="/apple.png" sizes="180x180">'
        .'<link rel="manifest" href="/manifest.json">'
        .'<meta property="og:image" content="/share.png">';

    $candidates = app(WebsiteLogoCandidateExtractor::class)->extract($html, 'https://venue.example');

    expect($candidates)->toContain(['kind' => 'apple-touch', 'url' => 'https://venue.example/apple.png', 'sizes' => '180x180', 'type' => '']);
    expect(collect($candidates)->firstWhere('kind', 'manifest')['url'])->toBe('https://venue.example/manifest.json');
    expect(collect($candidates)->firstWhere('kind', 'og-image')['url'])->toBe('https://venue.example/share.png');
});

it('extracts a plain icon candidate distinct from apple-touch', function () {
    $html = '<link rel="icon" href="/favicon.ico" sizes="32x32" type="image/x-icon">';
    $candidates = app(WebsiteLogoCandidateExtractor::class)->extract($html, 'https://venue.example');

    $icon = collect($candidates)->firstWhere('kind', 'icon');
    expect($icon['url'])->toBe('https://venue.example/favicon.ico');
    expect($icon['sizes'])->toBe('32x32');
    expect($icon['type'])->toBe('image/x-icon');
});

it('extracts a twitter:image candidate', function () {
    $html = '<meta name="twitter:image" content="https://cdn.venue.example/card.png">';
    $candidates = app(WebsiteLogoCandidateExtractor::class)->extract($html, 'https://venue.example');

    expect(collect($candidates)->firstWhere('kind', 'twitter-image')['url'])->toBe('https://cdn.venue.example/card.png');
});

it('extracts a header img candidate with a logo hint', function () {
    $html = '<header><img class="site-logo" src="/logo.png" alt="Venue"></header>';
    $candidates = app(WebsiteLogoCandidateExtractor::class)->extract($html, 'https://venue.example');
    $img = collect($candidates)->firstWhere('kind', 'header-img');
    expect($img['url'])->toBe('https://venue.example/logo.png');
    expect($img['hint'])->toBeTrue();
    expect($img['inHeader'])->toBeTrue();
    expect($img['natW'])->toBeNull();
    expect($img['natH'])->toBeNull();
});

it('extracts a header img candidate without a logo hint when nothing suggests it', function () {
    $html = '<header><img src="/hero.png" alt="Hero shot"></header>';
    $candidates = app(WebsiteLogoCandidateExtractor::class)->extract($html, 'https://venue.example');
    $img = collect($candidates)->firstWhere('kind', 'header-img');
    expect($img['hint'])->toBeFalse();
});

it('extracts an inline svg candidate from the header', function () {
    $html = '<header><svg class="logo" viewBox="0 0 100 40"><path d="M0 0"/></svg></header>';
    $candidates = app(WebsiteLogoCandidateExtractor::class)->extract($html, 'https://venue.example');
    $svg = collect($candidates)->firstWhere('kind', 'inline-svg');
    expect($svg['svg'])->toContain('<svg');
    expect($svg['hint'])->toBeTrue();
    expect($svg['viewBox'])->toBe('0 0 100 40');
    expect($svg['w'])->toBeNull();
    expect($svg['h'])->toBeNull();
});

it('falls back to a nav element when there is no header', function () {
    $html = '<nav class="main-nav"><img class="brand-logo" src="/logo.png"></nav>';
    $candidates = app(WebsiteLogoCandidateExtractor::class)->extract($html, 'https://venue.example');
    $img = collect($candidates)->firstWhere('kind', 'header-img');
    expect($img)->not->toBeNull();
    expect($img['inHeader'])->toBeTrue();
});

it('caps output at 16 candidates', function () {
    $html = '<header>'.str_repeat('<img class="logo" src="/l.png">', 30).'</header>';
    expect(app(WebsiteLogoCandidateExtractor::class)->extract($html, 'https://venue.example'))->toHaveCount(16);
});

it('drops an oversized inline svg over the byte cap', function () {
    $bigPath = str_repeat('M0 0 L1 1 ', 10000); // well over 60000 bytes once wrapped
    $html = '<header><svg class="logo" viewBox="0 0 100 40"><path d="'.$bigPath.'"/></svg></header>';
    $candidates = app(WebsiteLogoCandidateExtractor::class)->extract($html, 'https://venue.example');
    expect(collect($candidates)->firstWhere('kind', 'inline-svg'))->toBeNull();
});

it('returns an empty array for html with no candidates at all', function () {
    expect(app(WebsiteLogoCandidateExtractor::class)->extract('<html><body>Hello</body></html>', 'https://venue.example'))->toBe([]);
});

it('ignores a data: uri icon href', function () {
    $html = '<link rel="icon" href="data:image/png;base64,abc123">';
    expect(app(WebsiteLogoCandidateExtractor::class)->extract($html, 'https://venue.example'))->toBe([]);
});

// ── Doc-wide logo-hinted rescue pass (2026-07-23, signup-v2 Phase B live find) ──
// Squarespace-shaped markup: the real branding <img> lives in a div-classed
// .Header-branding block OUTSIDE the semantic <header>, whose only graphics
// are UI icon SVGs. The scoped pass alone missed the logo entirely.

it('collects a logo-hinted img that lives outside the semantic header (squarespace shape)', function () {
    $html = <<<'HTML'
    <header><svg class="Icon Icon--search" viewBox="0 0 20 20"><path d="M0 0"/></svg></header>
    <div class="Header-branding">
        <img src="//images.squarespace-cdn.example/Supernormal_Logo.jpg?format=1500w"
             alt="Supernormal Restaurant | Melbourne" class="Header-branding-logo">
    </div>
    HTML;

    $candidates = app(WebsiteLogoCandidateExtractor::class)->extract($html, 'https://venue.example');
    $img = collect($candidates)->firstWhere('kind', 'header-img');

    expect($img)->not->toBeNull();
    // Protocol-relative src resolved with the base scheme — not mangled into
    // "https://venue.example//images...".
    expect($img['url'])->toBe('https://images.squarespace-cdn.example/Supernormal_Logo.jpg?format=1500w');
    expect($img['hint'])->toBeTrue();
    expect($img['inHeader'])->toBeFalse();
});

it('does not sweep unhinted imgs into the rescue pass', function () {
    $html = '<header><svg class="Icon" viewBox="0 0 20 20"><path d="M0 0"/></svg></header>'
        .'<div><img src="/photos/dish.jpg" alt="A tasty dish"></div>';

    $candidates = app(WebsiteLogoCandidateExtractor::class)->extract($html, 'https://venue.example');

    expect(collect($candidates)->firstWhere('kind', 'header-img'))->toBeNull();
});

it('dedupes the rescue pass against imgs the scoped pass already collected', function () {
    $html = '<header><img class="site-logo" src="/logo.png"></header>'
        .'<footer><img class="footer-logo" src="/logo.png"></footer>';

    $candidates = app(WebsiteLogoCandidateExtractor::class)->extract($html, 'https://venue.example');
    $imgs = collect($candidates)->where('kind', 'header-img')->where('url', 'https://venue.example/logo.png');

    expect($imgs)->toHaveCount(1);
});

it('bounds the rescue pass at six hinted extras', function () {
    $body = '';
    for ($i = 0; $i < 12; $i++) {
        $body .= "<div><img class=\"logo\" src=\"/brand-{$i}.png\"></div>";
    }
    // No <header> imgs at all — every candidate comes from the rescue pass.
    $html = '<header><span>nav only</span></header>'.$body;

    $candidates = app(WebsiteLogoCandidateExtractor::class)->extract($html, 'https://venue.example');

    expect(collect($candidates)->where('kind', 'header-img'))->toHaveCount(6);
});

// ── #FU-1: LIBXML_NONET on an untrusted parse ────────────────────────────────

it('still extracts an icon candidate from a page carrying an external DOCTYPE and entity declarations', function () {
    // LIBXML_NONET's whole risk is that it changes how a page with external
    // references parses. Pins that adding it costs no candidates on the exact
    // page shape that would exercise it — same DOCTYPE+entity fixture as
    // WebsiteLinkHarvesterTest's #W1-SEC-13 regression test.
    $html = <<<'HTML'
    <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
      "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd" [
      <!ENTITY probe SYSTEM "http://169.254.169.254/latest/meta-data/">
    ]>
    <html><head><link rel="apple-touch-icon" href="/apple.png" sizes="180x180"></head><body></body></html>
    HTML;

    $candidates = app(WebsiteLogoCandidateExtractor::class)->extract($html, 'https://venue.example');

    expect(collect($candidates)->firstWhere('kind', 'apple-touch')['url'])->toBe('https://venue.example/apple.png');
});
