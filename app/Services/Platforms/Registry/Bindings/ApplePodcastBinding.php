<?php

namespace App\Services\Platforms\Registry\Bindings;

use App\Http\Resources\Platforms\ApplePodcastConnectionResource;
use App\Services\Platforms\AppleSearch;
use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\Strategies\Fetch\ApplePodcastFetch;

/**
 * PD-retirement P4 (2026-08-27): Apple Podcasts' full behavioural contract,
 * moved VERBATIM from the retired hand-written registration. Every string is
 * a frozen contract — do not edit without the tests that pin it.
 *
 * CA-W3 — see AppleMusicBinding's identical note: no ->deferredConnect()
 * (Apple's connect is bespoke via AppleController + DefersBespokeConnect,
 * there is no ConnectStrategy for the flag to describe), and routes(Bespoke)
 * documents the bespoke /apple/* hand-written routes.
 */
final class ApplePodcastBinding
{
    public static function configure(PlatformDescriptor $d): void
    {
        $d->category(PlatformCategory::Content)
            ->resource(ApplePodcastConnectionResource::class)
            ->payload(FeedPayload::class)
            ->refreshable()
            // Feed fetch strategy (Plan 3b / Task 8) — consumed by the registry-driven refresher.
            ->fetch(fn () => new ApplePodcastFetch(app(AppleSearch::class)))
            ->connectFetchError('Could not find that Apple Podcast or an episode.')
            ->connectInput('show', ['required', 'string', 'max:200'])
            ->displayToggles([
                ['key' => 'auto_sync_latest', 'label' => 'Newest episode', 'description' => 'Your newest episode joins your site automatically.'],
            ])
            ->refreshEvery((int) config('partna.refresh.intervals.apple-podcast', 12 * 3600))
            ->routes(PlatformRouteShape::Bespoke);
    }
}
