<?php

namespace App\Services\SmartLinks\Extractors;

use App\Services\SmartLinks\ParsedUrl;
use App\Services\SmartLinks\ResolvedSmartLinkData;
use App\Services\SmartLinks\SafeUrlException;
use App\Services\SmartLinks\SafeUrlFetcher;

/**
 * oEmbed extractor for content links: Spotify, YouTube, Bandcamp. Hands the
 * URL to the provider's public oEmbed endpoint and maps title / thumbnail /
 * author. Content images are hotlinked (not rehosted), so the thumbnail URL
 * is stored as-is.
 */
class OEmbedExtractor implements SmartLinkExtractor
{
    private const PROVIDERS = [
        'spotify' => ['suffix' => 'open.spotify.com', 'endpoint' => 'https://open.spotify.com/oembed', 'display' => 'Spotify'],
        'youtube' => ['suffix' => 'youtube.com', 'endpoint' => 'https://www.youtube.com/oembed', 'display' => 'YouTube'],
        'youtube_short' => ['suffix' => 'youtu.be', 'endpoint' => 'https://www.youtube.com/oembed', 'display' => 'YouTube'],
        'bandcamp' => ['suffix' => 'bandcamp.com', 'endpoint' => 'https://bandcamp.com/oembed', 'display' => 'Bandcamp'],
    ];

    public function __construct(
        private readonly SafeUrlFetcher $fetcher,
    ) {}

    public function matches(ParsedUrl $url, string $type): bool
    {
        if (! str_starts_with($type, 'content.music') && $type !== 'content.video') {
            return false;
        }

        return $this->providerFor($url) !== null;
    }

    public function resolve(ParsedUrl $url, string $type): ?ResolvedSmartLinkData
    {
        $provider = $this->providerFor($url);
        if ($provider === null) {
            return null;
        }
        [$key, $cfg] = $provider;

        $endpoint = $cfg['endpoint'].'?format=json&url='.urlencode($url->canonical);
        try {
            $res = $this->fetcher->fetch($endpoint, ['Accept' => 'application/json']);
        } catch (SafeUrlException) {
            return null;
        }
        if ($res['status'] >= 400) {
            return null;
        }
        $json = json_decode($res['body'], true);
        if (! is_array($json)) {
            return null;
        }

        $platform = $key === 'youtube_short' ? 'youtube' : $key;
        $title = $this->str($json['title'] ?? null);
        $thumb = $this->str($json['thumbnail_url'] ?? null);
        $author = $this->str($json['author_name'] ?? null);

        if ($type === 'content.video') {
            return new ResolvedSmartLinkData(
                title: $title,
                imageUrl: $thumb,
                brandName: $cfg['display'],
                platform: $platform,
                metadata: array_filter(['channelName' => $author], fn ($v) => $v !== null),
            );
        }

        // content.music.{track|album|playlist}
        $subType = $this->subTypeFromType($type);

        return new ResolvedSmartLinkData(
            title: $title,
            imageUrl: $thumb,
            brandName: $cfg['display'],
            platform: $platform,
            metadata: array_filter(['subType' => $subType], fn ($v) => $v !== null),
        );
    }

    /** @return array{0:string,1:array}|null [providerKey, config] */
    private function providerFor(ParsedUrl $url): ?array
    {
        foreach (self::PROVIDERS as $key => $cfg) {
            if ($url->host === $cfg['suffix'] || str_ends_with($url->host, '.'.$cfg['suffix'])) {
                return [$key, $cfg];
            }
        }

        return null;
    }

    private function subTypeFromType(string $type): ?string
    {
        $parts = explode('.', $type);

        return end($parts) ?: null;
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
