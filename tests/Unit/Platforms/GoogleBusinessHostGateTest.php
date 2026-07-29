<?php

// #TEST-12: GoogleBusinessService::parsePlaceUrl() had ZERO spoof coverage and
// its host gate was an OPEN family (`com(\.[a-z]{2})?|co\.[a-z]{2}|[a-z]{2}`)
// admitting ~2,029 registrable suffixes — `google.tk`, `google.cm`,
// `google.com.zz` all passed. It has been narrowed to a closed enumeration
// (GoogleBusinessService::TLDS), matching EventbriteScraper::TLDS /
// OpenTableService::TLDS. Driven entirely through the PUBLIC resolve() —
// parsePlaceUrl() is private, no reflection. resolve() only performs HTTP
// when the host is in the short-link allowlist, so every case below is pure.

use App\Services\Cache\PlacesBudget;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\GoogleBusinessService;

afterEach(function () {
    Mockery::close();
});

function googleGateService(): GoogleBusinessService
{
    // resolve() treats 'maps.google.com' itself as a short-link host (it's in
    // the same in_array() allowlist as maps.app.goo.gl), so a bare
    // maps.google.com/?q=... fixture DOES reach tryFetch. Default it to a
    // miss so resolve() falls back to parsing the original input directly —
    // the short-link-follow mechanism itself is covered by the dedicated
    // 'only follows the enumerated Google short-link hosts' test below.
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->andReturn(null)->byDefault();

    return new GoogleBusinessService($fetcher, new PlacesBudget);
}

it('rejects a spoofed Google Maps host', function (string $url) {
    expect(googleGateService()->resolve($url))->toBeNull();
})->with([
    'www.google.evil.com maps host' => ['https://www.google.evil.com/maps/place/Some+Cafe'],
    'attacker subdomain of google' => ['https://google.attacker.io/maps/place/X'],
    // "google.com" as a LABEL inside an attacker host — the registrable
    // domain is evil.com, not google.com.
    'google.com.evil.com' => ['https://google.com.evil.com/maps/place/X'],
    'notgoogle.com is not google' => ['https://notgoogle.com/maps/place/X'],
    'google.com appears only in the path' => ['https://evil.com/google.com/maps/place/X'],
    // The open-family gate this replaces would have admitted BOTH of these —
    // this is the load-bearing assertion of the finding.
    'google.tk is not a real Google ccTLD' => ['https://google.tk/maps/place/X'],
    'google.com.zz is not a real Google ccTLD' => ['https://google.com.zz/maps/place/X'],
]);

it('accepts the real Google Maps host shapes', function () {
    $svc = googleGateService();

    $www = $svc->resolve('https://www.google.com/maps/place/Fade+Lab+Barbers/@-37.81,144.96,17z');
    // Asserting ['name'] proves the parse SUCCEEDED (host gate let it through
    // AND the place data extracted), not merely that resolve() returned an array.
    expect($www['name'])->toBe('Fade Lab Barbers');

    expect($svc->resolve('https://www.google.com.au/maps/place/Some+Place/@-33.8,151.2,17z')['name'])->toBe('Some Place');
    expect($svc->resolve('https://www.google.co.uk/maps/place/Another+Place/@51.5,-0.1,17z')['name'])->toBe('Another Place');
    expect($svc->resolve('https://www.google.de/maps/place/Ein+Ort/@52.5,13.4,17z')['name'])->toBe('Ein Ort');

    // Regression: the first cut of TLDS carried a bare `pe`, which does not
    // exist — Peru is google.com.pe — so the gate admitted a fabricated host
    // while rejecting the real one. These four are the shapes most easily got
    // wrong by deriving the suffix from the country code instead of checking
    // it, so they stay pinned.
    expect($svc->resolve('https://www.google.com.pe/maps/place/Un+Lugar/@-12.0,-77.0,17z')['name'])->toBe('Un Lugar');
    expect($svc->resolve('https://www.google.co.kr/maps/place/Some+Spot/@37.5,127.0,17z')['name'])->toBe('Some Spot');
    expect($svc->resolve('https://www.google.co.id/maps/place/Satu+Tempat/@-6.2,106.8,17z')['name'])->toBe('Satu Tempat');
    expect($svc->resolve('https://www.google.com.ua/maps/place/Some+Kyiv+Spot/@50.4,30.5,17z')['name'])->toBe('Some Kyiv Spot');

    // maps.google.com is also a recognised short-link host (see the fixture
    // helper above) — tryFetch is stubbed to miss, so resolve() falls back to
    // parsing this URL directly, exactly as it would for a real q= search link.
    $q = $svc->resolve('https://maps.google.com/?q=Fade+Lab+Barbers');
    expect($q['name'])->toBe('Fade Lab Barbers');

    // Bare host (no www) with a q= search link.
    $bare = $svc->resolve('https://google.com/maps/place/Bare+Host+Cafe');
    expect($bare['name'])->toBe('Bare Host Cafe');
});

it('only follows the enumerated Google short-link hosts', function () {
    // Guards against the `in_array(..., true)` short-link allowlist in
    // resolve() becoming a `str_contains`/suffix check: a spoofed short-link
    // host must never trigger the network fetch, and a real one must.
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->never();
    $svc = new GoogleBusinessService($fetcher, new PlacesBudget);

    expect($svc->resolve('https://maps.app.goo.gl.evil.com/abc'))->toBeNull();

    Mockery::close();

    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')
        ->once()
        ->with('https://maps.app.goo.gl/abc', Mockery::any())
        ->andReturn(['finalUrl' => 'https://www.google.com/maps/place/Resolved+Cafe']);
    $svc = new GoogleBusinessService($fetcher, new PlacesBudget);

    $place = $svc->resolve('https://maps.app.goo.gl/abc');
    expect($place['name'])->toBe('Resolved Cafe');
});
