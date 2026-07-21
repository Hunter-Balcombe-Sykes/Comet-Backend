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
