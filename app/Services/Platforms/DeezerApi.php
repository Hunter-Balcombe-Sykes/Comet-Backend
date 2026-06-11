<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;

// Deezer's public JSON API is fully open (api.deezer.com/artist/{id} — no
// key), and the official widget (widget.deezer.com/widget/auto/artist/{id})
// embeds keylessly. Connect accepts any deezer.com artist link; the stored
// shape matches the Spotify/SoundCloud music-embed contract.
class DeezerApi
{
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /** Artist id from a deezer.com artist URL (any locale prefix). */
    public function parseArtistId(string $url): ?string
    {
        if (preg_match('~^https?://(?:www\.)?deezer\.com/(?:[a-z]{2}/)?artist/(\d+)~i', trim($url), $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @return array{name:?string, thumbnail:?string, link:string, fans:?int}|null
     */
    public function fetchArtist(string $id): ?array
    {
        $res = $this->fetcher->tryFetch("https://api.deezer.com/artist/{$id}", ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json']);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }

        $data = json_decode($res['body'], true);
        // The API answers 200 with an error object for unknown ids.
        if (! is_array($data) || isset($data['error']) || ! isset($data['id'])) {
            return null;
        }

        return [
            'name' => is_string($data['name'] ?? null) ? trim($data['name']) : null,
            'thumbnail' => $data['picture_big'] ?? $data['picture_medium'] ?? null,
            'link' => $data['link'] ?? "https://www.deezer.com/artist/{$id}",
            'fans' => isset($data['nb_fan']) ? (int) $data['nb_fan'] : null,
        ];
    }

    /**
     * Official keyless widget src — 'auto' follows the visitor's theme.
     * Artist widgets need the /top_tracks tracklist suffix: the bare
     * /artist/{id} URL answers 200 but renders an empty frame.
     */
    public static function embedUrlForArtist(string $id): string
    {
        return "https://widget.deezer.com/widget/auto/artist/{$id}/top_tracks";
    }
}
