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
    //
    // $userId is threaded for log correlation. platform_connection_id is
    // intentionally not threaded: the connection row is written only AFTER a
    // successful scrape, so it doesn't exist at log time.
    public function fetchProfile(string $username, ?string $userId = null): ?array
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
            report($e);
            Log::warning('instagram.apify.threw', ['username' => $username, 'user_id' => $userId, 'error' => $e->getMessage()]);

            return null;
        }

        // 201 Created on success — ->ok() would only accept exactly 200.
        if (! $response->successful()) {
            // Server errors (5xx) indicate genuine Apify infra failures worth alerting on;
            // 4xx (e.g. 404 for an unknown username) are expected and log-only.
            if ($response->status() >= 500) {
                report(new \RuntimeException('Apify scrape failed with status '.$response->status()));
            }
            Log::warning('instagram.apify.not_ok', [
                'username' => $username,
                'user_id' => $userId,
                'status' => $response->status(),
            ]);

            return null;
        }

        $items = $response->json();
        if (! is_array($items) || empty($items) || ! is_array($items[0])) {
            Log::warning('instagram.apify.bad_items', [
                'username' => $username,
                'user_id' => $userId,
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
     * AUTO mode: the SINGLE most-recent post of ANY type — photo, carousel, or
     * video/reel (the old auto path skipped videos; it no longer does). Returns
     * its type + the poster/thumbnail url + the reel's video url, or null when the
     * profile has no usable post. The job mirrors these to R2.
     *
     * @return array{type:string, thumbnailUrl:?string, videoUrl:?string, shortCode:?string}|null
     */
    public function latestPost(array $profile): ?array
    {
        $posts = data_get($profile, 'latestPosts');
        if (! is_array($posts) || $posts === []) {
            return null;
        }

        // latestPosts is newest-first, so [0] is the latest post regardless of type.
        $post = $posts[0];
        $isVideo = data_get($post, 'type') === 'Video';
        // displayUrl is the cover for every type (a video's poster frame too).
        $thumb = data_get($post, 'displayUrl') ?? data_get($post, 'images.0');
        $video = $isVideo ? data_get($post, 'videoUrl') : null;

        // Neither a usable image nor a video → treat as "no post".
        $thumb = is_string($thumb) && $thumb !== '' ? $thumb : null;
        $video = is_string($video) && $video !== '' ? $video : null;
        if ($thumb === null && $video === null) {
            return null;
        }

        return [
            'type' => $isVideo ? 'video' : 'image',
            'thumbnailUrl' => $thumb,
            'videoUrl' => $video,
            'shortCode' => data_get($post, 'shortCode'),
        ];
    }
}
