<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;

// Vimeo's legacy Simple API is keyless: /api/v2/{user}/info.json for the
// profile and /api/v2/{user}/videos.json for the latest uploads (channels use
// the /api/v2/channel/{name}/... form). The per-video embed player
// (player.vimeo.com/video/{id}) is public, so the sitepage can render real
// players without any credentials.
class VimeoApi
{
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private const RESERVED = ['watch', 'upload', 'features', 'enterprise', 'pricing', 'blog', 'help', 'about', 'jobs', 'stats', 'search', 'categories', 'ondemand', 'settings', 'log_in', 'join', 'site_map', 'solutions'];

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /**
     * Parse a vimeo.com profile or channel URL into its Simple-API path
     * segment ("username" or "channel/name"), or null when the URL is a video
     * page / reserved route / not Vimeo.
     *
     * @return array{apiPath:string, link:string}|null
     */
    public function parseSource(string $url): ?array
    {
        $url = PlatformInput::urlish($url);

        // A bare profile name maps straight onto vimeo.com/{name}.
        if (PlatformInput::isBareToken($url, '~^[a-z0-9_-]{2,60}$~i')) {
            $url = 'https://vimeo.com/'.strtolower(PlatformInput::token($url));
        }

        $parts = parse_url(trim($url));
        $host = strtolower($parts['host'] ?? '');
        if (! in_array(preg_replace('~^www\.~', '', $host), ['vimeo.com'], true)) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $parts['path'] ?? '')));

        if (count($segments) === 2 && strtolower($segments[0]) === 'channels'
            && preg_match('~^[a-z0-9_-]+$~i', $segments[1])) {
            $name = strtolower($segments[1]);

            return ['apiPath' => "channel/{$name}", 'link' => "https://vimeo.com/channels/{$name}"];
        }

        if (count($segments) === 1 && preg_match('~^[a-z0-9_-]+$~i', $segments[0])) {
            $user = strtolower($segments[0]);
            // Bare numeric paths are video ids, not profiles.
            if (ctype_digit($user) || in_array($user, self::RESERVED, true)) {
                return null;
            }

            return ['apiPath' => $user, 'link' => "https://vimeo.com/{$user}"];
        }

        return null;
    }

    /**
     * @return array{name:?string, thumbnail:?string, link:?string, bio:?string}|null
     */
    public function fetchProfile(string $apiPath): ?array
    {
        $data = $this->json("https://vimeo.com/api/v2/{$apiPath}/info.json");
        if (! is_array($data)) {
            return null;
        }

        // Users expose display_name/portrait_huge; channels expose name/logo.
        return [
            'name' => $data['display_name'] ?? $data['name'] ?? null,
            'thumbnail' => $data['portrait_huge'] ?? $data['portrait_large'] ?? $data['logo'] ?? null,
            'link' => $data['profile_url'] ?? $data['url'] ?? null,
            'bio' => is_string($data['bio'] ?? null) ? $data['bio'] : null,
        ];
    }

    /**
     * Latest uploads, newest first (the API caps a page at 20).
     *
     * @return list<array{itemId:string, name:?string, thumbnail:?string, link:?string, date:?string, embedUrl:string}>
     */
    public function fetchVideos(string $apiPath): array
    {
        $data = $this->json("https://vimeo.com/api/v2/{$apiPath}/videos.json");
        if (! is_array($data)) {
            return [];
        }

        $videos = [];
        foreach ($data as $video) {
            if (! is_array($video) || ! isset($video['id'])) {
                continue;
            }
            $id = (string) $video['id'];

            $videos[] = [
                'itemId' => $id,
                'name' => is_string($video['title'] ?? null) ? trim($video['title']) : null,
                'thumbnail' => $video['thumbnail_large'] ?? $video['thumbnail_medium'] ?? null,
                'link' => $video['url'] ?? "https://vimeo.com/{$id}",
                'date' => is_string($video['upload_date'] ?? null) ? $video['upload_date'] : null,
                'embedUrl' => "https://player.vimeo.com/video/{$id}",
            ];
        }

        return $videos;
    }

    private function json(string $url): mixed
    {
        $res = $this->fetcher->tryFetch($url, ['User-Agent' => self::USER_AGENT, 'Accept' => 'application/json']);
        if ($res === null || $res['status'] !== 200) {
            return null;
        }

        return json_decode($res['body'], true);
    }
}
