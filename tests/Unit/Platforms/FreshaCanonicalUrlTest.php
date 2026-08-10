<?php

use App\Services\Platforms\FreshaScraper;
use Tests\TestCase;

// tests/Unit is NOT bound to TestCase in Pest.php — resolving FreshaScraper
// needs the container, so opt in per-file as the other Unit/Platforms tests do.
uses(TestCase::class)->in(__FILE__);

it('rewrites a book-now url to the canonical /a/ form', function () {
    expect(app(FreshaScraper::class)->canonicalUrl(
        'https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?share=true&pId=2835260'
    ))->toBe('https://www.fresha.com/a/anseo-studio-v0v92jna');
});

it('leaves an already canonical url untouched', function () {
    $url = 'https://www.fresha.com/a/vision-hair-studio-melbourne-520-522-city-road-tzo6gxk0';
    expect(app(FreshaScraper::class)->canonicalUrl($url))->toBe($url);
});

it('passes through a providers-shaped fresha url unchanged', function () {
    // Out of scope by design — asserted so the behaviour is deliberate.
    $url = 'https://www.fresha.com/providers/brother-wolf-bhenueul';
    expect(app(FreshaScraper::class)->canonicalUrl($url))->toBe($url);
});

it('passes through a non-fresha url unchanged', function () {
    $url = 'https://example.com/book-now/whatever/all-offer';
    expect(app(FreshaScraper::class)->canonicalUrl($url))->toBe($url);
});

it('canonicalises a bare-host book-now url', function () {
    // Fresha's share sheet omits www. often enough that dropping it here would
    // strand the slug and force every downstream slugFromUrl() to return null.
    expect(app(FreshaScraper::class)->canonicalUrl('https://fresha.com/book-now/anseo-studio-v0v92jna/all-offer'))
        ->toBe('https://www.fresha.com/a/anseo-studio-v0v92jna');
});

it('produces a url slugFromUrl can actually read', function () {
    // The whole point of canonicalising at write time: the stored URL must be
    // one the employee leg can extract a slug from.
    $scraper = app(FreshaScraper::class);
    $canonical = $scraper->canonicalUrl('https://www.fresha.com/book-now/anseo-studio-v0v92jna/all-offer?share=true');

    expect($scraper->slugFromUrl($canonical))->toBe('anseo-studio-v0v92jna');
});
