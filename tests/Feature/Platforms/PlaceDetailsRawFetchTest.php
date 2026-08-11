<?php

use App\Exceptions\Platforms\PlacesBudgetExhaustedException;
use App\Services\Platforms\GoogleBusinessService;
use App\Services\Platforms\PlaceDetailsFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.google_maps.server_api_key', 'test-key');
    config()->set('partna.limits.places.details_max_attempts', 1);
    config()->set('partna.limits.places.global_daily_cap', 100);
    config()->set('partna.limits.places.skus.details', 100);
    config()->set('partna.limits.places.skus.photos', 100);
    config()->set('partna.limits.places.per_user_daily_cap', 100);
});

function places(): GoogleBusinessService
{
    return app(GoogleBusinessService::class);
}

it('returns the raw Places response untouched, not the mapped payload', function () {
    // The connector reads displayName.text / photos[].name / reviews[].authorAttribution.
    // Mapping here would null every one of them — including the three reviewer-PII
    // keys the manifest's when_unclaimed redaction is declared over.
    Http::fake(['places.googleapis.com/*' => Http::response([
        'displayName' => ['text' => 'Maha'],
        'formattedAddress' => '21 Bond St',
        'photos' => [['name' => 'places/abc/photos/xyz', 'widthPx' => 400]],
        'reviews' => [['rating' => 5, 'text' => ['text' => 'great'], 'authorAttribution' => ['displayName' => 'Sam']]],
    ], 200)]);

    $result = places()->fetchPlaceDetailsRaw('ChIJabc', 'user-1');

    expect($result->failure)->toBeNull()
        ->and($result->place['displayName']['text'])->toBe('Maha')
        ->and($result->place['photos'][0]['name'])->toBe('places/abc/photos/xyz')
        ->and($result->place['reviews'][0]['authorAttribution']['displayName'])->toBe('Sam');
});

it('reports a missing server key without touching the network', function () {
    config()->set('services.google_maps.server_api_key', '');
    Http::fake();

    expect(places()->fetchPlaceDetailsRaw('ChIJabc', 'user-1')->failure)
        ->toBe(PlaceDetailsFailure::NotConfigured);

    Http::assertNothingSent();
});

it('reports a first-attempt budget denial as BudgetDenied, before any request', function () {
    config()->set('partna.limits.places.per_user_daily_cap', 0);
    Http::fake();

    $result = places()->fetchPlaceDetailsRaw('ChIJabc', 'user-1');

    expect($result->failure)->toBe(PlaceDetailsFailure::BudgetDenied)
        ->and($result->deniedBy)->not->toBeNull();

    Http::assertNothingSent();
});

it('reports a transport failure on every attempt as Transport', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    expect(places()->fetchPlaceDetailsRaw('ChIJabc', 'user-1')->failure)
        ->toBe(PlaceDetailsFailure::Transport);
});

// ⚠️ ONE STATUS PER TEST — DO NOT COLLAPSE THESE INTO A LOOP OF Http::fake() CALLS.
// Illuminate\Http\Client\Factory::fake() MERGES stub callbacks rather than
// replacing them, and PendingRequest resolves with ->filter()->first(). Four
// sequential fakes against the same pattern all resolve to the FIRST one, so a
// collapsed version would fail three assertions and, worse, could pass vacuously
// if every arm happened to expect the same outcome.
it('treats a 404 as Google answering: there is no such place', function () {
    Http::fake(['places.googleapis.com/*' => Http::response([], 404)]);

    expect(places()->fetchPlaceDetailsRaw('ChIJgone', 'user-1')->failure)
        ->toBe(PlaceDetailsFailure::NotFound);
});

it('treats a 429 as Google not answering', function () {
    Http::fake(['places.googleapis.com/*' => Http::response([], 429)]);

    expect(places()->fetchPlaceDetailsRaw('ChIJabc', 'user-1')->failure)
        ->toBe(PlaceDetailsFailure::UpstreamError);
});

it('treats a 503 as Google not answering', function () {
    Http::fake(['places.googleapis.com/*' => Http::response([], 503)]);

    expect(places()->fetchPlaceDetailsRaw('ChIJabc', 'user-1')->failure)
        ->toBe(PlaceDetailsFailure::UpstreamError);
});

it('treats a 403 as our credential problem, never as "no such place"', function () {
    // Settling a 403 as an answer would cache a broken key as "this place has no
    // data" — for every place at once, for the whole freshness window.
    Http::fake(['places.googleapis.com/*' => Http::response([], 403)]);

    expect(places()->fetchPlaceDetailsRaw('ChIJabc', 'user-1')->failure)
        ->toBe(PlaceDetailsFailure::UpstreamError);
});

it('keeps fetchPlaceDetails mapping and its budget exception unchanged', function () {
    Http::fake(['places.googleapis.com/*' => Http::response([
        'displayName' => ['text' => 'Maha'],
        'formattedAddress' => '21 Bond St',
        'nationalPhoneNumber' => '03 9000 0000',
    ], 200)]);

    $mapped = places()->fetchPlaceDetails('ChIJabc', 'user-1');

    expect($mapped['name'])->toBe('Maha')
        ->and($mapped['address'])->toBe('21 Bond St')
        ->and($mapped['phone'])->toBe('03 9000 0000')
        ->and($mapped)->toHaveKey('detailsFetchedAt');

    config()->set('partna.limits.places.per_user_daily_cap', 0);
    expect(fn () => places()->fetchPlaceDetails('ChIJabc', 'user-2'))
        ->toThrow(PlacesBudgetExhaustedException::class);
});

it('returns null from fetchPlaceDetails when the vendor did not answer', function () {
    Http::fake(['places.googleapis.com/*' => Http::response([], 503)]);

    expect(places()->fetchPlaceDetails('ChIJabc', 'user-1'))->toBeNull();
});

it('returns null from fetchPlaceDetails when the key is unset', function () {
    config()->set('services.google_maps.server_api_key', '');
    Http::fake();

    expect(places()->fetchPlaceDetails('ChIJabc', 'user-1'))->toBeNull();
});
