<?php

use App\Services\Platforms\GoogleBusinessService;
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
