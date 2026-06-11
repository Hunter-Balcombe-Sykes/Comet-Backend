<?php

namespace App\Services\Platforms;

use App\Services\SmartLinks\SafeUrlFetcher;

// Mixcloud's JSON API is fully open: api.mixcloud.com/{username}/ for the
// profile and .../cloudcasts/ for the latest shows. The official player
// widget (player-widget.mixcloud.com/?feed={key}) embeds keylessly, so each
// show — and the profile feed itself — can stream on the sitepage.
class MixcloudApi
{
    private const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

    private const RESERVED = ['discover', 'upload', 'live', 'settings', 'pro', 'premium', 'select', 'categories', 'search', 'about', 'developers', 'jobs', 'legal', 'privacy', 'terms', 'signup', 'login'];

    public function __construct(private readonly SafeUrlFetcher $fetcher) {}

    /** Username from a mixcloud.com profile/show URL or a bare handle. */
    public function parseUsername(string $input): ?string
    {
        $input = trim($input);

        if (preg_match('~^https?://(?:www\.)?mixcloud\.com/([A-Za-z0-9_-]+)/?~i', $input, $m)) {
            $candidate = $m[1];
        } elseif (preg_match('~^@?([A-Za-z0-9_-]{2,60})$~', $input, $m)) {
            $candidate = $m[1];
        } else {
            return null;
        }

        return in_array(strtolower($candidate), self::RESERVED, true) ? null : $candidate;
    }

    /**
     * @return array{username:string, name:?string, thumbnail:?string, link:?string, followers:?int}|null
     */
    public function fetchProfile(string $username): ?array
    {
        $data = $this->json('https://api.mixcloud.com/'.rawurlencode($username).'/');
        if (! is_array($data) || ! isset($data['username'])) {
            return null;
        }

        return [
            'username' => (string) $data['username'],
            'name' => is_string($data['name'] ?? null) ? trim($data['name']) : null,
            'thumbnail' => $data['pictures']['extra_large'] ?? $data['pictures']['large'] ?? null,
            'link' => $data['url'] ?? null,
            'followers' => isset($data['follower_count']) ? (int) $data['follower_count'] : null,
        ];
    }

    /**
     * Latest shows, newest first.
     *
     * @return list<array{itemId:string, name:?string, thumbnail:?string, link:?string, date:?string, embedUrl:string}>
     */
    public function fetchCloudcasts(string $username, int $limit = 12): array
    {
        $data = $this->json('https://api.mixcloud.com/'.rawurlencode($username)."/cloudcasts/?limit={$limit}");
        if (! is_array($data['data'] ?? null)) {
            return [];
        }

        $shows = [];
        foreach ($data['data'] as $cast) {
            if (! is_array($cast) || ! isset($cast['key'])) {
                continue;
            }

            $shows[] = [
                'itemId' => (string) $cast['key'],
                'name' => is_string($cast['name'] ?? null) ? trim($cast['name']) : null,
                'thumbnail' => $cast['pictures']['extra_large'] ?? $cast['pictures']['large'] ?? null,
                'link' => $cast['url'] ?? null,
                'date' => is_string($cast['created_time'] ?? null) ? $cast['created_time'] : null,
                'embedUrl' => self::embedUrlForFeed((string) $cast['key']),
            ];
        }

        return $shows;
    }

    /** Official widget src for a feed key ("/user/" or "/user/show-slug/"). */
    public static function embedUrlForFeed(string $feedKey): string
    {
        return 'https://player-widget.mixcloud.com/?hide_cover=1&feed='.rawurlencode($feedKey);
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
