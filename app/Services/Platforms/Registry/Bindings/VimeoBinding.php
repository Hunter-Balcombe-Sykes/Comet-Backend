<?php

namespace App\Services\Platforms\Registry\Bindings;

use App\Http\Resources\Platforms\VimeoConnectionResource;
use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\Strategies\Connect\VimeoConnect;
use App\Services\Platforms\Strategies\Fetch\VimeoFetch;
use App\Services\Platforms\VimeoApi;

/**
 * PD-retirement P4 (2026-08-27, canary): Vimeo's full behavioural contract,
 * moved VERBATIM from the retired hand-written registration. The derived
 * base (slug, label, surface key) comes from the catalog; everything a
 * refresh/connect needs attaches here. Every string is a frozen contract
 * (the 422 copy, the deferred fetch error, the toggle copy) — do not edit
 * without the tests that pin them.
 */
final class VimeoBinding
{
    public static function configure(PlatformDescriptor $d): void
    {
        $d->category(PlatformCategory::Content)
            ->resource(VimeoConnectionResource::class)
            ->payload(FeedPayload::class)
            ->refreshable()
            // Feed fetch strategy (Plan 3b) — consumed by the registry-driven refresher.
            ->fetch(fn () => new VimeoFetch(app(VimeoApi::class)))
            // Connect strategy (FOUND-24 Task 8) — parse-fail message is the frozen 422 contract.
            ->connect(fn () => new VimeoConnect(app(VimeoApi::class)), 'Enter your Vimeo profile or channel URL (vimeo.com/yourname).')
            ->deferredConnect()->connectFetchError('Could not find that Vimeo profile.')
            ->connectInput('url', ['required', 'string', 'max:300'])
            ->displayToggles([
                ['key' => 'auto_sync_latest', 'label' => 'Latest video', 'description' => 'Your newest video joins your site automatically.'],
            ])
            ->refreshEvery((int) config('partna.refresh.intervals.vimeo', 12 * 3600))
            ->routes(PlatformRouteShape::MultiAccount, null, true);
    }
}
