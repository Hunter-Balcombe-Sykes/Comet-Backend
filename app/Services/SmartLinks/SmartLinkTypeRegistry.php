<?php

namespace App\Services\SmartLinks;

use App\Services\SmartLinks\Extractors\ITunesExtractor;
use App\Services\SmartLinks\Extractors\OEmbedExtractor;
use App\Services\SmartLinks\Extractors\ShopifyExtractor;
use App\Services\SmartLinks\Extractors\SmartLinkExtractor;
use App\Services\SmartLinks\Extractors\StructuredDataExtractor;

/**
 * Maps a dashboard selection (a commerce type, or a content platform) + a URL
 * to the precise namespaced smart-link type, and owns each type's ordered
 * extractor chain. Adding a platform = a branch here + (maybe) an extractor.
 */
class SmartLinkTypeRegistry
{
    public const COMMERCE_SELECTIONS = ['brand', 'collection', 'product', 'event'];

    public const CONTENT_PLATFORMS = ['spotify', 'apple_music', 'bandcamp', 'apple_podcasts', 'youtube'];

    public function __construct(
        private readonly ShopifyExtractor $shopify,
        private readonly StructuredDataExtractor $structured,
        private readonly OEmbedExtractor $oembed,
        private readonly ITunesExtractor $itunes,
    ) {}

    /**
     * Resolve the precise type from a selection + URL, or null when the URL
     * doesn't match the selection (e.g. a Spotify pick with a YouTube URL).
     */
    public function resolveType(ParsedUrl $url, string $selection): ?string
    {
        if (in_array($selection, self::COMMERCE_SELECTIONS, true)) {
            return 'commerce.'.$selection;
        }

        return match ($selection) {
            'spotify' => $this->endsWith($url, 'open.spotify.com') ? $this->spotifySub($url) : null,
            'apple_music' => $url->host === 'music.apple.com' ? $this->appleMusicSub($url) : null,
            'bandcamp' => $this->endsWith($url, 'bandcamp.com') ? $this->bandcampSub($url) : null,
            'apple_podcasts' => ($url->host === 'podcasts.apple.com' && isset($url->essentialQuery['i']))
                ? 'content.podcast.episode'
                : null,
            'youtube' => $this->isYoutube($url) ? 'content.video' : null,
            default => null,
        };
    }

    public function familyOf(string $type): string
    {
        return str_starts_with($type, 'commerce.') ? 'commerce' : 'content';
    }

    /** @return SmartLinkExtractor[] ordered chain — first non-null resolve() wins. */
    public function extractorChain(string $type): array
    {
        return match (true) {
            $type === 'commerce.product' => [$this->shopify, $this->structured],
            $type === 'commerce.collection' => [$this->shopify],
            $type === 'commerce.brand', $type === 'commerce.event' => [$this->structured],
            str_starts_with($type, 'content.music') => [$this->oembed, $this->itunes],
            $type === 'content.podcast.episode' => [$this->itunes],
            $type === 'content.video' => [$this->oembed],
            default => [],
        };
    }

    // ── content sub-type detection ───────────────────────────────────────────

    private function spotifySub(ParsedUrl $url): ?string
    {
        $seg = $url->segment(0);

        return in_array($seg, ['track', 'album', 'playlist'], true) ? "content.music.{$seg}" : null;
    }

    private function appleMusicSub(ParsedUrl $url): ?string
    {
        $segs = $url->pathSegments;
        if (in_array('playlist', $segs, true)) {
            return null; // Apple Music playlists cut (no catalog API support)
        }
        if (in_array('album', $segs, true) || in_array('song', $segs, true)) {
            return isset($url->essentialQuery['i']) ? 'content.music.track' : 'content.music.album';
        }

        return null;
    }

    private function bandcampSub(ParsedUrl $url): ?string
    {
        if (in_array('track', $url->pathSegments, true)) {
            return 'content.music.track';
        }
        if (in_array('album', $url->pathSegments, true)) {
            return 'content.music.album';
        }

        return null; // artist/label root page — not a track/album
    }

    private function isYoutube(ParsedUrl $url): bool
    {
        return $this->endsWith($url, 'youtube.com') || $url->host === 'youtu.be';
    }

    private function endsWith(ParsedUrl $url, string $suffix): bool
    {
        return $url->host === $suffix || str_ends_with($url->host, '.'.$suffix);
    }
}
