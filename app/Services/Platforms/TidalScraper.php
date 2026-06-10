<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;

// TIDAL ships a public oEmbed endpoint (oembed.tidal.com) that returns the
// official embed-player iframe for any entity link — but unlike Spotify's it
// carries no title or artwork, so the entity page's own og tags fill those
// in. Both reads are keyless.
class TidalScraper extends PlatformScraper
{
    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /**
     * Canonicalize any tidal.com / listen.tidal.com entity link.
     *
     * @return array{type:string, id:string, link:string}|null
     */
    public function parseEntity(string $url): ?array
    {
        if (preg_match('~^https?://(?:www\.|listen\.)?tidal\.com/(?:browse/)?(artist|album|track|playlist|video|mix)/([A-Za-z0-9-]+)~i', trim($url), $m)) {
            $type = strtolower($m[1]);

            return ['type' => $type, 'id' => $m[2], 'link' => "https://tidal.com/browse/{$type}/{$m[2]}"];
        }

        return null;
    }

    /**
     * og-scraped display name + artwork from the public entity page. Null
     * fields are fine — the embed player still renders without them.
     *
     * @return array{name:?string, thumbnail:?string}
     */
    public function fetchMeta(string $link): array
    {
        $res = $this->fetcher->tryFetch($link, ['User-Agent' => self::USER_AGENT]);
        if ($res === null || $res['status'] !== 200) {
            return ['name' => null, 'thumbnail' => null];
        }
        $html = $res['body'];

        $name = $this->metaContent($html, 'og:title');

        return [
            // og:title is "Name on TIDAL" / "Name - TIDAL".
            'name' => $name !== null ? (trim(preg_replace('~\s*(?:on|[-|])\s*TIDAL\s*$~i', '', $name)) ?: $name) : null,
            'thumbnail' => $this->metaContent($html, 'og:image'),
        ];
    }

    /** Deterministic embed src (oEmbed fallback): embed.tidal.com/{type}s/{id}. */
    public static function embedUrlFor(string $type, string $id): string
    {
        return "https://embed.tidal.com/{$type}s/{$id}";
    }
}
