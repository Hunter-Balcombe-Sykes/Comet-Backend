<?php

namespace App\Services\Platforms\Registry\Bindings;

use App\Http\Resources\Platforms\SpotifyPodcastConnectionResource;
use App\Services\Platforms\Payloads\FeedPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\SpotifyPodcastsScraper;
use App\Services\Platforms\Strategies\Connect\SpotifyPodcastsConnect;
use App\Services\Platforms\Strategies\Fetch\SpotifyPodcastsFetch;

/**
 * Item 11f (2026-09-01): Spotify Podcasts' behavioural contract — a NEW
 * platform, so unlike its P4 siblings nothing here is a frozen hand-written
 * registration; the shape is ApplePodcastBinding's (the sibling product)
 * where a choice existed.
 *
 * Why a binding at all: without one the derived descriptor takes the Brand
 * default (BrandLinkConnect + CardPayload), which would store a bare link
 * card and never run SpotifyPodcastsConnect — the vendor identity resolve,
 * the deferred 202 lane, and SpotifyPodcastsFetch's refresh leg all hang
 * off this class.
 *
 *  - payload: FeedPayload, not CardPayload — the selection carries identity
 *    keys (name/thumbnail/description/publisher) that a LinkPayload-style
 *    round-trip would strip (TwitchBinding's documented reason, same shape).
 *  - deferredConnect(): SpotifyPodcastsConnect implements DeferredConnect —
 *    identify() writes the canonical link pending, ConnectFetchJob fills it
 *    through the fetch strategy below.
 *  - refresh: BILLED (spotify_podcasts vendor cap), so the cadence key
 *    defaults to weekly — see config('partna.refresh.intervals').
 *  - episodes are NOT this binding's business: they ride the ingest lane
 *    (SpotifyPodcastsConnector → listen pool) off the same connection.
 */
final class SpotifyPodcastsBinding
{
    public static function configure(PlatformDescriptor $d): void
    {
        $d->category(PlatformCategory::Content)
            ->resource(SpotifyPodcastConnectionResource::class)
            ->payload(FeedPayload::class)
            ->refreshable()
            // A CLOSURE, never an instance — descriptors build at boot on
            // every request (DerivedDescriptorFactory's own rule).
            ->fetch(fn () => new SpotifyPodcastsFetch(app(SpotifyPodcastsScraper::class)))
            ->connect(fn () => new SpotifyPodcastsConnect(app(SpotifyPodcastsScraper::class)), 'Enter a Spotify show link (open.spotify.com/show/...).')
            ->deferredConnect()->connectFetchError('Could not load that Spotify show.')
            ->connectInput('url', ['required', 'string', 'max:500'])
            ->displayToggles([
                ['key' => 'auto_sync_latest', 'label' => 'Newest episode', 'description' => 'Your newest episode joins your site automatically.'],
            ])
            ->refreshEvery((int) config('partna.refresh.intervals.spotify_podcasts', 7 * 86400))
            ->routes(PlatformRouteShape::MultiAccount, null, true);
    }
}
