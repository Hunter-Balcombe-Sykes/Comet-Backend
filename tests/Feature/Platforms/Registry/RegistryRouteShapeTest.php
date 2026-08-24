<?php

use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Registry\PlatformRouteShape;

it('pins the registry-driven route shapes', function () {
    $registry = app(PlatformRegistry::class);

    // skool/strava/twitch joined this group on their Phase-1.2 demotion — they
    // left SingleSelection (skool), MultiAccount+multi (twitch) and
    // MultiAccount+single (strava) respectively, and now carry the same
    // controller-less shape as the socials.
    $linkOnly = ['x', 'linkedin', 'threads', 'reddit', 'tiktok', 'facebook', 'skool', 'strava', 'twitch'];
    foreach ($linkOnly as $key) {
        expect($registry->get($key)->routeShape())->toBe(PlatformRouteShape::LinkOnly, $key);
        expect($registry->get($key)->connectController())->toBeNull($key);
    }

    $single = ['google-business'];
    foreach ($single as $key) {
        expect($registry->get($key)->routeShape())->toBe(PlatformRouteShape::SingleSelection, $key);
        expect($registry->get($key)->connectController())->not->toBeNull($key);
        expect($registry->get($key)->multiAccount())->toBeFalse($key);
    }

    $multiTrue = ['spotify', 'soundcloud', 'youtube', 'vimeo', 'youtube-music', 'bandcamp'];
    foreach ($multiTrue as $key) {
        expect($registry->get($key)->routeShape())->toBe(PlatformRouteShape::MultiAccount, $key);
        expect($registry->get($key)->multiAccount())->toBeTrue($key);
    }

    $multiFalse = ['opentable', 'resdiary', 'nowbookit'];
    foreach ($multiFalse as $key) {
        expect($registry->get($key)->routeShape())->toBe(PlatformRouteShape::MultiAccount, $key);
        expect($registry->get($key)->multiAccount())->toBeFalse($key);
    }

    $bespoke = ['apple-music', 'apple-podcast',
        // The five pseudo descriptors (events-custom / custom / booking /
        // reservations / online-ordering) left the registry 2026-08-19.
        'instagram', 'eventbrite', 'humanitix', 'fresha', 'square', 'shop'];
    // mixcloud/tidal upgraded to Brand (connectable link cards, task #17 2026-08-18).
    foreach ($bespoke as $key) {
        expect($registry->get($key)->routeShape())->toBe(PlatformRouteShape::Bespoke, $key);
    }
});
