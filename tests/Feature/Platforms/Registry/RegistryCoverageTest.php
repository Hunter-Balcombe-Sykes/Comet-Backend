<?php

use App\Http\Resources\Platforms\MusicEmbedConnectionResource;
use App\Services\Platforms\Payloads\EmbedPayload;
use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Strategies\Fetch\DeezerFetch;
use App\Services\Platforms\Strategies\Fetch\GoogleBusinessFetch;
use App\Services\Platforms\Strategies\Fetch\OEmbedFetch;

it('registers exactly the platforms the app accepts today', function () {
    $registry = app(PlatformRegistry::class);

    $expected = [
        'shop', 'eventbrite', 'humanitix', 'apple-music', 'apple-podcast',
        'spotify', 'soundcloud', 'bandcamp', 'mixcloud', 'deezer', 'tidal',
        'youtube-music', 'youtube', 'vimeo', 'twitch', 'instagram', 'pinterest',
        'tiktok', 'facebook', 'x', 'linkedin', 'threads', 'reddit', 'fresha',
        'square', 'skool', 'strava', 'google-business', 'custom', 'opentable',
        'booking', 'reservations', 'online-ordering', 'resdiary', 'nowbookit',
        'events-custom',
    ];

    sort($expected);
    $actual = $registry->keys();
    sort($actual);

    expect($actual)->toBe($expected);
});

it('marks exactly the current REFRESHABLE platforms as refreshable', function () {
    $registry = app(PlatformRegistry::class);
    $refreshable = array_keys($registry->refreshable());
    sort($refreshable);

    $expected = PlatformRefresher::REFRESHABLE;
    sort($expected);

    expect($refreshable)->toBe($expected);
});

it('attaches an OEmbedFetch strategy to the spotify and soundcloud descriptors', function () {
    $registry = app(PlatformRegistry::class);

    expect($registry->get('spotify')->fetchStrategy())
        ->toBeInstanceOf(OEmbedFetch::class);
    expect($registry->get('soundcloud')->fetchStrategy())
        ->toBeInstanceOf(OEmbedFetch::class);
});

it('attaches a DeezerFetch strategy to the deezer descriptor', function () {
    $registry = app(PlatformRegistry::class);

    expect($registry->get('deezer')->fetchStrategy())
        ->toBeInstanceOf(DeezerFetch::class);
});

it('assigns the dormant mixcloud/tidal embeds EmbedPayload with no fetch strategy', function () {
    $registry = app(PlatformRegistry::class);

    foreach (['mixcloud', 'tidal'] as $key) {
        $d = $registry->get($key);
        expect($d)->not->toBeNull();
        expect($d->payloadClass())->toBe(EmbedPayload::class);
        expect($d->resourceClass())->toBe(MusicEmbedConnectionResource::class);
        expect($d->isRefreshable())->toBeFalse();
        expect($d->fetchStrategy())->toBeNull(); // dormant — no upstream fetch, no routes
    }
});

it('does not register routes for the dormant mixcloud/tidal embeds', function () {
    $uris = collect(app('router')->getRoutes())->map(fn ($r) => $r->uri());

    expect($uris->contains(fn ($u) => str_contains($u, 'platforms/mixcloud')))->toBeFalse();
    expect($uris->contains(fn ($u) => str_contains($u, 'platforms/tidal')))->toBeFalse();
});

it('attaches GoogleBusinessFetch but defers its payload/read-path to Plan 5', function () {
    $registry = app(PlatformRegistry::class);
    $d = $registry->get('google-business');

    expect($d->fetchStrategy())->toBeInstanceOf(GoogleBusinessFetch::class);
    // Intentionally NOT FeedPayload — its resource emits a variable key set (see plan).
    expect($d->payloadClass())->toBeNull();
});
