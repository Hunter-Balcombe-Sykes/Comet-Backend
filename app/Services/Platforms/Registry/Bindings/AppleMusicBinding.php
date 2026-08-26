<?php

namespace App\Services\Platforms\Registry\Bindings;

use App\Http\Resources\Platforms\AppleMusicConnectionResource;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\Strategies\Fetch\AppleMusicFetch;

/**
 * PD-retirement P4 (2026-08-27): Apple Music's full behavioural contract,
 * moved VERBATIM from the retired hand-written registration. Every string is
 * a frozen contract — do not edit without the tests that pin it.
 *
 * CA-W3: connectFetchError is the message ConnectFetchJob stores on the row
 * when the deferred fetch fails — verbatim from connectFor()'s own
 * synchronous 404 message. Deliberately NOT ->deferredConnect(): that flag
 * means "this descriptor's ConnectStrategy implements DeferredConnect"
 * (RegistryConnectCoverageTest pins flag<=>instanceof for every descriptor),
 * but Apple has no ConnectStrategy at all — its connect is bespoke
 * (AppleController::connectFor(), via DefersBespokeConnect), never routed
 * through ConnectResolver/GenericPlatformController. The rollout flag check
 * (config('partna.connect.deferred')) is read directly by
 * DefersBespokeConnect::shouldDeferConnect(), not via
 * supportsDeferredConnect() — so setting that flag here would just be a
 * false claim that breaks the pinned invariant for no functional gain.
 *
 * routes(Bespoke) is explicit documentation of the default: Apple's routes
 * live hand-written under /apple/* (AppleController), exactly as when the
 * descriptor was hand-written and never called routes() at all.
 */
final class AppleMusicBinding
{
    public static function configure(PlatformDescriptor $d): void
    {
        $d->category(PlatformCategory::Music)
            ->resource(AppleMusicConnectionResource::class)
            ->payload(FeedPayload::class)
            ->refreshable()
            // Feed fetch strategy (Plan 3b / Task 8) — consumed by the registry-driven refresher.
            ->fetch(fn () => new AppleMusicFetch(app(AppleSearch::class)))
            ->connectFetchError('Could not find that Apple Music artist or an album.')
            ->connectInput('artist', ['required', 'string', 'max:200'])
            // Listen restructure (owner, 2026-08-18): each switch names the
            // FORMAT it publishes; Apple Music emits both.
            ->displayToggles([
                ['key' => 'auto_sync_latest', 'label' => 'Newest release', 'description' => 'Your newest album, EP or single joins your site automatically.'],
                ['key' => 'auto_sync_latest_track', 'label' => 'Newest song', 'description' => 'Your newest song joins your site automatically.'],
            ])
            ->refreshEvery((int) config('partna.refresh.intervals.apple-music', 12 * 3600))
            ->routes(PlatformRouteShape::Bespoke);
    }
}
