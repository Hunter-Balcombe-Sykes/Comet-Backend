<?php

use App\Catalog\LegacyPlatformMap;
use App\Http\Resources\Platforms\MusicEmbedConnectionResource;
use App\Services\Platforms\Payloads\EmbedPayload;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Registry\LinkOnlyBindings;
use App\Services\Platforms\Registry\PlatformRegistry;
use App\Services\Platforms\Strategies\Fetch\EventbriteFetch;
use App\Services\Platforms\Strategies\Fetch\GoogleBusinessFetch;
use App\Services\Platforms\Strategies\Fetch\HumanitixFetch;
use App\Services\Platforms\Strategies\Fetch\OEmbedFetch;
use App\Services\Platforms\Strategies\Refresh\NoRefresh;
use App\Services\Platforms\Strategies\Refresh\ScheduledRefresh;

it('keeps the hand-written registry frozen to the legacy map', function () {
    $registry = app(PlatformRegistry::class);

    // This assertion used to demand registry keys == LegacyPlatformMap keys, on
    // the premise that the connect layer and the write-guard must accept the same
    // vocabulary or one would accept what the other rejects. That premise is
    // already half-false: LegacyPlatformMap::isKnownSurface() falls back to the
    // compiled catalog, so the write-guard has long accepted catalog-only
    // surfaces the registry never carried.
    //
    // Derived descriptors (DerivedDescriptorFactory) make the asymmetry explicit
    // rather than introducing it. The freeze still binds the HAND-WRITTEN half —
    // that half is what the 20260727110001 backfill CASE mirrors, and
    // CatalogLegacyMapTest pins it independently.
    // P0.0 (2026-08-26): the freeze binds RESOLUTION, not authorship. Every
    // legacy-map slug must resolve to SOME descriptor — hand-written while it
    // exists, catalog-derived once its PD entry is deleted (the four ordering
    // slugs were the first to retire). A slug that resolves to NOTHING is the
    // vaporized-platform failure this test exists to catch.
    foreach (PlatformRegistry::handWrittenFreeze() as $slug) {
        expect($registry->has($slug))->toBeTrue(
            "Frozen slug '{$slug}' resolves to no descriptor at all — deleting its hand-written entry without a derivable catalog surface vaporizes the platform."
        );
    }

    // The retirees stay retired: derived, never re-hand-written. Four
    // ordering slugs went with the menu plan (2026-08-26); the 23 detect-only
    // card entries followed in PD-retirement P1 (2026-08-27).
    foreach ([...LinkOnlyBindings::slugs(), 'mixcloud', 'tidal', 'vimeo', 'bandcamp', 'youtube', 'youtube-music', 'apple-music', 'apple-podcast', 'spotify', 'soundcloud', 'eventbrite', 'humanitix', 'fresha', 'square', 'square-ordering', 'bopple', 'hungrypanda', 'easi', 'booksy', 'vagaro', 'timely', 'kitomba', 'phorest', 'shortcuts', 'bella-booking', 'boulevard', 'glossgenius', 'mangomint', 'zenoti', 'mindbody', 'ovatu', 'resy', 'quandoo', 'sevenrooms', 'tock', 'tablecheck', 'ticketek', 'oztix', 'trybooking', 'resident-advisor', 'ticketmaster'] as $slug) {
        expect($registry->get($slug)?->isDerived())->toBeTrue(
            "'{$slug}' was retired to a catalog-derived descriptor (2026-08-26); a hand-written entry has crept back."
        );
    }
});

it('never lets a derived descriptor shadow a hand-written one', function () {
    $registry = app(PlatformRegistry::class);

    // register() throws on a duplicate key, so a shadow can only appear if the
    // derived registration site stopped skipping on has(). Belt to that brace.
    // Retired slugs are excluded: derived IS their end state.
    $retired = [...LinkOnlyBindings::slugs(), 'mixcloud', 'tidal', 'vimeo', 'bandcamp', 'youtube', 'youtube-music', 'apple-music', 'apple-podcast', 'spotify', 'soundcloud', 'eventbrite', 'humanitix', 'fresha', 'square', 'square-ordering', 'bopple', 'hungrypanda', 'easi', 'booksy', 'vagaro', 'timely', 'kitomba', 'phorest', 'shortcuts', 'bella-booking', 'boulevard', 'glossgenius', 'mangomint', 'zenoti', 'mindbody', 'ovatu', 'resy', 'quandoo', 'sevenrooms', 'tock', 'tablecheck', 'ticketek', 'oztix', 'trybooking', 'resident-advisor', 'ticketmaster'];
    foreach (array_diff(PlatformRegistry::handWrittenFreeze(), $retired) as $slug) {
        expect($registry->get($slug)?->isDerived())->toBeFalse(
            "Derived descriptor shadowed the hand-written '{$slug}'."
        );
    }
});

// The "cover-capable platforms" freeze lived here until 2026-08-05: the owner
// retired per-integration covers, so the registry no longer carries the flag.

it('marks exactly the current REFRESHABLE platforms as refreshable', function () {
    $registry = app(PlatformRegistry::class);
    $refreshable = array_keys($registry->refreshable());
    sort($refreshable);

    // Frozen expectation (was PlatformRefresher::REFRESHABLE before Plan 6 deleted it).
    // The 13 auto-content platforms the daily cron + manual refresh button re-pull.
    // 'shop' joined for latest-mode product selections (ShopFetch 304s when no
    // brand is in latest mode); 'fresha' joined for the service-menu refresh
    // (FreshaFetch 304s when nothing is selected or the menu is unchanged).
    // 'twitch' and 'strava' left on demotion to link-only (Phase 1.2) — a link
    // has no upstream content to re-pull.
    $expected = [
        'youtube', 'youtube-music', 'eventbrite', 'humanitix', 'apple-music', 'apple-podcast',
        'bandcamp', 'spotify', 'soundcloud', 'vimeo',
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

it('registers brand connect routes for mixcloud/tidal — a profile / artist link card (task #17, 2026-08-18); the widget embeds stay dormant', function () {
    $uris = collect(app('router')->getRoutes())->map(fn ($r) => $r->uri());

    expect($uris->contains('api/platforms/mixcloud/connect'))->toBeTrue();
    expect($uris->contains('api/platforms/tidal/connect'))->toBeTrue();
});

it('attaches the Plan-6 fetch strategies to eventbrite and humanitix', function () {
    // Strava dropped from this trio on its Phase-1.2 demotion to link-only —
    // the inverse (no fetch strategy at all) is now pinned by the test below.
    $registry = app(PlatformRegistry::class);

    expect($registry->get('eventbrite')->fetchStrategy())->toBeInstanceOf(EventbriteFetch::class);
    expect($registry->get('humanitix')->fetchStrategy())->toBeInstanceOf(HumanitixFetch::class);
});

it('leaves the demoted skool/strava/twitch descriptors with no fetch strategy and no refresh', function () {
    // Phase 1.2 demoted all three to link-only: connect is a pure normalizer,
    // there is no upstream content to pull, and nothing may re-attach a fetch
    // strategy without also re-deciding refreshability and route shape.
    $registry = app(PlatformRegistry::class);

    foreach (['skool', 'strava', 'twitch'] as $key) {
        $d = $registry->get($key);
        expect($d)->not->toBeNull($key);
        expect($d->fetchStrategy())->toBeNull($key);
        expect($d->connectFetchStrategy())->toBeNull($key);
        expect($d->isRefreshable())->toBeFalse($key);
        expect($d->refreshStrategy())->toBeInstanceOf(NoRefresh::class, $key);
    }
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
