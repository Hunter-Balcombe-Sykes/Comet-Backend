<?php

namespace App\Services\SmartLinks\Extractors;

use App\Services\SmartLinks\MetadataParser;
use App\Services\SmartLinks\ParsedUrl;
use App\Services\SmartLinks\ResolvedSmartLinkData;
use App\Services\SmartLinks\SafeUrlException;
use App\Services\SmartLinks\SafeUrlFetcher;

/**
 * Apple extractor:
 *   - Apple Music album/track via the free iTunes Lookup API (clean cover +
 *     name; no auth). (Playlists are not supported by the catalog API — cut.)
 *   - Apple Podcasts episode: OG-scrape of the episode page is primary
 *     (limit-free), enriched by iTunes lookup (show id + entity=podcastEpisode,
 *     match the `?i=` episode id) for show name + release date when in-window.
 * Content images are hotlinked.
 */
class ITunesExtractor implements SmartLinkExtractor
{
    private const LOOKUP = 'https://itunes.apple.com/lookup';

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
        private readonly MetadataParser $parser,
    ) {}

    public function matches(ParsedUrl $url, string $type): bool
    {
        return match ($type) {
            'content.music.track', 'content.music.album' => $url->host === 'music.apple.com',
            'content.podcast.episode' => $url->host === 'podcasts.apple.com',
            default => false,
        };
    }

    public function resolve(ParsedUrl $url, string $type): ?ResolvedSmartLinkData
    {
        return match ($type) {
            'content.music.track' => $this->resolveMusic($url, 'track'),
            'content.music.album' => $this->resolveMusic($url, 'album'),
            'content.podcast.episode' => $this->resolveEpisode($url),
            default => null,
        };
    }

    private function resolveMusic(ParsedUrl $url, string $subType): ?ResolvedSmartLinkData
    {
        // Track id is the `?i=` param; album id is the trailing numeric path segment.
        $id = $subType === 'track'
            ? ($url->essentialQuery['i'] ?? null)
            : $this->numericTail($url);
        if (! $id) {
            return null;
        }

        $result = $this->lookupFirst(['id' => $id]);
        if ($result === null) {
            return null;
        }

        $title = $this->str($result['trackName'] ?? $result['collectionName'] ?? null);
        $artwork = $this->upscale($this->str($result['artworkUrl100'] ?? $result['artworkUrl60'] ?? null));

        return new ResolvedSmartLinkData(
            title: $title,
            imageUrl: $artwork,
            brandName: 'Apple Music',
            platform: 'apple_music',
            metadata: array_filter(['subType' => $subType], fn ($v) => $v !== null),
        );
    }

    private function resolveEpisode(ParsedUrl $url): ?ResolvedSmartLinkData
    {
        // Primary: OG of the episode page (limit-free).
        $ogTitle = null;
        $ogImage = null;
        $favicon = null;
        try {
            $res = $this->fetcher->fetch($url->canonical);
            if ($res['status'] < 400 && str_contains(strtolower($res['contentType']), 'html')) {
                $meta = $this->parser->parse($res['body'], $url->canonical);
                $ogTitle = $meta->og('og:title') ?? $meta->title;
                $ogImage = $meta->og('og:image');
                $favicon = $meta->faviconUrl;
            }
        } catch (SafeUrlException) {
            // fall through to iTunes-only
        }

        // Enrich via iTunes (show id from path `id{N}`, episode id from `?i=`).
        $showId = $this->showId($url);
        $episodeId = $url->essentialQuery['i'] ?? null;
        $showName = null;
        $releaseDate = null;
        $itunesArtwork = null;
        if ($showId && $episodeId) {
            $episode = $this->lookupEpisode($showId, (string) $episodeId);
            if ($episode !== null) {
                $ogTitle ??= $this->str($episode['trackName'] ?? null);
                $showName = $this->str($episode['collectionName'] ?? null);
                $releaseDate = $this->str($episode['releaseDate'] ?? null);
                $itunesArtwork = $this->upscale($this->str($episode['artworkUrl600'] ?? $episode['artworkUrl160'] ?? null));
            }
        }

        $title = $ogTitle;
        if ($title === null) {
            return null;
        }

        return new ResolvedSmartLinkData(
            title: $title,
            imageUrl: $ogImage ?? $itunesArtwork,
            faviconUrl: $favicon,
            brandName: $showName ?? 'Apple Podcasts',
            platform: 'apple_podcasts',
            metadata: array_filter([
                'showName' => $showName,
                'releaseDate' => $releaseDate,
            ], fn ($v) => $v !== null),
        );
    }

    /** @param array<string,string> $params */
    private function lookupFirst(array $params): ?array
    {
        $url = self::LOOKUP.'?'.http_build_query($params);
        try {
            $res = $this->fetcher->fetch($url, ['Accept' => 'application/json']);
        } catch (SafeUrlException) {
            return null;
        }
        if ($res['status'] >= 400) {
            return null;
        }
        $json = json_decode($res['body'], true);
        $results = $json['results'] ?? null;

        return is_array($results) && isset($results[0]) && is_array($results[0]) ? $results[0] : null;
    }

    private function lookupEpisode(string $showId, string $episodeId): ?array
    {
        $url = self::LOOKUP.'?'.http_build_query([
            'id' => $showId,
            'entity' => 'podcastEpisode',
            'limit' => 200,
        ]);
        try {
            $res = $this->fetcher->fetch($url, ['Accept' => 'application/json']);
        } catch (SafeUrlException) {
            return null;
        }
        if ($res['status'] >= 400) {
            return null;
        }
        $json = json_decode($res['body'], true);
        foreach (($json['results'] ?? []) as $row) {
            if (is_array($row) && (string) ($row['trackId'] ?? '') === $episodeId) {
                return $row;
            }
        }

        return null;
    }

    /** Last numeric path segment (album id). */
    private function numericTail(ParsedUrl $url): ?string
    {
        for ($i = count($url->pathSegments) - 1; $i >= 0; $i--) {
            $seg = $url->pathSegments[$i];
            if (ctype_digit($seg)) {
                return $seg;
            }
        }

        return null;
    }

    /** Podcast show id from a path segment like `id1234567890`. */
    private function showId(ParsedUrl $url): ?string
    {
        foreach ($url->pathSegments as $seg) {
            if (preg_match('/^id(\d+)$/', $seg, $m)) {
                return $m[1];
            }
        }

        return null;
    }

    /** Apple artwork URLs are sized in the filename — bump 100x100 → 600x600. */
    private function upscale(?string $art): ?string
    {
        if ($art === null) {
            return null;
        }

        return preg_replace('/\/\d+x\d+(bb|cc)?\.(jpg|png|webp)$/i', '/600x600bb.$2', $art) ?: $art;
    }

    private function str(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $t = trim($v);

        return $t === '' ? null : $t;
    }
}
