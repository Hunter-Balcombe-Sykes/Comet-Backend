<?php

// tests/Feature/Platforms/RefreshObservabilityTest.php
//
// OBS-1 report()/fail() assertions across the 4 surviving silent-failure sites.
// All imports for the whole file live in this block; Tasks 8 & 10 append only bodies.

use App\Exceptions\Platforms\PlaceDetailsUnavailableException;
use App\Services\Platforms\GoogleBusinessService;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;

it('reports a PlaceDetailsUnavailableException when the billed Place-Details call is non-OK', function () {
    config()->set('services.google_maps.server_api_key', 'test-key');
    Exceptions::fake();
    Http::fake(['places.googleapis.com/v1/places/*' => Http::response(['error' => 'quota'], 429)]);

    $result = app(GoogleBusinessService::class)->fetchPlaceDetails('ChIJfail');

    expect($result)->toBeNull(); // contract preserved
    Exceptions::assertReported(fn (PlaceDetailsUnavailableException $e) => $e->placeId === 'ChIJfail' && $e->status === 429);
});

it('does not report on a healthy Place-Details fetch', function () {
    config()->set('services.google_maps.server_api_key', 'test-key');
    Exceptions::fake();
    // Media pattern first (see Task 6 note); the details response carries no photos,
    // so resolvePhotoUrls is skipped and no media call is made regardless.
    Http::fake([
        'places.googleapis.com/*/media*' => Http::response(['photoUri' => 'x'], 200),
        'places.googleapis.com/v1/places/*' => Http::response(['id' => 'ChIJok'], 200),
    ]);

    app(GoogleBusinessService::class)->fetchPlaceDetails('ChIJok');

    Exceptions::assertNothingReported();
});
