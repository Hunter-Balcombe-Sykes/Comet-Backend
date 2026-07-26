<?php

namespace App\Services\V5\Scraping\Normalization;

// V5 PlatformUrlNormalizer — configurable regex-based URL normalization.
// Replaces 11 per-platform normalizer classes with one class + per-platform patterns.
class PlatformUrlNormalizer
{
    /**
     * Built-in normalization patterns per platform slug.
     */
    private const PATTERNS = [
        'youtube' => [
            'channel' => '#(?:youtube\.com/(?:channel/|@|c/|user/)|youtu\.be/)([\w\-]+)#i',
            'video' => '#(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/shorts/)([\w\-]+)#i',
        ],
        'instagram' => [
            'handle' => '#instagram\.com/([\w.]+)/?#i',
        ],
        'tiktok' => [
            'handle' => '#tiktok\.com/@([\w.]+)#i',
        ],
        'twitter' => [
            'handle' => '#(?:twitter\.com|x\.com)/(\w+)#i',
        ],
        'facebook' => [
            'handle' => '#facebook\.com/([\w.]+)/?#i',
        ],
        'twitch' => [
            'channel' => '#twitch\.tv/(\w+)#i',
        ],
        'spotify' => [
            'artist' => '#spotify\.com/artist/(\w+)#i',
            'track' => '#spotify\.com/track/(\w+)#i',
            'album' => '#spotify\.com/album/(\w+)#i',
        ],
        'bandcamp' => [
            'subdomain' => '#(\w+)\.bandcamp\.com#i',
        ],
        'eventbrite' => [
            'organizer' => '#eventbrite\.com(?:\.au)?/o/([\w\-]+)#i',
            'event' => '#eventbrite\.com(?:\.au)?/e/([\w\-]+)#i',
        ],
        'vimeo' => [
            'user' => '#vimeo\.com/(\w+)/?#i',
        ],
    ];

    /**
     * Normalize a URL or handle for a given platform.
     * Returns canonical handle/resource_id or null if no pattern matches.
     */
    public function normalize(string $platform, string $input, string $type = 'handle'): ?string
    {
        $patterns = self::PATTERNS[$platform] ?? [];
        $pattern = $patterns[$type] ?? null;

        if (! $pattern) {
            return $this->normalizeFallback($input);
        }

        if (preg_match($pattern, $input, $m)) {
            return $m[1];
        }

        return null;
    }

    /** Last-resort normalization: strip @, trim, lowercase. */
    private function normalizeFallback(string $input): string
    {
        return mb_strtolower(trim(ltrim(trim($input), '@')));
    }

    /** Check if a URL matches any known platform pattern (for router). */
    public function detect(string $url): ?string
    {
        foreach (self::PATTERNS as $platform => $patterns) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $url)) {
                    return $platform;
                }
            }
        }
        return null;
    }
}
