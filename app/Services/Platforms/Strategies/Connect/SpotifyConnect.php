<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\OEmbedService;
use App\Services\Platforms\PlatformInput;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\DeferredConnect;

// Spotify connect: any entity link (artist/album/playlist/track/show/episode/
// user) → public oEmbed resolves name + artwork keylessly; the embed player URL
// is derived from entity type + id. Moved verbatim from SpotifyController.
class SpotifyConnect implements DeferredConnect
{
    public function __construct(private readonly OEmbedService $oembed) {}

    public function resolve(string $input): ConnectResult
    {
        $entity = $this->parseEntity($input);
        if (! $entity) {
            return ConnectResult::fail(); // descriptor's parse-fail message
        }
        [$type, $id] = $entity;
        $link = "https://open.spotify.com/{$type}/{$id}";

        $resolved = $this->oembed->resolve('https://open.spotify.com/oembed?url='.rawurlencode($link));
        if ($resolved === null) {
            return ConnectResult::fail('Could not load that Spotify link.');
        }

        return ConnectResult::ok([
            'url' => $link,
            'name' => $resolved['name'],
            'thumbnail' => $resolved['thumbnail'],
            // The embed URL is deterministic; oEmbed's iframe_url is preferred
            // but the constructed form covers a missing field.
            'embedUrl' => $resolved['embedUrl'] ?? "https://open.spotify.com/embed/{$type}/{$id}",
            'link' => $link,
        ]);
    }

    // DeferredConnect — no network. Writes exactly what OEmbedFetch reads
    // (link ?? url) plus the deterministic embedUrl, so a pending Spotify row
    // already renders a working player before the eventual job upgrades
    // name/thumbnail from oEmbed.
    public function identify(string $input): ConnectResult
    {
        $entity = $this->parseEntity($input);
        if (! $entity) {
            return ConnectResult::fail(); // same as resolve() — descriptor's parse-fail message
        }
        [$type, $id] = $entity;
        $link = "https://open.spotify.com/{$type}/{$id}";

        return ConnectResult::ok([
            'url' => $link,
            'link' => $link,
            'embedUrl' => "https://open.spotify.com/embed/{$type}/{$id}",
        ]);
    }

    /**
     * [type, id] from an ACCOUNT-kind entity link. T6b (2026-08-20): the
     * manual connect door narrows exactly as the detector did — track/album/
     * episode are ITEMS (they belong in the watch/listen pools, and a
     * connection row named by an episode id is the natalieannehair bug
     * through the front door). Playlist kept — the same owner-flagged call
     * as the detector.
     *
     * @return array{0:string, 1:string}|null
     */
    private function parseEntity(string $url): ?array
    {
        // `show` removed 2026-09-01 (Item 11f): shows connect through the
        // spotify_podcasts brand (SpotifyPodcastsConnect) — the same
        // narrowing move T6b made for items, applied to the podcast kind.
        if (preg_match('~^https?://open\.spotify\.com/(?:intl-[a-z]{2}(?:-[a-z]{2})?/)?(artist|playlist|user)/([A-Za-z0-9]+)~i', PlatformInput::urlish($url), $m)) {
            return [strtolower($m[1]), $m[2]];
        }

        return null;
    }
}
