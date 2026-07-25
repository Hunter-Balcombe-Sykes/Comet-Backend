<?php

use App\Http\Resources\Platforms\MusicEmbedConnectionResource;
use App\Services\Platforms\Payloads\EmbedPayload;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Strategies\Fetch\EventbriteFetch;
use App\Services\Platforms\Strategies\Fetch\GoogleBusinessFetch;
use App\Services\Platforms\Strategies\Fetch\HumanitixFetch;
use App\Services\Platforms\Strategies\Fetch\OEmbedFetch;
use App\Services\Platforms\Strategies\Fetch\StravaFetch;
use App\Services\Platforms\Strategies\Refresh\NoRefresh;
use App\Services\Platforms\Strategies\Refresh\ScheduledRefresh;

it('registers exactly the platforms the app accepts today', function () {
    $registry = app(PlatformRegistry::class);

    $expected = [
        'shop', 'eventbrite', 'humanitix', 'apple-music', 'apple-podcast',
        'spotify', 'soundcloud', 'bandcamp', 'mixcloud', 'tidal',
        'youtube-music', 'youtube', 'vimeo', 'twitch', 'instagram', 'pinterest',
        'tiktok', 'facebook', 'x', 'linkedin', 'threads', 'reddit',
        'snapchat', 'discord', 'telegram', 'kick', 'medium', 'fresha',
        'square', 'skool', 'strava', 'google-business', 'custom', 'opentable',
        'booking', 'reservations', 'online-ordering', 'resdiary', 'nowbookit',
        'events-custom',
        // Link classification consolidation, Phase 2 (2026-07-25). Each is its
        // own identity on the sitepage, so each earns a key. The ~55 new
        // booking/reservation/ordering BRANDS deliberately do NOT appear here —
        // under Decision 10 they ride as a `provider` string on the shared
        // 'booking' / 'reservations' / 'online-ordering' keys above.
        'whatsapp', 'substack', 'patreon', 'ko-fi', 'buymeacoffee', 'github',
        'gitlab', 'codepen', 'dribbble', 'behance', 'gumroad',
    ];

    sort($expected);
    $actual = $registry->keys();
    sort($actual);

    expect($actual)->toBe($expected);
});

it('marks exactly the cover-capable platforms as coverable', function () {
    $registry = app(PlatformRegistry::class);
    $coverable = array_keys($registry->coverable());
    sort($coverable);

    // The 4 platforms with a per-integration cover-image slot (SiteMedia design singletons).
    $expected = ['apple-music', 'apple-podcast', 'eventbrite', 'youtube'];
    sort($expected);

    expect($coverable)->toBe($expected);
});

it('marks exactly the current REFRESHABLE platforms as refreshable', function () {
    $registry = app(PlatformRegistry::class);
    $refreshable = array_keys($registry->refreshable());
    sort($refreshable);

    // Frozen expectation (was PlatformRefresher::REFRESHABLE before Plan 6 deleted it).
    // The 16 auto-content platforms the daily cron + manual refresh button re-pull.
    // 'shop' joined for latest-mode product selections (ShopFetch 304s when no
    // brand is in latest mode); 'fresha' joined for the service-menu refresh
    // (FreshaFetch 304s when nothing is selected or the menu is unchanged).
    $expected = [
        'youtube', 'youtube-music', 'eventbrite', 'humanitix', 'apple-music', 'apple-podcast',
        'bandcamp', 'spotify', 'soundcloud', 'vimeo', 'twitch', 'pinterest', 'strava',
        'google-business', 'shop', 'fresha',
    ];
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

it('attaches the Plan-6 fetch strategies to strava, eventbrite and humanitix', function () {
    $registry = app(PlatformRegistry::class);

    expect($registry->get('strava')->fetchStrategy())->toBeInstanceOf(StravaFetch::class);
    expect($registry->get('eventbrite')->fetchStrategy())->toBeInstanceOf(EventbriteFetch::class);
    expect($registry->get('humanitix')->fetchStrategy())->toBeInstanceOf(HumanitixFetch::class);
});

it('attaches GoogleBusinessFetch and a verbatim GoogleBusinessPayload (Plan 5 read-path) to google-business', function () {
    $registry = app(PlatformRegistry::class);
    $d = $registry->get('google-business');

    expect($d->fetchStrategy())->toBeInstanceOf(GoogleBusinessFetch::class);
    // GoogleBusinessPayload is verbatim-preserving (its resource emits a variable key set
    // via array_intersect_key) — NOT FeedPayload. Read-path migrated in Plan 5.
    expect($d->payloadClass())->toBe(GoogleBusinessPayload::class);
});

it('derives a ScheduledRefresh for a refreshable platform and NoRefresh otherwise', function () {
    $registry = app(PlatformRegistry::class);

    expect($registry->get('youtube')->refreshStrategy())->toBeInstanceOf(ScheduledRefresh::class);
    expect($registry->get('instagram')->refreshStrategy())->toBeInstanceOf(NoRefresh::class); // not refreshable
    expect($registry->get('tiktok')->refreshStrategy())->toBeInstanceOf(NoRefresh::class);   // link-only
});

it('isRefreshable mirrors the refreshable() set', function () {
    $registry = app(PlatformRegistry::class);

    expect($registry->isRefreshable('youtube'))->toBeTrue();
    expect($registry->isRefreshable('instagram'))->toBeFalse();
    expect($registry->isRefreshable('not-a-platform'))->toBeFalse();
});

it('every refreshable descriptor has a non-null fetchStrategy (flag ⇒ fetch; prevents cron degrading to NoRefresh)', function () {
    $registry = app(PlatformRegistry::class);

    $missing = [];
    foreach ($registry->refreshable() as $key => $descriptor) {
        if ($descriptor->fetchStrategy() === null) {
            $missing[] = $key;
        }
    }

    expect($missing)->toBe([], 'Refreshable platform(s) have no fetchStrategy — cron would silently degrade to NoRefresh: '.implode(', ', $missing));
});
