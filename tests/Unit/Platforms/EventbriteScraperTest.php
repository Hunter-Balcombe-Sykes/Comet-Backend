<?php

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\EventbriteScraper;

afterEach(function () {
    Mockery::close();
});

// Event enrichment (2026-07-17): the same JSON-LD node the scraper already
// reads carries description / endDate / AggregateOffer numerics — these tests
// pin the enriched shape (description, startsAt/endsAt aliases, machine-readable
// priceMin + currency, soldOut bool) alongside the untouched legacy keys.

it('enriches a single event with description, ISO aliases, lowest price + currency, and soldOut', function () {
    $node = [
        '@type' => 'MusicEvent',
        'name' => 'Warehouse Rave',
        'description' => '<p>All-night warehouse party.</p>',
        'startDate' => '2026-09-05T21:00:00+10:00',
        'endDate' => '2026-09-06T05:00:00+10:00',
        'url' => 'https://www.eventbrite.com.au/e/warehouse-rave-tickets-123',
        'image' => 'https://img.evbuc.com/cover.jpg',
        'location' => ['name' => 'The Depot', 'address' => ['addressLocality' => 'Brunswick']],
        'offers' => [[
            '@type' => 'AggregateOffer',
            'lowPrice' => 20,
            'highPrice' => 55.5,
            'priceCurrency' => 'AUD',
            'availability' => 'https://schema.org/InStock',
        ]],
    ];
    $html = '<html><body><script type="application/ld+json">'.json_encode($node).'</script></body></html>';

    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->once()
        ->andReturn(['status' => 200, 'body' => $html, 'finalUrl' => 'x', 'contentType' => 'text/html']);

    $event = (new EventbriteScraper($fetcher))->fetchSingleEvent('https://www.eventbrite.com.au/e/warehouse-rave-tickets-123');

    // Legacy keys untouched (price stays the display string; availability the enum).
    expect($event['price'])->toBe('AUD 20 – 55.5');
    expect($event['availability'])->toBe('available');
    expect($event['startDate'])->toBe('2026-09-05T21:00:00+10:00');
    // Enriched keys.
    expect($event['description'])->toBe('All-night warehouse party.'); // HTML stripped
    expect($event['startsAt'])->toBe('2026-09-05T21:00:00+10:00');
    expect($event['endsAt'])->toBe('2026-09-06T05:00:00+10:00');
    expect($event['priceMin'])->toBe(20.0);
    expect($event['currency'])->toBe('AUD');
    expect($event['soldOut'])->toBeFalse();
    // Regional host still rewrites to .com (normalizeLink unaffected).
    expect($event['link'])->toBe('https://www.eventbrite.com/e/warehouse-rave-tickets-123');
});

it('maps a SoldOut availability to soldOut=true and tolerates a missing description', function () {
    $node = [
        '@type' => 'Event',
        'name' => 'Tiny Gig',
        'startDate' => '2026-10-01T19:00:00+10:00',
        'offers' => ['@type' => 'AggregateOffer', 'lowPrice' => 0, 'priceCurrency' => 'AUD', 'availability' => 'https://schema.org/SoldOut'],
    ];
    $html = '<html><body><script type="application/ld+json">'.json_encode($node).'</script></body></html>';

    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->once()
        ->andReturn(['status' => 200, 'body' => $html, 'finalUrl' => 'x', 'contentType' => 'text/html']);

    $event = (new EventbriteScraper($fetcher))->fetchSingleEvent('https://www.eventbrite.com/e/tiny-gig-1');

    expect($event['soldOut'])->toBeTrue();
    expect($event['availability'])->toBe('sold_out');
    expect($event['description'])->toBeNull();
    expect($event['endsAt'])->toBeNull();
    // Free event: numeric floor is still reported (frontend decides "Free" rendering).
    expect($event['priceMin'])->toBe(0.0);
    expect($event['price'])->toBe('Free');
});
