<?php

use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Registry\PlatformRouteShape;

it('pins the registry-driven route shapes', function () {
    $registry = app(PlatformRegistry::class);

    $linkOnly = ['x', 'linkedin', 'threads', 'reddit', 'tiktok', 'facebook'];
    foreach ($linkOnly as $key) {
        expect($registry->get($key)->routeShape())->toBe(PlatformRouteShape::LinkOnly, $key);
        expect($registry->get($key)->connectController())->toBeNull($key);
    }

    $single = ['skool', 'google-business'];
    foreach ($single as $key) {
        expect($registry->get($key)->routeShape())->toBe(PlatformRouteShape::SingleSelection, $key);
        expect($registry->get($key)->connectController())->not->toBeNull($key);
        expect($registry->get($key)->multiAccount())->toBeFalse($key);
    }

    $multiTrue = ['spotify', 'soundcloud', 'twitch', 'youtube', 'vimeo', 'youtube-music', 'bandcamp'];
    foreach ($multiTrue as $key) {
        expect($registry->get($key)->routeShape())->toBe(PlatformRouteShape::MultiAccount, $key);
        expect($registry->get($key)->multiAccount())->toBeTrue($key);
    }

    $multiFalse = ['strava', 'opentable', 'resdiary', 'nowbookit'];
    foreach ($multiFalse as $key) {
        expect($registry->get($key)->routeShape())->toBe(PlatformRouteShape::MultiAccount, $key);
        expect($registry->get($key)->multiAccount())->toBeFalse($key);
    }

    $bespoke = ['apple-music', 'apple-podcast',
        'instagram', 'eventbrite', 'humanitix', 'events-custom', 'fresha', 'square', 'shop',
        'custom', 'booking', 'reservations', 'online-ordering', 'mixcloud', 'tidal'];
    foreach ($bespoke as $key) {
        expect($registry->get($key)->routeShape())->toBe(PlatformRouteShape::Bespoke, $key);
    }
});
