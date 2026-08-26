<?php

namespace App\Services\Platforms\Registry\Bindings;

use App\Http\Resources\Platforms\YoutubeMusicConnectionResource;
use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\Strategies\Connect\YoutubeMusicConnect;
use App\Services\Platforms\Strategies\Fetch\YoutubeMusicFetch;
use App\Services\Platforms\YoutubeScraper;

/**
 * PD-retirement P4 (2026-08-27): YouTube Music's full behavioural contract,
 * moved VERBATIM from the retired hand-written registration. Every string is
 * a frozen contract (the 422 copy, the deferred fetch error, the toggle
 * copy) — do not edit without the tests that pin them.
 *
 * Listen restructure (owner, 2026-08-18): the switch names the FORMAT it
 * publishes — Topic-channel uploads are songs, so this is the track one.
 */
final class YoutubeMusicBinding
{
    public static function configure(PlatformDescriptor $d): void
    {
        $d->category(PlatformCategory::Music)
            ->resource(YoutubeMusicConnectionResource::class)
            ->payload(FeedPayload::class)
            ->refreshable()
            // Feed fetch strategy (Plan 3b) — consumed by the registry-driven refresher.
            ->fetch(fn () => new YoutubeMusicFetch(app(YoutubeScraper::class)))
            // Connect strategy (FOUND-24 Task 8) — parse-fail message is the frozen 422 contract.
            ->connect(fn () => new YoutubeMusicConnect(app(YoutubeScraper::class)), 'Enter your YouTube Music artist URL (music.youtube.com/channel/…) or your channel @handle.')
            ->deferredConnect()->connectFetchError('Could not load releases for that channel.')
            ->connectInput('url', ['required', 'string', 'max:300'])
            ->displayToggles([
                ['key' => 'auto_sync_latest_track', 'label' => 'Newest track', 'description' => 'Your newest song joins your site automatically.'],
            ])
            ->refreshEvery((int) config('partna.refresh.intervals.youtube-music', 12 * 3600))
            ->routes(PlatformRouteShape::MultiAccount, null, true);
    }
}
