<?php

namespace App\Services\Platforms\Registry\Bindings;

use App\Http\Resources\Platforms\MusicEmbedConnectionResource;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\Payloads\EmbedPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\Strategies\Connect\SpotifyConnect;
use App\Services\Platforms\Strategies\Fetch\OEmbedFetch;

/**
 * PD-retirement P4 (2026-08-27): Spotify's full behavioural contract, moved
 * VERBATIM from the retired hand-written registration (the PD::oEmbed base —
 * Music category, MusicEmbedConnectionResource, EmbedPayload, refreshable —
 * plus every post-registration mutation). Every string is a frozen contract —
 * do not edit without the tests that pin it.
 *
 * Spotify sources both RELEASES (discography actor, listen restructure
 * 2026-08-18) and tracks, so it carries the same two switches Apple Music
 * does — without the release key exposed the release arm was un-switchable
 * (session 3, F27).
 */
final class SpotifyBinding
{
    public static function configure(PlatformDescriptor $d): void
    {
        $d->category(PlatformCategory::Music)
            ->resource(MusicEmbedConnectionResource::class)
            ->payload(EmbedPayload::class)
            ->refreshable()
            // oEmbed fetch (Plan 3a) — consumed by the registry-driven refresher.
            ->fetch(fn () => new OEmbedFetch(
                app(OEmbedService::class), fn (string $link) => 'https://open.spotify.com/oembed?url='.rawurlencode($link), 'spotify',
            ))
            // Connect strategy (FOUND-24) — parse-fail message is the frozen 422 contract.
            ->connect(fn () => new SpotifyConnect(app(OEmbedService::class)), 'Enter a Spotify link (open.spotify.com/artist/...).')
            // Deferred-connect seam (Phase 2, W4) — SpotifyConnect implements
            // DeferredConnect; message verbatim from resolve()'s fetch-stage failure.
            ->deferredConnect()->connectFetchError('Could not load that Spotify link.')
            ->connectInput('url', ['required', 'string', 'max:500'])
            ->displayToggles([
                ['key' => 'auto_sync_latest', 'label' => 'Newest release', 'description' => 'Your newest album, EP or single joins your site automatically.'],
                ['key' => 'auto_sync_latest_track', 'label' => 'Newest track', 'description' => 'Your newest track joins your site automatically.'],
            ])
            ->refreshEvery((int) config('partna.refresh.intervals.spotify', 12 * 3600))
            ->routes(PlatformRouteShape::MultiAccount, null, true);
    }
}
