<?php

use App\Services\Cache\PlacesBudget;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\GoogleBusinessService;

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
//
// REMOVED: 'strava normalizes club URLs and splits the og title into location
// and name'. Strava was demoted to link-only and StravaClubScraper is deleted,
// so the club card (og title split into location + name, member count, avatar)
// is no longer scraped at all. Club-URL normalisation survives on
// StravaNormalizer and is covered by
// tests/Feature/Platforms/IntegrationsV4AdditionsTest.php.

// ── Google Business ──────────────────────────────────────────────────────────

it('google business parses full place URLs preferring the pin coordinates', function () {
    $service = new GoogleBusinessService(bookingFetcherWith([]), new PlacesBudget);

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
    ]), new PlacesBudget);

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
    ]), new PlacesBudget);

    $place = $service->resolve('https://share.google/xyz');
    expect($place['name'])->toBe('Mock Cafe');
});

// REMOVED: 'strava upgrades the og avatar to the original rendition when it
// exists' — same demotion. Link-only Strava stores {username, url} only; there
// is no avatar fetched, so no rendition to upgrade.
