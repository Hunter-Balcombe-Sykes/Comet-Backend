<?php

namespace App\Services\Platforms\ScrapeCreators;

// Item 11f (2026-09-01): /v1/spotify/podcast → the show identity card for the
// listen lane's Spotify-podcasts source (the sibling of what AppleSearch
// resolves for apple_podcasts at connect time: name/thumbnail/description/
// link). Spotify calls podcasts "shows"; the payload is show-scoped GraphQL
// riddled with __typename markers and credits_* billing fields, so the card
// is SYNTHESIZED, never spread.
//
// Gate on payload shape, never HTTP status: a NotFound answer bills a credit
// and arrives as success:true with __typename "NotFound" (recorded 2026-09-01,
// scrapecreators-spotify-podcast-notfound.json) — the twin of
// SpotifyArtistNormalizer's "Artist" gate.
class SpotifyPodcastNormalizer
{
    /**
     * @param  array<string, mixed>  $body  the full vendor response body
     * @return array<string, mixed>|null null unless the payload positively
     *                                   carries a show (typename + id + name) — a husk must read as
     *                                   "vendor miss", never as an empty show.
     */
    public function show(array $body): ?array
    {
        if (($body['__typename'] ?? null) !== 'Podcast') {
            return null;
        }

        $id = is_string($body['id'] ?? null) ? trim($body['id']) : '';
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';
        if ($id === '' || $name === '') {
            return null;
        }

        $publisher = is_array($body['publisher'] ?? null) ? ($body['publisher']['name'] ?? null) : null;
        $description = is_string($body['description'] ?? null) ? trim($body['description']) : '';

        return [
            'id' => $id,
            'name' => $name,
            // Derived, not sharingInfo.shareUrl — that one carries a
            // per-request ?si= share token, which would make the same show a
            // "changed" card on every refresh.
            'url' => 'https://open.spotify.com/show/'.$id,
            'publisher' => is_string($publisher) && trim($publisher) !== '' ? trim($publisher) : null,
            // description, not htmlDescription — the card is plain text.
            'description' => $description === '' ? null : $description,
            'artwork' => $this->coverUrl($body['coverArt'] ?? null),
        ];
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
