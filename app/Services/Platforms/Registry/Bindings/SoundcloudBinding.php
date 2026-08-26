<?php

namespace App\Services\Platforms\Registry\Bindings;

use App\Http\Resources\Platforms\MusicEmbedConnectionResource;
use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\Payloads\EmbedPayload;
use App\Services\Platforms\Registry\PlatformCategory;
use App\Services\Platforms\Registry\PlatformDescriptor;
use App\Services\Platforms\Registry\PlatformRouteShape;
use App\Services\Platforms\Strategies\Connect\SoundcloudConnect;
use App\Services\Platforms\Strategies\Fetch\OEmbedFetch;

/**
 * PD-retirement P4 (2026-08-27): SoundCloud's full behavioural contract,
 * moved VERBATIM from the retired hand-written registration (the PD::oEmbed
 * base — Music category, MusicEmbedConnectionResource, EmbedPayload,
 * refreshable — plus every post-registration mutation). Every string is a
 * frozen contract — do not edit without the tests that pin it.
 *
 * No ->deferredConnect(): SoundcloudConnect does not implement
 * DeferredConnect (RegistryConnectCoverageTest pins flag<=>instanceof).
 */
final class SoundcloudBinding
{
    public static function configure(PlatformDescriptor $d): void
    {
        $d->category(PlatformCategory::Music)
            ->resource(MusicEmbedConnectionResource::class)
            ->payload(EmbedPayload::class)
            ->refreshable()
            // oEmbed fetch (Plan 3a) — consumed by the registry-driven refresher.
            ->fetch(fn () => new OEmbedFetch(
                app(OEmbedService::class), fn (string $link) => 'https://soundcloud.com/oembed?format=json&url='.rawurlencode($link), 'soundcloud',
            ))
            // Connect strategy (FOUND-24) — parse-fail message is the frozen 422 contract.
            ->connect(fn () => new SoundcloudConnect(app(OEmbedService::class)), 'Enter your SoundCloud link (soundcloud.com/yourname).')
            ->connectInput('url', ['required', 'string', 'max:500'])
            ->displayToggles([
                ['key' => 'auto_sync_latest_track', 'label' => 'Newest track', 'description' => 'Your newest track joins your site automatically.'],
            ])
            ->refreshEvery((int) config('partna.refresh.intervals.soundcloud', 12 * 3600))
            ->routes(PlatformRouteShape::MultiAccount, null, true);
    }
}
