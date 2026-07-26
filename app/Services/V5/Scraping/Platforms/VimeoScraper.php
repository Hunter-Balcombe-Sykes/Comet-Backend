<?php

namespace App\Services\V5\Scraping\Platforms;

use App\Services\V5\Scraping\BaseTemplates\ApiBase;
use App\Services\V5\Scraping\Contracts\FetchContract;

// V5 Vimeo scraper — resolves a vimeo.com profile or channel URL to the
// latest uploads via the legacy Simple API v2. No auth needed.
//
// The Simple API is keyless: /api/v2/{user}/info.json for the profile and
// /api/v2/{user}/videos.json for the latest uploads (channels use the
// /api/v2/channel/{name}/... form). Returns profile data + up to 20 videos.
// Each video carries a player embed URL (player.vimeo.com/video/{id}) that
// the sitepage can render without credentials.
// Replaces VimeoApi.
class VimeoScraper extends ApiBase implements FetchContract
{
    protected string $endpoint = 'https://vimeo.com/api';
    protected string $authType = 'none';

    // Reserved paths that are video pages or system routes, not profiles.
    private const RESERVED = [
        'watch', 'upload', 'features', 'enterprise', 'pricing', 'blog',
        'help', 'about', 'jobs', 'stats', 'search', 'categories', 'ondemand',
        'settings', 'log_in', 'join', 'site_map', 'solutions',
    ];

    /**
     * Fetch profile info and latest videos for a Vimeo profile/channel URL.
     *
     * @return array{items:list<array{identifier:string, name:?string, item_type:string, values:list<array{field_name:string, value:mixed, format:string}>}>, profile:array{display_name:?string, profile_pic_url:?string, bio:?string}}
     */
    public function fetch(string $identifier): array
    {
        $parsed = $this->parseUrl($identifier);
        if (! $parsed) {
            return ['items' => [], 'profile' => []];
        }

        $apiPath = $parsed['apiPath'];

        // Fetch profile info
        $profile = $this->apiGet('/v2/'.$apiPath.'/info.json');
        $profileName = null;
        $profileThumb = null;
        $profileLink = null;
        $profileBio = null;

        if ($profile) {
            $profileName = $profile['display_name'] ?? $profile['name'] ?? null;
            $profileThumb = $profile['portrait_huge'] ?? $profile['portrait_large'] ?? $profile['logo'] ?? null;
            $profileLink = $profile['profile_url'] ?? $profile['url'] ?? null;
            $profileBio = is_string($profile['bio'] ?? null) ? $profile['bio'] : null;
        }

        // Fetch latest videos
        $videos = $this->apiGet('/v2/'.$apiPath.'/videos.json');
        $items = [];

        if ($videos && is_array($videos)) {
            foreach ($videos as $video) {
                if (! is_array($video) || ! isset($video['id'])) {
                    continue;
                }

                $vidId = (string) $video['id'];
                $values = [
                    ['field_name' => 'title', 'value' => is_string($video['title'] ?? null) ? trim($video['title']) : null, 'format' => 'text'],
                    ['field_name' => 'thumbnail_url', 'value' => $video['thumbnail_large'] ?? $video['thumbnail_medium'] ?? null, 'format' => 'image'],
                    ['field_name' => 'page_url', 'value' => $video['url'] ?? "https://vimeo.com/{$vidId}", 'format' => 'url'],
                    ['field_name' => 'embed_url', 'value' => "https://player.vimeo.com/video/{$vidId}", 'format' => 'url'],
                    ['field_name' => 'upload_date', 'value' => $video['upload_date'] ?? null, 'format' => 'date'],
                    ['field_name' => 'description', 'value' => $video['description'] ?? null, 'format' => 'text'],
                    ['field_name' => 'duration', 'value' => $video['duration'] ?? null, 'format' => 'number'],
                ];
                if ($video['width'] ?? null) {
                    $values[] = ['field_name' => 'width', 'value' => $video['width'], 'format' => 'number'];
                }
                if ($video['height'] ?? null) {
                    $values[] = ['field_name' => 'height', 'value' => $video['height'], 'format' => 'number'];
                }

                $items[] = [
                    'identifier' => $vidId,
                    'name' => is_string($video['title'] ?? null) ? trim($video['title']) : null,
                    'item_type' => 'video',
                    'values' => $values,
                ];
            }
        }

        $profileData = [];
        if ($profileName !== null) {
            $profileData['display_name'] = $profileName;
        }
        if ($profileThumb !== null) {
            $profileData['profile_pic_url'] = $profileThumb;
        }
        if ($profileBio !== null) {
            $profileData['bio'] = $profileBio;
        }

        return [
            'items' => $items,
            'profile' => $profileData,
        ];
    }

    /**
     * Parse a vimeo.com URL into its Simple-API path segment.
     *
     * @return array{apiPath:string, link:string}|null
     */
    private function parseUrl(string $url): ?array
    {
        // Bare profile name
        if (preg_match('/^[a-z0-9_-]{2,60}$/i', trim($url)) && ! ctype_digit(trim($url))) {
            return [
                'apiPath' => strtolower(trim($url)),
                'link' => 'https://vimeo.com/'.strtolower(trim($url)),
            ];
        }

        $parts = parse_url(trim($url));
        $host = strtolower($parts['host'] ?? '');
        if (! str_contains($host, 'vimeo.com')) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $parts['path'] ?? '')));

        // Channel: vimeo.com/channels/{name}
        if (count($segments) === 2 && strtolower($segments[0]) === 'channels'
            && preg_match('/^[a-z0-9_-]+$/i', $segments[1])) {
            $name = strtolower($segments[1]);
            return ['apiPath' => "channel/{$name}", 'link' => "https://vimeo.com/channels/{$name}"];
        }

        // User profile: vimeo.com/{username}
        if (count($segments) === 1 && preg_match('/^[a-z0-9_-]+$/i', $segments[0])) {
            $user = strtolower($segments[0]);
            if (ctype_digit($user) || in_array($user, self::RESERVED, true)) {
                return null;
            }
            return ['apiPath' => $user, 'link' => "https://vimeo.com/{$user}"];
        }

        return null;
    }
}
