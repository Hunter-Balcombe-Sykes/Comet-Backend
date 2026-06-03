<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Models\Core\User\User;
use App\Services\Platforms\InstagramScraper;
use App\Services\SmartLinks\SafeUrlFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

// Test-mode endpoints for the Instagram integration. Two modes, identical output
// shape (a mirrored images[] + a `mode` tag):
//   automatic — connect() scrapes and takes the 8 most-recent post covers.
//   manual    — posts() lists recent posts + their images for the picker;
//               saveSelection() mirrors the chosen images.
// Scraping lives in App\Services\Platforms\InstagramScraper; mirroring to the R2
// `media` disk stays here (IG CDN urls expire, so we re-host the chosen images).
class InstagramController extends ApiController
{
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    private const AUTO_IMAGE_COUNT = 8;

    private const MAX_MANUAL_IMAGES = 8;

    // Pilot cost controls for the paid Apify scraper. Minimal — backend dev to
    // tune/extend (cover posts/saveSelection, telemetry). A re-connect is
    // rate-limited per user; total daily runs are globally capped.
    private const APIFY_COOLDOWN_SECONDS = 600;

    private const APIFY_DAILY_CAP = 200;

    // Hosts `mirror()` will fetch from — Instagram/Facebook CDNs only.
    private const ALLOWED_IMAGE_HOSTS = ['cdninstagram.com', 'fbcdn.net'];

    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly SafeUrlFetcher $fetcher,
    ) {}

    protected function platform(): string
    {
        return 'instagram';
    }

    // POST /api/platforms/instagram/connect — AUTOMATIC: scrape, mirror 8 covers, store.
    public function connect(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $username = $this->validateUsername($request);
        if ($username instanceof JsonResponse) {
            return $username;
        }

        if ($budgetError = $this->guardApifyBudget($user)) {
            return $budgetError;
        }

        $profile = $this->scraper->fetchProfile($username);
        if (! $profile) {
            return $this->error('Could not fetch that Instagram profile (private, not found, or scraper error).', 502);
        }

        $folder = 'platforms/instagram/'.now()->timestamp;
        $coverUrls = $this->scraper->recentCoverImages($profile, self::AUTO_IMAGE_COUNT);
        $images = $this->mirrorAll($coverUrls, $folder);

        $selection = $this->buildSelection($username, $profile, $folder, 'automatic', $images, count($coverUrls) - count($images));
        $this->writeConnection($user, $selection);

        return $this->success($selection);
    }

    // GET /api/platforms/instagram/posts?username=X — recent posts + their image
    // urls (LIVE, un-mirrored) + profile, for the manual picker.
    public function posts(Request $request): JsonResponse
    {
        $username = $this->validateUsername($request);
        if ($username instanceof JsonResponse) {
            return $username;
        }

        $profile = $this->scraper->fetchProfile($username);
        if (! $profile) {
            return $this->error('Could not fetch that Instagram profile.', 502);
        }

        return $this->success([
            'username' => $username,
            'fullName' => data_get($profile, 'fullName'),
            'profilePicUrl' => $this->scraper->profilePicUrl($profile),
            'businessCategory' => data_get($profile, 'businessCategoryName'),
            'posts' => $this->scraper->recentPosts($profile),
        ]);
    }

    // POST /api/platforms/instagram/selection — MANUAL: mirror the chosen images, store.
    public function saveSelection(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $request->validate([
            'images' => ['present', 'array', 'max:'.self::MAX_MANUAL_IMAGES],
            'images.*' => ['string', 'url', 'max:2000'],
        ]);
        $username = $this->validateUsername($request);
        if ($username instanceof JsonResponse) {
            return $username;
        }

        $profile = $this->scraper->fetchProfile($username);
        if (! $profile) {
            return $this->error('Could not fetch that Instagram profile.', 502);
        }

        $folder = 'platforms/instagram/'.now()->timestamp;
        $chosen = array_slice($request->input('images', []), 0, self::MAX_MANUAL_IMAGES);
        $images = $this->mirrorAll($chosen, $folder);

        $selection = $this->buildSelection($username, $profile, $folder, 'manual', $images, count($chosen) - count($images));
        $this->writeConnection($user, $selection);

        return $this->success($selection);
    }

    // GET /api/platforms/instagram/selection — the authenticated user's saved selection.
    public function selection(Request $request): JsonResponse
    {
        return $this->success(['selection' => $this->readConnection($this->currentUser($request))]);
    }

    // DELETE /api/platforms/instagram — clear the authenticated user's connection.
    public function forget(Request $request): JsonResponse
    {
        $this->forgetConnection($this->currentUser($request));

        return $this->success(['selection' => null]);
    }

    // ── internals ────────────────────────────────────────────────

    // Pilot cost guard: 429 when the user is within their re-scrape cooldown or
    // the global daily Apify cap is hit; otherwise records the run and returns
    // null. Atomic per-user cooldown via Cache::add; the daily counter is a
    // read-modify-write (good enough for a pilot — backend dev to harden).
    private function guardApifyBudget(User $user): ?JsonResponse
    {
        $cooldownKey = "platforms:instagram:cooldown:{$user->id}";
        if (! Cache::add($cooldownKey, 1, self::APIFY_COOLDOWN_SECONDS)) {
            return $this->error('You refreshed Instagram recently — please wait a few minutes.', 429);
        }

        $dayKey = 'platforms:instagram:apify-daily:'.now()->format('Y-m-d');
        $count = (int) Cache::get($dayKey, 0);
        if ($count >= self::APIFY_DAILY_CAP) {
            return $this->error('Instagram is busy right now — please try again later.', 429);
        }
        Cache::put($dayKey, $count + 1, now()->addDay());

        return null;
    }

    // Validate + normalise the username, or return a 422/500 JsonResponse the
    // caller forwards. (Apify token must be configured for any scrape.)
    private function validateUsername(Request $request): JsonResponse|string
    {
        $validated = $request->validate(['username' => ['required', 'string', 'max:80']]);
        $username = ltrim(trim($validated['username']), '@');
        if (! preg_match('/^[A-Za-z0-9._]{1,80}$/', $username)) {
            return $this->error("That doesn't look like a valid Instagram username.", 422);
        }
        if (! config('services.apify.token')) {
            return $this->error('Apify token not configured on the server.', 500);
        }

        return $username;
    }

    // Shape the stored blob — identical across modes, plus the `mode` tag.
    private function buildSelection(string $username, array $profile, string $folder, string $mode, array $images, int $imagesDropped = 0): array
    {
        $picSrc = $this->scraper->profilePicUrl($profile);
        $profilePic = $picSrc ? $this->mirror($picSrc, "{$folder}/profile.jpg") : null;

        return [
            'username' => $username,
            'fullName' => data_get($profile, 'fullName'),
            'profilePicUrl' => $profilePic,
            'businessCategory' => data_get($profile, 'businessCategoryName'),
            'followersCount' => data_get($profile, 'followersCount'),
            'postsCount' => data_get($profile, 'postsCount'),
            'mode' => $mode,
            'images' => $images,
            // How many chosen/cover images failed to mirror (IG CDN hiccup or
            // expired URL). Surfaced so the dashboard can warn "N couldn't load"
            // instead of silently saving fewer images than the user picked.
            'imagesDropped' => $imagesDropped,
        ];
    }

    /**
     * Mirror each url to a fresh R2 path under $folder; drop any that fail.
     *
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function mirrorAll(array $urls, string $folder): array
    {
        $out = [];
        foreach (array_values($urls) as $i => $url) {
            $mirrored = $this->mirror($url, "{$folder}/img-{$i}.jpg");
            if ($mirrored) {
                $out[] = $mirrored;
            }
        }

        return $out;
    }

    // Download a (short-lived) Instagram CDN image and re-host it on the R2
    // `media` disk. Returns the public URL, or null on failure.
    //
    // SSRF guard (manual mode's image URLs are client-supplied): the host is
    // allowlisted to IG/FB CDNs, the fetch goes through SafeUrlFetcher (scheme +
    // resolved-IP + per-redirect-hop validation against private/reserved ranges),
    // and the body is only stored if the response is actually an image.
    private function mirror(string $url, string $path): ?string
    {
        if (! $this->isAllowedImageHost($url)) {
            return null;
        }

        try {
            $res = $this->fetcher->fetch($url, ['Accept' => 'image/*']);
            if ($res['status'] >= 400) {
                return null;
            }
            $contentType = strtolower(trim(explode(';', $res['contentType'])[0]));
            if (! str_starts_with($contentType, 'image/')) {
                return null;
            }
            Storage::disk('media')->put($path, $res['body']);

            return Storage::disk('media')->url($path);
        } catch (Throwable) {
            return null;
        }
    }

    // Legitimate mirror sources are always Instagram/Facebook CDNs (auto mode
    // comes from the scraper; manual mode's URLs originate from the scraper-backed
    // picker). True only when $url's host is one of those CDNs (or a subdomain).
    private function isAllowedImageHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach (self::ALLOWED_IMAGE_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }
}
