<?php

use App\Services\Platforms\HumanitixScraper;
use App\Services\SmartLinks\SafeUrlFetcher;

afterEach(function () {
    Mockery::close();
});

it('resolves host URLs directly without fetching', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldNotReceive('tryFetch');
    $scraper = new HumanitixScraper($fetcher);

    expect($scraper->resolveHostUrl('https://events.humanitix.com/host/Sleepless-Festival?x=1'))
        ->toBe('https://events.humanitix.com/host/sleepless-festival');
    expect($scraper->resolveHostUrl('https://humanitix.com/host/acme'))
        ->toBe('https://events.humanitix.com/host/acme');
    expect($scraper->resolveHostUrl('https://example.com/host/acme'))->toBeNull();
});

it('resolves an event URL to its host page by reading the event page', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')
        ->with('https://events.humanitix.com/some-festival-2026', Mockery::any())
        ->andReturn(['status' => 200, 'body' => '<a href="/host/some-festival">Host</a>', 'finalUrl' => 'x', 'contentType' => 'text/html']);
    $scraper = new HumanitixScraper($fetcher);

    expect($scraper->resolveHostUrl('https://events.humanitix.com/some-festival-2026'))
        ->toBe('https://events.humanitix.com/host/some-festival');
});

// Fast path: the host page embeds the events' JSON-LD directly — no
// per-event fetches needed.
it('parses events straight off the host page JSON-LD', function () {
    $future = now()->addMonths(2)->toIso8601String();
    $past = now()->subMonths(2)->toIso8601String();
    $ld = json_encode([
        [
            '@type' => 'Event',
            'name' => 'Future Fest',
            'startDate' => $future,
            'url' => 'https://events.humanitix.com/future-fest',
            'image' => ['https://images.humanitix.com/future.jpg'],
            'location' => ['@type' => 'Place', 'name' => 'Trugo Lane ', 'address' => ['addressLocality' => 'Footscray']],
            'offers' => [['@type' => 'AggregateOffer', 'lowPrice' => 25, 'highPrice' => 80, 'priceCurrency' => 'AUD', 'availability' => 'https://schema.org/InStock']],
        ],
        [
            '@type' => 'Event',
            'name' => 'Past Gig',
            'startDate' => $past,
            'url' => 'https://events.humanitix.com/past-gig',
        ],
    ]);
    $html = '<html><head><meta property="og:title" content="Acme Events | Humanitix">'
        .'<script type="application/ld+json">'.$ld.'</script></head><body></body></html>';

    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->once()->andReturn(['status' => 200, 'body' => $html, 'finalUrl' => 'x', 'contentType' => 'text/html']);
    $scraper = new HumanitixScraper($fetcher);

    $result = $scraper->fetchEvents('https://events.humanitix.com/host/acme');

    expect($result['organiser'])->toBe('Acme Events');
    // The past event is filtered out; only the future one remains.
    expect($result['events'])->toHaveCount(1);
    expect($result['events'][0])->toMatchArray([
        'name' => 'Future Fest',
        'venue' => 'Trugo Lane',
        'location' => 'Footscray',
        'price' => 'AUD 25 – 80',
        'availability' => 'available',
        'image' => 'https://images.humanitix.com/future.jpg',
        'link' => 'https://events.humanitix.com/future-fest',
    ]);
});

it('returns null when the host page is unreachable', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->andReturn(['status' => 503, 'body' => '', 'finalUrl' => 'x', 'contentType' => '']);

    expect((new HumanitixScraper($fetcher))->fetchEvents('https://events.humanitix.com/host/acme'))->toBeNull();
});
