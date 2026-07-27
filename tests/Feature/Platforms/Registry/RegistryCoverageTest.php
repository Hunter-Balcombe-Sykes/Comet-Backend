<?php

use App\Catalog\LegacyPlatformMap;
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

    // Since the 27-provider stopgap (512689f4) + the P1 catalog bridge, the
    // registry's key set and LegacyPlatformMap must be the SAME 78-slug
    // vocabulary: the registry is what the legacy connect flows accept, the
    // map is what the connection write-guard accepts — drift between them
    // would let one layer accept what the other rejects. (The old hand-list
    // here encoded Decision 10, which plan §0 supersedes.)
    $expected = array_keys(LegacyPlatformMap::toSurfaceMap());

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
    // The 15 auto-content platforms the daily cron + manual refresh button re-pull.
    // 'shop' joined for latest-mode product selections (ShopFetch 304s when no
    // brand is in latest mode); 'fresha' joined for the service-menu refresh
    // (FreshaFetch 304s when nothing is selected or the menu is unchanged).
    $expected = [
        'youtube', 'youtube-music', 'eventbrite', 'humanitix', 'apple-music', 'apple-podcast',
        'bandcamp', 'spotify', 'soundcloud', 'vimeo', 'twitch', 'strava',
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
