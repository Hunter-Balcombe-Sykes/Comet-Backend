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

it('resolves a protocol-relative icon href against the base scheme', function () {
    $html = '<link rel="icon" href="//cdn.example.com/fav.png">';
    $fetcher = faviconFetcherReturning('https://cdn.example.com/fav.png', 'bytes');

    $result = $fetcher->fetch($html, 'https://venue.example');

    expect($result['url'])->toBe('https://cdn.example.com/fav.png');
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

// ── #FU-1: LIBXML_NONET on an untrusted parse ────────────────────────────────

it('still finds an icon link on a page carrying an external DOCTYPE and entity declarations', function () {
    // LIBXML_NONET's whole risk is that it changes how a page with external
    // references parses. Pins that adding it costs no icon lookup on the
    // exact page shape that would exercise it — same DOCTYPE+entity fixture
    // as WebsiteLinkHarvesterTest's #W1-SEC-13 regression test.
    $html = <<<'HTML'
    <!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"
      "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd" [
      <!ENTITY probe SYSTEM "http://169.254.169.254/latest/meta-data/">
    ]>
    <html><head><link rel="apple-touch-icon" href="/apple-touch.png"></head><body></body></html>
    HTML;
    $fetcher = faviconFetcherReturning('https://venue.example/apple-touch.png', 'fake-png-bytes');

    $result = $fetcher->fetch($html, 'https://venue.example');

    expect($result['url'])->toBe('https://venue.example/apple-touch.png');
});
