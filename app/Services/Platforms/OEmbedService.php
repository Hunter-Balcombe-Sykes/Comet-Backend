<?php

namespace App\Services\Platforms;

use App\Services\Http\SafeUrlFetcher;

// Resolves a public oEmbed endpoint (no auth, no key) into the small profile
// the music-embed platforms store: display name, artwork, and the official
// embed-player URL. Spotify (open.spotify.com/oembed) and SoundCloud
// (soundcloud.com/oembed) both ship these; the iframe src is taken from the
// `iframe_url` field when present (Spotify) or parsed out of the returned
// `html` snippet (SoundCloud).
class OEmbedService extends PlatformScraper
{
    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /**
     * @return array{name:?string, thumbnail:?string, embedUrl:?string}|null
     *                                                                       null when the endpoint is unreachable or returns no usable JSON.
     */
    public function resolve(string $oembedEndpoint): ?array
    {
        $res = $this->fetcher->tryFetch($oembedEndpoint, ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json']);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }

        $data = json_decode($res['body'], true);
        if (! is_array($data)) {
            return null;
        }

        $embedUrl = $data['iframe_url'] ?? null;
        if (! is_string($embedUrl) && is_string($data['html'] ?? null)
            && preg_match('~src="([^"]+)"~i', $data['html'], $m)) {
            $embedUrl = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
        }

        return [
            'name' => is_string($data['title'] ?? null) ? trim($data['title']) : null,
            'thumbnail' => is_string($data['thumbnail_url'] ?? null) ? $data['thumbnail_url'] : null,
            'embedUrl' => is_string($embedUrl) ? $embedUrl : null,
        ];
    }
}
