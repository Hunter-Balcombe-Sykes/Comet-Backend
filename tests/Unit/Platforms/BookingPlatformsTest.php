<?php

use App\Services\Platforms\BooksyScraper;
use App\Services\Platforms\CalendlyApi;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\QuandooScraper;
use App\Services\Platforms\StravaClubScraper;
use App\Services\SmartLinks\SafeUrlFetcher;

afterEach(function () {
    Mockery::close();
});

function bookingFetcherWith(array $routes): SafeUrlFetcher
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->andReturnUsing(function (string $url) use ($routes) {
        foreach ($routes as $needle => $response) {
            if (str_contains($url, $needle)) {
                return $response;
            }
        }

        return ['status' => 404, 'body' => '', 'finalUrl' => $url, 'contentType' => ''];
    });

    return $fetcher;
}

// ── Booksy ───────────────────────────────────────────────────────────────────

it('booksy normalizes listing URLs and reads the business JSON-LD', function () {
    $ld = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'HairSalon',
        'name' => 'Fade Lab',
        'image' => ['https://booksy.com/img/fadelab.jpg'],
        'aggregateRating' => ['@type' => 'AggregateRating', 'ratingValue' => 4.974137, 'reviewCount' => 232],
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => '12 Side St', 'addressLocality' => 'Sydney'],
    ]);
    $scraper = new BooksyScraper(bookingFetcherWith([
        'booksy.com/en-au/12345_fade-lab' => ['status' => 200, 'body' => '<script type="application/ld+json">'.$ld.'</script>', 'finalUrl' => 'x', 'contentType' => 'text/html'],
    ]));

    expect($scraper->normalizeUrl('https://booksy.com/en-au/12345_fade-lab_barber-shop_30748_sydney?from=share'))
        ->toBe('https://booksy.com/en-au/12345_fade-lab_barber-shop_30748_sydney');
    expect($scraper->normalizeUrl('https://booksy.com/en-au/s/barbers'))->toBeNull(); // search page
    expect($scraper->normalizeUrl('https://example.com/en-au/12345_x'))->toBeNull();

    $business = $scraper->fetchBusiness('https://booksy.com/en-au/12345_fade-lab');
    expect($business)->toMatchArray([
        'name' => 'Fade Lab',
        'image' => 'https://booksy.com/img/fadelab.jpg',
        'rating' => 4.97,
        'reviewCount' => 232,
        'address' => '12 Side St, Sydney',
    ]);
});

// ── Quandoo ──────────────────────────────────────────────────────────────────

it('quandoo normalizes place URLs and reads the Restaurant JSON-LD with its /6 scale', function () {
    $ld = json_encode([
        '@type' => 'Restaurant',
        'name' => 'Mazzaro',
        'image' => 'https://qul.imgix.net/m.jpg',
        'servesCuisine' => ['Mediterranean', 'Italian', 'European', 'Pizza'],
        'aggregateRating' => ['ratingValue' => '5.5', 'bestRating' => 6, 'reviewCount' => 173],
        'address' => ['streetAddress' => '271 Elizabeth St', 'addressLocality' => 'Sydney'],
    ]);
    $scraper = new QuandooScraper(bookingFetcherWith([
        'quandoo.com.au/place/mazzaro-12350' => ['status' => 200, 'body' => '<script type="application/ld+json">'.$ld.'</script>', 'finalUrl' => 'x', 'contentType' => 'text/html'],
    ]));

    expect($scraper->normalizeUrl('https://www.quandoo.com.au/place/Mazzaro-12350/menu?x=1'))
        ->toBe('https://www.quandoo.com.au/place/mazzaro-12350');
    expect($scraper->normalizeUrl('https://quandoo.com.au/help'))->toBeNull();

    $restaurant = $scraper->fetchRestaurant('https://www.quandoo.com.au/place/mazzaro-12350');
    expect($restaurant)->toMatchArray([
        'name' => 'Mazzaro',
        'rating' => 5.5,
        'bestRating' => 6,
        'reviewCount' => 173,
        'cuisines' => ['Mediterranean', 'Italian', 'European'], // capped at 3
        'address' => '271 Elizabeth St, Sydney',
    ]);
});

// ── Calendly ─────────────────────────────────────────────────────────────────

it('calendly parses slugs and fetches the booking profile + event types', function () {
    $api = new CalendlyApi(bookingFetcherWith([
        '/api/booking/profiles/mock-pt/event_types' => ['status' => 200, 'body' => json_encode([
            ['name' => 'PT Session', 'slug' => 'pt-session', 'description' => '<p>45 min</p>', 'color' => '#fff', 'uuid' => 'u1'],
            ['name' => 'Consult', 'slug' => 'consult', 'description' => null, 'color' => '#000', 'uuid' => 'u2'],
        ]), 'finalUrl' => 'x', 'contentType' => 'application/json'],
        '/api/booking/profiles/mock-pt' => ['status' => 200, 'body' => json_encode([
            'name' => 'Mock PT', 'avatar_url' => 'https://d3v0px0pttie1i.cloudfront.net/a.png', 'description' => 'Book a session.',
        ]), 'finalUrl' => 'x', 'contentType' => 'application/json'],
    ]));

    expect($api->parseSlug('https://calendly.com/Mock-PT/intro?month=2026-06'))->toBe('mock-pt');
    expect($api->parseSlug('mock-pt'))->toBe('mock-pt');
    expect($api->parseSlug('https://calendly.com/api/x'))->toBeNull(); // reserved

    $profile = $api->fetchProfile('mock-pt');
    expect($profile)->toMatchArray(['name' => 'Mock PT', 'description' => 'Book a session.']);

    $types = $api->fetchEventTypes('mock-pt');
    expect($types)->toHaveCount(2);
    expect($types[0])->toBe(['name' => 'PT Session', 'slug' => 'pt-session', 'description' => '45 min']);
});

// ── Strava ───────────────────────────────────────────────────────────────────

it('strava normalizes club URLs and splits the og title into location and name', function () {
    $html = '<meta property="og:title" content="San Francisco, California | The Strava Club"/>'
        .'<meta property="og:image" content="https://cf.example/club.jpg"/>'
        .'<meta property="og:description" content="A place to ride."/>'
        .'<span>7,081,174 members</span>';
    $scraper = new StravaClubScraper(bookingFetcherWith([
        'strava.com/clubs/231407' => ['status' => 200, 'body' => $html, 'finalUrl' => 'x', 'contentType' => 'text/html'],
    ]));

    expect($scraper->normalizeUrl('https://strava.com/clubs/231407?tab=recent'))->toBe('https://www.strava.com/clubs/231407');
    expect($scraper->normalizeUrl('https://strava.com/athletes/1'))->toBeNull();

    $club = $scraper->fetchClub('https://www.strava.com/clubs/231407');
    expect($club)->toMatchArray([
        'name' => 'The Strava Club',
        'location' => 'San Francisco, California',
        'image' => 'https://cf.example/club.jpg',
        'members' => 7081174,
    ]);
});

// ── Google Business ──────────────────────────────────────────────────────────

it('google business parses full place URLs preferring the pin coordinates', function () {
    $service = new GoogleBusinessService(bookingFetcherWith([]));

    $place = $service->resolve('https://www.google.com/maps/place/Sydney+Opera+House/@-33.857,151.213,17z/data=!3m1!4b1!4m6!3m5!1s0x6b12ae665e892fdd!8m2!3d-33.8567844!4d151.2152967');
    expect($place['name'])->toBe('Sydney Opera House');
    // !3d/!4d pin beats the @ viewport centre.
    expect($place['lat'])->toBe(-33.8567844);
    expect($place['lng'])->toBe(151.2152967);

    $viewportOnly = $service->resolve('https://www.google.com/maps/place/Fade+Lab+Barbers/@-37.81,144.96,15z');
    expect($viewportOnly['lat'])->toBe(-37.81);

    $q = $service->resolve('https://maps.google.com/maps?q=Fade+Lab+Barbers');
    expect($q['name'])->toBe('Fade Lab Barbers');

    expect($service->resolve('https://example.com/maps/place/X'))->toBeNull();
});

it('google business resolves short links through the fetcher', function () {
    $service = new GoogleBusinessService(bookingFetcherWith([
        'maps.app.goo.gl/abc' => [
            'status' => 200, 'body' => '',
            'finalUrl' => 'https://www.google.com/maps/place/Fade+Lab/@-37.81,144.96,17z/data=!3d-37.8123!4d144.9601',
            'contentType' => 'text/html',
        ],
    ]));

    $place = $service->resolve('https://maps.app.goo.gl/abc123');
    expect($place['name'])->toBe('Fade Lab');
    expect($place['lat'])->toBe(-37.8123);
});

it('google business reads the canonical URL from an interstitial body', function () {
    $service = new GoogleBusinessService(bookingFetcherWith([
        'share.google/xyz' => [
            'status' => 200,
            'body' => '<html><a href="https://www.google.com/maps/place/Mock+Cafe/@-37.8,144.9,15z">open</a></html>',
            'finalUrl' => 'https://share.google/xyz',
            'contentType' => 'text/html',
        ],
    ]));

    $place = $service->resolve('https://share.google/xyz');
    expect($place['name'])->toBe('Mock Cafe');
});
