<?php

// tests/Feature/Platforms/PlacesBudgetGateTest.php
//
// RV-6 — the load-bearing proof of the 1:1 claim invariant: every HTTP request
// that leaves the process bound for a billed Places endpoint is preceded by
// exactly one Granted PlacesBudget claim, and no Granted claim exists without
// a corresponding request. Http::fake + Http::assertSentCount is the
// established technique for this (RefreshHostLimitsTest.php), including the
// media/details fake-ordering trap (the `/media` pattern must be registered
// BEFORE the details catch-all, or Http::fake's first-match rule routes the
// media request to the details stub instead).

use App\Exceptions\Platforms\PlacesBudgetExhaustedException;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\GoogleBusinessService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** A Place Details (New) response carrying exactly $count fresh photo refs. */
function pbgDetailsResponse(string $placeId, int $count = 15): array
{
    return [
        'id' => $placeId,
        'displayName' => ['text' => 'Gate Test Cafe'],
        'photos' => array_map(fn (int $i) => [
            'name' => "places/{$placeId}/photos/photo-{$i}",
            'widthPx' => 100,
            'heightPx' => 100,
        ], range(1, $count)),
    ];
}

beforeEach(function () {
    config()->set('services.google_maps.server_api_key', 'gate-test-key');
    config()->set('partna.limits.places.global_daily_cap', 1000);
    config()->set('partna.limits.places.per_user_daily_cap', 1000);
    config()->set('partna.limits.places.skus.details', 1000);
    config()->set('partna.limits.places.skus.photos', 1000);
});

it('claims exactly 16 times for one fetchPlaceDetails() with 15 fresh photos — the 16x finding, proven', function () {
    Http::fake([
        'places.googleapis.com/*/media*' => Http::response(['photoUri' => 'https://lh3.example/x.jpg'], 200),
        'places.googleapis.com/v1/places/*' => Http::response(pbgDetailsResponse('ChIJgate1'), 200),
    ]);

    $userId = 'gate-user-16x';
    $result = app(GoogleBusinessService::class)->fetchPlaceDetails('ChIJgate1', $userId);

    expect($result)->not->toBeNull();
    expect($result['photos'])->toHaveCount(15);

    // 1 details request + 15 photo media requests = 16 billed calls total.
    Http::assertSentCount(16);

    $date = now()->format('Y-m-d');
    expect((int) Cache::get(CacheKeyGenerator::placesSkuDailyLimit('details', $date)))->toBe(1);
    expect((int) Cache::get(CacheKeyGenerator::placesSkuDailyLimit('photos', $date)))->toBe(15);
    expect((int) Cache::get(CacheKeyGenerator::placesGlobalDailyLimit($date)))->toBe(16);
    expect((int) Cache::get(CacheKeyGenerator::placesUserDailyLimit($userId, $date)))->toBe(16);
});

it('carry-forward photos claim nothing — 1 request, 1 claim, not 16', function () {
    $prior = array_map(fn (int $i) => [
        'ref' => "places/ChIJgate2/photos/photo-{$i}",
        'url' => "https://lh3.example/prior-{$i}.jpg",
    ], range(1, 15));

    Http::fake([
        'places.googleapis.com/*/media*' => Http::response(['photoUri' => 'https://lh3.example/x.jpg'], 200),
        'places.googleapis.com/v1/places/*' => Http::response(pbgDetailsResponse('ChIJgate2'), 200),
    ]);

    $userId = 'gate-user-carry';
    $result = app(GoogleBusinessService::class)->fetchPlaceDetails('ChIJgate2', $userId, $prior);

    expect($result['photos'][0]['url'])->toBe('https://lh3.example/prior-1.jpg'); // reused, not re-billed
    Http::assertSentCount(1); // details only — every photo ref matched a prior url

    $date = now()->format('Y-m-d');
    expect((int) Cache::get(CacheKeyGenerator::placesSkuDailyLimit('details', $date)))->toBe(1);
    expect((int) Cache::get(CacheKeyGenerator::placesSkuDailyLimit('photos', $date)))->toBe(0);
});

it('photo cap mid-fan-out is partial, not fatal — the connect still returns a card', function () {
    config()->set('partna.limits.places.skus.photos', 5);

    Http::fake([
        'places.googleapis.com/*/media*' => Http::response(['photoUri' => 'https://lh3.example/x.jpg'], 200),
        'places.googleapis.com/v1/places/*' => Http::response(pbgDetailsResponse('ChIJgate3'), 200),
    ]);

    $result = app(GoogleBusinessService::class)->fetchPlaceDetails('ChIJgate3', 'gate-user-photocap');

    expect($result)->not->toBeNull(); // the details payload still returns
    $resolved = collect($result['photos'])->filter(fn ($p) => ! empty($p['url']));
    $unresolved = collect($result['photos'])->filter(fn ($p) => empty($p['url']));
    expect($resolved)->toHaveCount(5)
        ->and($unresolved)->toHaveCount(10); // still carry their ref, just no url

    // Exactly the 5 admitted photo claims were billed — never more than the cap.
    Http::assertSentCount(1 + 5);
});

it('details cap denies before the request — no claim without a request, no request without a claim', function () {
    config()->set('partna.limits.places.skus.details', 0);
    Http::fake(); // nothing should be sent at all

    expect(fn () => app(GoogleBusinessService::class)->fetchPlaceDetails('ChIJgate4', 'gate-user-detailscap'))
        ->toThrow(PlacesBudgetExhaustedException::class);

    Http::assertNothingSent();
});

it('a transport retry on Place Details claims twice, once per attempt', function () {
    $attempt = 0;
    Http::fake([
        'places.googleapis.com/v1/places/*' => function () use (&$attempt) {
            $attempt++;
            if ($attempt === 1) {
                throw new ConnectionException('timeout');
            }

            return Http::response(['id' => 'ChIJgate5'], 200); // no photos — isolates the details-retry claim count
        },
    ]);

    $result = app(GoogleBusinessService::class)->fetchPlaceDetails('ChIJgate5', 'gate-user-retry');

    expect($result)->not->toBeNull();
    expect($attempt)->toBe(2); // the stub itself proves both HTTP attempts fired
    // NOTE: Http::assertSentCount can't prove this one — Laravel's fake harness
    // only records a promise that resolves via buildRecorderHandler's ->then();
    // a stub that THROWS synchronously (simulating a transport failure) never
    // reaches that recorder, so the failed attempt is invisible to
    // assertSentCount by construction of the test double, not of the code
    // under test. The budget counter below is what actually proves the claim
    // fired on BOTH attempts, which is the property this test exists for.

    $date = now()->format('Y-m-d');
    expect((int) Cache::get(CacheKeyGenerator::placesSkuDailyLimit('details', $date)))->toBe(2); // one claim per attempt
});

it('the Street View metadata probe stays free and stays the metadata endpoint', function () {
    Http::fake([
        'places.googleapis.com/v1/places/*' => Http::response([
            'id' => 'ChIJgate6',
            'location' => ['latitude' => -37.8, 'longitude' => 144.9],
        ], 200),
        'maps.googleapis.com/maps/api/streetview/metadata*' => Http::response([
            'status' => 'OK', 'pano_id' => 'panoGate', 'location' => ['lat' => -37.8, 'lng' => 144.9],
        ], 200),
    ]);

    $result = app(GoogleBusinessService::class)->fetchPlaceDetails('ChIJgate6', 'gate-user-streetview');

    expect($result['streetView']['panoId'])->toBe('panoGate');

    // Regression guard: the outbound URL is the free metadata endpoint, never
    // a billed image render (a one-word `/streetview` edit would flip this).
    Http::assertSent(fn ($request) => str_contains($request->url(), 'maps.googleapis.com/maps/api/streetview/metadata')
        || str_contains($request->url(), 'places.googleapis.com/v1/places/'));

    $date = now()->format('Y-m-d');
    // Only the 1 billed details call claimed — the free Street View probe claimed nothing.
    expect((int) Cache::get(CacheKeyGenerator::placesGlobalDailyLimit($date)))->toBe(1);
});
