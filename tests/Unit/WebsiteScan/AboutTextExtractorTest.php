<?php

use App\Services\WebsiteScan\AboutTextExtractor;

it('extracts description from LocalBusiness JSON-LD', function () {
    $html = <<<'HTML'
    <script type="application/ld+json">
    {"@context": "https://schema.org", "@type": "LocalBusiness", "name": "Doc Pizza", "description": "Wood-fired pizza since 1985."}
    </script>
    HTML;

    expect(app(AboutTextExtractor::class)->extract($html, 'https://venue.example'))->toBe('Wood-fired pizza since 1985.');
});

it('extracts description from Organization JSON-LD when LocalBusiness is absent', function () {
    $html = <<<'HTML'
    <script type="application/ld+json">
    {"@type": "Organization", "description": "A creative studio."}
    </script>
    HTML;

    expect(app(AboutTextExtractor::class)->extract($html, 'https://venue.example'))->toBe('A creative studio.');
});

it('falls back to meta description when no JSON-LD description is present', function () {
    $html = '<meta name="description" content="Fresh coffee, roasted daily.">';
    expect(app(AboutTextExtractor::class)->extract($html, 'https://venue.example'))->toBe('Fresh coffee, roasted daily.');
});

it('prefers JSON-LD description over meta description when both are present', function () {
    $html = '<meta name="description" content="Meta version.">'
        .'<script type="application/ld+json">{"@type": "LocalBusiness", "description": "JSON-LD version."}</script>';

    expect(app(AboutTextExtractor::class)->extract($html, 'https://venue.example'))->toBe('JSON-LD version.');
});

it('returns null when neither source has a description', function () {
    expect(app(AboutTextExtractor::class)->extract('<html><body>Hello</body></html>', 'https://venue.example'))->toBeNull();
});

it('returns null for an empty or whitespace-only meta description', function () {
    $html = '<meta name="description" content="   ">';
    expect(app(AboutTextExtractor::class)->extract($html, 'https://venue.example'))->toBeNull();
});

it('trims whitespace from the extracted text', function () {
    $html = '<meta name="description" content="  Padded text.  ">';
    expect(app(AboutTextExtractor::class)->extract($html, 'https://venue.example'))->toBe('Padded text.');
});

it('decodes HTML entities left over from a JSON-LD block that double-escaped its own text', function () {
    // Reproduced live 2026-07-20 (errols.com.au): the site's own JSON-LD
    // literally contains "&amp;" as text inside the description string —
    // <script> content isn't HTML-entity-decoded by the DOM parser, so
    // json_decode() faithfully preserves it. Left undecoded, this would show
    // up verbatim ("Restaurant &amp; Bar") on the user's live site.
    $html = <<<'HTML'
    <script type="application/ld+json">
    {"@type": "LocalBusiness", "description": "Restaurant &amp; Bar, North Melbourne"}
    </script>
    HTML;

    expect(app(AboutTextExtractor::class)->extract($html, 'https://venue.example'))
        ->toBe('Restaurant & Bar, North Melbourne');
});
