<?php

use App\Services\Platforms\PlatformRefresher;
use App\Services\Platforms\Registry\PlatformRegistry;
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
