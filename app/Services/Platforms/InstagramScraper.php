<?php

namespace App\Services\Platforms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

// Instagram profile scrape via Apify's instagram-profile-scraper (no direct IG
// access). Pure fetch + parse — mirroring the chosen images to R2 stays in the
// controller (a storage concern). Extracted from InstagramController.
//
// run-sync-get-dataset-items returns 201 on success, so we accept any 2xx.
class InstagramScraper extends PlatformScraper
{
    // Apify actor id (tilde-separated owner~name form for the API path).
    private const ACTOR = 'apify~instagram-profile-scraper';

    // Posts to ask Apify for — enough that auto reliably yields 8 covers and the
    // manual picker has a healthy pool.
    private const RESULTS_LIMIT = 24;

    // Run the profile scraper, returning the first dataset item (the profile,
    // with latestPosts) or null on any failure / missing token.
    public function fetchProfile(string $username): ?array
    {
        $token = config('services.apify.token');
        if (! $token) {
            return null;
        }

        try {
            $response = Http::withToken($token)
                ->timeout(110)
                ->post(
                    'https://api.apify.com/v2/acts/'.self::ACTOR.'/run-sync-get-dataset-items',
                    ['usernames' => [$username], 'resultsLimit' => self::RESULTS_LIMIT],
                );
        } catch (Throwable $e) {
            Log::warning('instagram.apify.threw', ['username' => $username, 'error' => $e->getMessage()]);

            return null;
        }

        // 201 Created on success — ->ok() would only accept exactly 200.
        if (! $response->successful()) {
            Log::warning('instagram.apify.not_ok', [
                'username' => $username,
                'status' => $response->status(),
            ]);

            return null;
        }

        $items = $response->json();
        if (! is_array($items) || empty($items) || ! is_array($items[0])) {
            Log::warning('instagram.apify.bad_items', [
                'username' => $username,
                'type' => gettype($items),
                'count' => is_array($items) ? count($items) : 0,
            ]);

            return null;
        }

        return $items[0];
    }

    public function profilePicUrl(array $profile): ?string
    {
        $url = data_get($profile, 'profilePicUrlHD') ?? data_get($profile, 'profilePicUrl');

        return is_string($url) ? $url : null;
    }

    /**
     * AUTO mode: the $count most-recent post cover images (skips videos),
     * newest-first.
     *
     * @return list<string>
     */
    public function recentCoverImages(array $profile, int $count): array
    {
        $out = [];
        foreach ($this->nonVideoPosts($profile) as $post) {
            $url = data_get($post, 'displayUrl') ?? data_get($post, 'images.0');
            if (is_string($url) && $url !== '') {
                $out[] = $url;
            }
            if (count($out) >= $count) {
                break;
            }
        }

        return $out;
    }

    /**
     * MANUAL mode: recent posts, each with ALL its selectable image URLs (carousel
     * children included — Apify returns them in `images[]`), for the picker. Live
     * IG CDN urls; the controller mirrors only the chosen ones on save.
     *
     * @return list<array{shortCode:?string, type:?string, caption:?string, images:list<string>}>
     */
    public function recentPosts(array $profile): array
    {
        $out = [];
        foreach ($this->nonVideoPosts($profile) as $post) {
            $images = data_get($post, 'images');
            if (! is_array($images) || empty($images)) {
                $cover = data_get($post, 'displayUrl');
                $images = is_string($cover) ? [$cover] : [];
            }
            $images = array_values(array_filter($images, fn ($u) => is_string($u) && $u !== ''));
            if (empty($images)) {
                continue;
            }

            $out[] = [
                'shortCode' => data_get($post, 'shortCode'),
                'type' => data_get($post, 'type'),
                'caption' => data_get($post, 'caption'),
                'images' => $images,
            ];
        }

        return $out;
    }

    /** Non-video posts in feed order. */
    private function nonVideoPosts(array $profile): array
    {
        $posts = data_get($profile, 'latestPosts', []);
        if (! is_array($posts)) {
            return [];
        }

        return array_filter($posts, fn ($post) => data_get($post, 'type') !== 'Video');
    }
}
