<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 11f (2026-09-01): /v1/spotify/podcast/episodes → listen-pool episode
// rows in the EXACT vocabulary ApplePodcastsConnector lands and
// ApplePodcastsEpisodeProjector already projects (trackId/trackName/
// collectionName/releaseDate/artworkUrl600/trackViewUrl/description) — one
// projector, two sources, no new pool semantics. Rows are SYNTHESIZED, never
// spread, so credits_* and vendor-only keys can never leak into a persisted
// payload.
//
// Trial-verified quirks absorbed here (recorded payloads 2026-09-01, Huberman
// Lab + The Joe Rogan Experience):
//  - restricted entries ride INSIDE an otherwise-good list as
//    {"__typename": "RestrictedContent"} husks (JRE, a Spotify exclusive,
//    answers one mid-list) — each entry is gated on __typename "Episode" and
//    skipped alone, and a list that yields NO usable rows reads as "vendor
//    miss", never as an empty catalogue.
//  - sharingInfo.shareUrl carries a per-request ?si= share token — the link
//    is derived from the id so an unchanged episode never reads as changed.
class SpotifyEpisodesNormalizer
{
    /**
     * @param  array<string, mixed>  $body  one /v1/spotify/podcast/episodes page
     * @return non-empty-list<array<string, mixed>>|null
     */
    public function episodes(array $body): ?array
    {
        $entries = $body['episodes'] ?? null;
        if (! is_array($entries)) {
            return null;
        }

        $rows = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || ($entry['__typename'] ?? null) !== 'Episode') {
                continue;
            }

            $id = is_string($entry['id'] ?? null) ? trim($entry['id']) : '';
            $name = is_string($entry['name'] ?? null) ? trim($entry['name']) : '';
            if ($id === '' || $name === '') {
                continue;
            }

            $show = is_array($entry['podcastV2'] ?? null) ? ($entry['podcastV2']['name'] ?? null) : null;
            $release = is_array($entry['releaseDate'] ?? null) ? ($entry['releaseDate']['isoString'] ?? null) : null;
            $description = is_string($entry['description'] ?? null) ? trim($entry['description']) : '';

            $rows[] = [
                'trackId' => $id,
                'trackName' => $name,
                // The show's name rides as collectionName — the projector
                // surfaces it as the creator, and an episode card without its
                // show is unattributable (same doctrine as apple_podcasts).
                'collectionName' => is_string($show) && trim($show) !== '' ? trim($show) : null,
                'releaseDate' => is_string($release) && $release !== '' ? $release : null,
                'artworkUrl600' => $this->coverUrl($entry['coverArt'] ?? null),
                'trackViewUrl' => 'https://open.spotify.com/episode/'.$id,
                'description' => $description === '' ? null : $description,
            ];
        }

        return $rows === [] ? null : $rows;
    }

    /**
     * coverArt.sources[] → one URL, largest first. Podcast covers carry an
     * explicit width on every source (unlike the album-art surface), so no
     * CDN-marker fallback is needed here.
     */
    private function coverUrl(mixed $coverArt): ?string
    {
        $sources = is_array($coverArt) ? ($coverArt['sources'] ?? null) : null;

        $best = null;
        foreach (is_array($sources) ? $sources : [] as $source) {
            $url = is_array($source) ? ($source['url'] ?? null) : null;
            if (! is_string($url) || ! preg_match('~^https?://~', $url)) {
                continue;
            }
            $width = is_numeric($source['width'] ?? null) ? (int) $source['width'] : 0;
            if ($best === null || $width > $best[0]) {
                $best = [$width, $url];
            }
        }

        return $best[1] ?? null;
    }
}
