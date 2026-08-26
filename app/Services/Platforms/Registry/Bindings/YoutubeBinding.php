<?php

namespace App\Services\Platforms\Registry\Bindings;

use App\Http\Resources\Platforms\YoutubeConnectionResource;
use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\Strategies\Connect\YoutubeConnect;
use App\Services\Platforms\Strategies\Fetch\YoutubeFetch;
use App\Services\Platforms\YoutubeScraper;

/**
 * PD-retirement P4 (2026-08-27): YouTube's full behavioural contract, moved
 * VERBATIM from the retired hand-written registration. Every string is a
 * frozen contract (the 422 copy, the deferred fetch error, the toggle copy) —
 * do not edit without the tests that pin them.
 */
final class YoutubeBinding
{
    public static function configure(PlatformDescriptor $d): void
    {
        $d->category(PlatformCategory::Content)
            ->resource(YoutubeConnectionResource::class)
            ->payload(FeedPayload::class)
            ->refreshable()
            // Feed fetch strategy (Plan 3b) — consumed by the registry-driven refresher.
            ->fetch(fn () => new YoutubeFetch(app(YoutubeScraper::class)))
            // Connect strategy (FOUND-24 Task 7) — parse-fail message is the frozen 422 contract.
            ->connect(fn () => new YoutubeConnect(app(YoutubeScraper::class)), 'Enter your YouTube channel.')
            ->deferredConnect()->connectFetchError('Could not find that YouTube channel or its latest video.')
            ->connectInput('channel', ['required', 'string', 'max:200'])
            // The pools' auto half (2026-08-05): the newest-item auto-join
            // switch, read at pool resolve time (latest_per_auto_source),
            // never as a fetch gate.
            ->displayToggles([
                ['key' => 'auto_sync_latest', 'label' => 'Latest video', 'description' => 'Your newest upload joins your site automatically.'],
            ])
            ->refreshEvery((int) config('partna.refresh.intervals.youtube', 12 * 3600))
            ->routes(PlatformRouteShape::MultiAccount, null, true);
    }
}
