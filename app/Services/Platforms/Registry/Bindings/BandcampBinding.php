<?php

namespace App\Services\Platforms\Registry\Bindings;

use App\Http\Resources\Platforms\BandcampConnectionResource;
use App\Services\Platforms\BandcampScraper;
use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\Strategies\Connect\BandcampConnect;
use App\Services\Platforms\Strategies\Fetch\BandcampFetch;

/**
 * PD-retirement P4 (2026-08-27): Bandcamp's full behavioural contract, moved
 * VERBATIM from the retired hand-written registration. Every string is a
 * frozen contract (the 422 copy, the deferred fetch error, the toggle copy) —
 * do not edit without the tests that pin them.
 *
 * auto_sync_latest defaults ON and gates BandcampFetch's scheduled re-pull,
 * mirroring the events toggle semantics (show_all_releases left with
 * Featured, 2026-08-06 — which releases appear is the Listen pool's
 * selection now, not a wire-visibility switch).
 */
final class BandcampBinding
{
    public static function configure(PlatformDescriptor $d): void
    {
        $d->category(PlatformCategory::Music)
            ->resource(BandcampConnectionResource::class)
            ->payload(FeedPayload::class)
            ->refreshable()
            // Feed fetch strategy (Plan 3b) — consumed by the registry-driven refresher.
            ->fetch(fn () => new BandcampFetch(app(BandcampScraper::class)))
            // Connect strategy (FOUND-24 Task 9) — parse-fail message is the frozen 422 contract.
            ->connect(fn () => new BandcampConnect(app(BandcampScraper::class)), 'Enter your Bandcamp page URL (yourname.bandcamp.com).')
            ->deferredConnect()->connectFetchError('Could not find releases on that Bandcamp page.')
            ->connectInput('url', ['required', 'string', 'max:500'])
            ->displayToggles([
                ['key' => 'auto_sync_latest', 'label' => 'Newest release', 'description' => 'Your newest album or single joins your site automatically.'],
            ])
            ->refreshEvery((int) config('partna.refresh.intervals.bandcamp', 12 * 3600))
            ->routes(PlatformRouteShape::MultiAccount, null, true);
    }
}
