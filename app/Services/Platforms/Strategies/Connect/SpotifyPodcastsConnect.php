<?php

namespace App\Services\Platforms\Strategies\Connect;

use App\Services\Platforms\PlatformInput;
use App\Services\Platforms\SpotifyPodcastsScraper;
use App\Services\Platforms\Strategies\Contracts\ConnectResult;
use App\Services\Platforms\Strategies\Contracts\DeferredConnect;

// Item 11f (2026-09-01): Spotify Podcasts connect — an open.spotify.com/show
// link → the show's identity card (name/artwork/description/publisher) via
// the budget-claimed ScrapeCreators lane in SpotifyPodcastsScraper. The
// sibling of what AppleController resolves for apple_podcasts at connect
// time, on the SpotifyConnect strategy frame: show links only — a track/
// album/episode link is an ITEM and stays with the pools (T6b), and the
// artist/playlist/user kinds already belong to the spotify.player surface.
class SpotifyPodcastsConnect implements DeferredConnect
{
    public function __construct(private readonly SpotifyPodcastsScraper $scraper) {}

    public function resolve(string $input): ConnectResult
    {
        $id = $this->parseShow($input);
        if ($id === null) {
            return ConnectResult::fail(); // descriptor's parse-fail message
        }
        $link = 'https://open.spotify.com/show/'.$id;

        $card = $this->scraper->fetchShow($id);
        if ($card === null) {
            return ConnectResult::fail('Could not load that Spotify show.');
        }

        return ConnectResult::ok([
            'url' => $link,
            'link' => $link,
            'name' => $card['name'],
            'thumbnail' => $card['artwork'],
            'description' => $card['description'],
            'publisher' => $card['publisher'],
        ]);
    }

    // DeferredConnect — no network. Writes exactly what SpotifyPodcastsFetch
    // reads (link ?? url), both derived from the canonical show link, so a
    // pending row already carries the identity the eventual job re-resolves.
    public function identify(string $input): ConnectResult
    {
        $id = $this->parseShow($input);
        if ($id === null) {
            return ConnectResult::fail(); // same as resolve() — descriptor's parse-fail message
        }
        $link = 'https://open.spotify.com/show/'.$id;

        return ConnectResult::ok(['url' => $link, 'link' => $link]);
    }

    // One parse for both paths (DeferredConnectParityTest's contract) — the
    // grammar itself lives on the scraper so the vendor driver agrees too.
    private function parseShow(string $input): ?string
    {
        return SpotifyPodcastsScraper::showId(PlatformInput::urlish($input));
    }
}
