<?php

use App\Services\Http\SafeUrlFetcher;
use App\Services\WebsiteScan\FaviconFetcher;

// SafeUrlFetcher::assertSafe() does a REAL DNS lookup before the fetch, which
// runs even under Http::fake() and fails for a non-resolving domain like
// venue.example — so these tests mock SafeUrlFetcher directly (mirrors
// WebsiteLinkHarvesterTest's harvesterFor() pattern) rather than faking Http.

function faviconFetcherReturning(string $expectedUrl, string $bytes): FaviconFetcher
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')
        ->once()
        ->with($expectedUrl)
        ->andReturn(['status' => 200, 'body' => $bytes, 'finalUrl' => $expectedUrl]);

    return new FaviconFetcher($fetcher);
}

it('finds and fetches an apple-touch icon in preference to a generic icon', function () {
    $html = '<link rel="icon" href="/favicon.ico"><link rel="apple-touch-icon" href="/apple-touch.png">';
    $fetcher = faviconFetcherReturning('https://venue.example/apple-touch.png', 'fake-png-bytes');

    $result = $fetcher->fetch($html, 'https://venue.example');

    expect($result['url'])->toBe('https://venue.example/apple-touch.png');
    expect($result['bytes'])->toBe('fake-png-bytes');
});

it('falls back to /favicon.ico when no link tag is present', function () {
    $fetcher = faviconFetcherReturning('https://venue.example/favicon.ico', 'ico-bytes');

    $result = $fetcher->fetch('<html></html>', 'https://venue.example');

    expect($result['url'])->toBe('https://venue.example/favicon.ico');
    expect($result['bytes'])->toBe('ico-bytes');
});

it('returns null when the fetch fails', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->once()->andReturn(null);

    expect((new FaviconFetcher($fetcher))->fetch('<html></html>', 'https://venue.example'))->toBeNull();
});

it('resolves a relative icon href against the origin root', function () {
    $html = '<link rel="icon" href="assets/favicon.png">';
    $fetcher = faviconFetcherReturning('https://venue.example/assets/favicon.png', 'bytes');

    $result = $fetcher->fetch($html, 'https://venue.example/some/page');

    expect($result['url'])->toBe('https://venue.example/assets/favicon.png');
});

it('ignores a data: uri icon href and falls back to /favicon.ico', function () {
    $html = '<link rel="icon" href="data:image/png;base64,abc123">';
    $fetcher = faviconFetcherReturning('https://venue.example/favicon.ico', 'ico-bytes');

    $result = $fetcher->fetch($html, 'https://venue.example');

    expect($result['url'])->toBe('https://venue.example/favicon.ico');
});

it('rejects a favicon over the byte cap', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->once()->andReturn([
        'status' => 200, 'body' => str_repeat('x', 2_000_001), 'finalUrl' => 'https://venue.example/favicon.ico',
    ]);

    expect((new FaviconFetcher($fetcher))->fetch('<html></html>', 'https://venue.example'))->toBeNull();
});

it('rejects an empty favicon body', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->once()->andReturn(['status' => 200, 'body' => '', 'finalUrl' => 'https://venue.example/favicon.ico']);

    expect((new FaviconFetcher($fetcher))->fetch('<html></html>', 'https://venue.example'))->toBeNull();
});
