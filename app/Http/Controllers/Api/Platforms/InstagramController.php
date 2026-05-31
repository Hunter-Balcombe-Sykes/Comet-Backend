<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

// Test-mode endpoints for the Instagram integration. Mirrors Fresha/Shopify:
// single-tenant cache, no auth, no migration. Takes a username, runs Apify's
// instagram-profile-scraper, pulls the HD profile pic + business category +
// the 5 most-recent image posts, mirrors every image to the R2 `media` disk
// (Instagram CDN URLs expire), and stores the blob partna-pages reads back.
//
// Promotion plan: per-user persistence + scheduled refresh; served inside the
// public profile payload.
class InstagramController extends ApiController
{
    private const SELECTION_KEY = 'platforms.instagram.selection';

    private const CACHE_TTL_DAYS = 30;

    // Apify actor id (tilde-separated owner~name form for the API path).
    private const ACTOR = 'apify~instagram-profile-scraper';

    private const TARGET_IMAGE_COUNT = 5;

    // POST /api/platforms/instagram/connect — scrape a username, mirror images,
    // store + return the blob.
    public function connect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:80'],
        ]);

        $username = ltrim(trim($validated['username']), '@');
        if (! preg_match('/^[A-Za-z0-9._]{1,80}$/', $username)) {
            return $this->error("That doesn't look like a valid Instagram username.", 422);
        }

        $token = config('services.apify.token');
        if (! $token) {
            return $this->error('Apify token not configured on the server.', 500);
        }

        $profile = $this->runProfileScraper($username, $token);
        if (! $profile) {
            return $this->error('Could not fetch that Instagram profile (private, not found, or scraper error).', 502);
        }

        // Unique folder per scrape so mirrored URLs are always fresh (immutable
        // CDN cache on the bucket means we must not reuse a path for new bytes).
        $folder = 'platforms/instagram/'.now()->timestamp;

        $imageUrls = $this->collectImageUrls($profile, self::TARGET_IMAGE_COUNT);
        $images = [];
        foreach ($imageUrls as $i => $url) {
            $mirrored = $this->mirror($url, "{$folder}/img-{$i}.jpg");
            if ($mirrored) {
                $images[] = $mirrored;
            }
        }

        $profilePicSrc = data_get($profile, 'profilePicUrlHD') ?? data_get($profile, 'profilePicUrl');
        $profilePic = is_string($profilePicSrc) ? $this->mirror($profilePicSrc, "{$folder}/profile.jpg") : null;

        $selection = [
            'username' => $username,
            'fullName' => data_get($profile, 'fullName'),
            'profilePicUrl' => $profilePic,
            'businessCategory' => data_get($profile, 'businessCategoryName'),
            'followersCount' => data_get($profile, 'followersCount'),
            'postsCount' => data_get($profile, 'postsCount'),
            'images' => $images,
        ];
        Cache::put(self::SELECTION_KEY, $selection, now()->addDays(self::CACHE_TTL_DAYS));

        return $this->success($selection);
    }

    // GET /api/platforms/instagram/selection — read the saved blob (partna-pages
    // reads this; the dashboard restores its state from it).
    public function selection(): JsonResponse
    {
        return $this->success(['selection' => Cache::get(self::SELECTION_KEY)]);
    }

    // DELETE /api/platforms/instagram — clear the saved selection.
    public function forget(): JsonResponse
    {
        Cache::forget(self::SELECTION_KEY);

        return $this->success(['selection' => null]);
    }

    // ── internals ────────────────────────────────────────────────

    // Run the profile scraper synchronously and return the first dataset item
    // (the profile), or null on any failure.
    private function runProfileScraper(string $username, string $token): ?array
    {
        try {
            $response = Http::withToken($token)
                ->timeout(110)
                ->post(
                    'https://api.apify.com/v2/acts/'.self::ACTOR.'/run-sync-get-dataset-items',
                    ['usernames' => [$username]],
                );
        } catch (Throwable) {
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $items = $response->json();
        if (! is_array($items) || empty($items) || ! is_array($items[0])) {
            return null;
        }

        return $items[0];
    }

    /**
     * Walk latestPosts newest-first, collecting image URLs until we have $count.
     * Skips videos; for carousels (Sidecar) takes the first image.
     *
     * @return list<string>
     */
    private function collectImageUrls(array $profile, int $count): array
    {
        $posts = data_get($profile, 'latestPosts', []);
        if (! is_array($posts)) {
            return [];
        }

        $out = [];
        foreach ($posts as $post) {
            if (data_get($post, 'type') === 'Video') {
                continue;
            }
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

    // Download a (short-lived) Instagram CDN image and re-host it on the R2
    // `media` disk. Returns the public URL, or null on failure.
    private function mirror(string $url, string $path): ?string
    {
        try {
            $res = Http::timeout(20)->get($url);
            if (! $res->ok()) {
                return null;
            }
            Storage::disk('media')->put($path, $res->body());

            return Storage::disk('media')->url($path);
        } catch (Throwable) {
            return null;
        }
    }
}
