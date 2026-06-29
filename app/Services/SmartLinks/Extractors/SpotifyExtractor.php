<?php

namespace App\Services\SmartLinks\Extractors;

use App\Services\Http\MetadataParser;
use App\Services\Http\ParsedMetadata;
use App\Services\Http\ParsedUrl;
use App\Services\Http\SafeUrlException;
use App\Services\Http\SafeUrlFetcher;
use App\Services\SmartLinks\Extractors\Concerns\ExtractorHelpers;
use App\Services\SmartLinks\ResolvedSmartLinkData;

/**
 * Spotify OG extractor — one fetch of the canonical open.spotify.com page gives
 * everything, including the artist + album that Spotify's oEmbed does NOT return:
 *   - music (track/album): og:title (name), og:image (art),
 *     music:musician_description (artist), og:description (`Artist · Album · …`).
 *   - podcast episode: og:title (episode) + og:image (art). The show name isn't
 *     reliably exposed in Spotify episode OG, so it's left null (the card line
 *     degrades to episode-only per the 0.1 contract); Apple Podcasts supplies
 *     show names via iTunes.
 *
 * Content images are hotlinked (the resolver only rehosts commerce), so og:image
 * is stored as-is. oEmbed stays the fallback for music when OG parsing fails —
 * see the content.music chain order in SmartLinkTypeRegistry.
 */
class SpotifyExtractor implements SmartLinkExtractor
{
    use ExtractorHelpers;

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly MetadataParser $parser,
    ) {}

    public function matches(ParsedUrl $url, string $type): bool
    {
        if (! str_starts_with($type, 'content.music') && $type !== 'content.podcast.episode') {
            return false;
        }

        return $url->host === 'open.spotify.com' || str_ends_with($url->host, '.spotify.com');
    }

    public function resolve(ParsedUrl $url, string $type): ?ResolvedSmartLinkData
    {
        $meta = $this->fetchMeta($url->canonical);
        if ($meta === null) {
            return null;
        }

        $title = $meta->og('og:title') ?? $meta->title;
        if ($title === null) {
            return null; // let the chain fall through to oEmbed
        }
        $image = $meta->og('og:image');

        if ($type === 'content.podcast.episode') {
            return new ResolvedSmartLinkData(
                title: $title,
                imageUrl: $image,
                brandName: 'Spotify',
                platform: 'spotify',
                metadata: ['subType' => 'episode'],
            );
        }

        $artist = $this->artist($meta);
        $album = $this->album($meta, $artist);

        return new ResolvedSmartLinkData(
            title: $title,
            imageUrl: $image,
            brandName: 'Spotify',
            platform: 'spotify',
            metadata: array_filter([
                'subType' => $this->subTypeFromType($type),
                'artist' => $artist,
                'album' => $album,
            ], fn ($v) => $v !== null),
        );
    }

    private function fetchMeta(string $url): ?ParsedMetadata
    {
        try {
            $res = $this->fetcher->fetch($url);
        } catch (SafeUrlException) {
            return null;
        }
        if ($res['status'] >= 400 || ! str_contains(strtolower($res['contentType']), 'html')) {
            return null;
        }

        return $this->parser->parse($res['body'], $url);
    }

    /** Clean artist from the dedicated meta, else the first og:description segment. */
    private function artist(ParsedMetadata $meta): ?string
    {
        $direct = $this->str($meta->meta['music:musician_description'] ?? null);
        if ($direct !== null) {
            return $direct;
        }

        return $this->descSegments($meta)[0] ?? null;
    }

    /**
     * Album from og:description (`Artist · Album · Song · Year`). Drop the artist,
     * any 4-digit year, and entity-type words; the first survivor is the album.
     */
    private function album(ParsedMetadata $meta, ?string $artist): ?string
    {
        $typeWords = ['song', 'single', 'album', 'ep', 'e.p.', 'playlist', 'compilation'];
        foreach ($this->descSegments($meta) as $seg) {
            if ($artist !== null && strcasecmp($seg, $artist) === 0) {
                continue;
            }
            if (preg_match('/^\d{4}$/', $seg) || in_array(strtolower($seg), $typeWords, true)) {
                continue;
            }

            return $seg;
        }

        return null;
    }

    /** @return list<string> og:description split on Spotify's " · " separator. */
    private function descSegments(ParsedMetadata $meta): array
    {
        $desc = $meta->og('og:description');
        if ($desc === null) {
            return [];
        }
        $parts = preg_split('/\s*·\s*/u', $desc) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }
}
