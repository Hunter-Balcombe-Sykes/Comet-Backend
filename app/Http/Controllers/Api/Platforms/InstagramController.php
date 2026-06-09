<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Api\Platforms\Concerns\ManagesIntegrationConnection;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\SaveInstagramSelectionRequest;
use App\Jobs\Platforms\InstagramConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\Concerns\JitteredTtl;
use App\Services\Platforms\InstagramScraper;
use App\Services\SmartLinks\SafeUrlFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

// Instagram integration endpoints. Two connect modes, same final payload shape
// (mirrored images[] + `mode` tag):
//   automatic — connect() queues a background job that scrapes and mirrors up to
//               8 most-recent post covers; responds 202 immediately.
//   manual    — posts() lists recent posts for the picker; saveSelection() mirrors
//               the chosen images synchronously (user-driven, bounded set).
// Scraping lives in InstagramScraper; heavy mirroring in InstagramConnectJob.
class InstagramController extends ApiController
{
    use JitteredTtl;
    use ManagesIntegrationConnection;
    use ResolveCurrentUser;

    private const AUTO_IMAGE_COUNT = 8;

    private const MAX_MANUAL_IMAGES = 8;

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

    // POST /api/platforms/instagram/connect — AUTOMATIC: queue a scrape + mirror
    // job and return 202 immediately. The cooldown guard runs HERE (not in the job)
    // so rapid re-connects are throttled before anything is queued.
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

        // Gate the placeholder write: determine create vs. update so the correct
        // policy ability fires. This is a direct write (not via writeConnection)
        // because the placeholder shape differs from a normal selection row.
        $existing = $this->connectionFor($user);
        if ($existing) {
            $this->authorizeForUser($user, 'update', $existing);
        } else {
            $skeleton = new IntegrationConnection([
                'user_id' => $user->id,
                'platform' => $this->platform(),
                'resource_id' => $this->defaultResourceId(),
            ]);
            $this->authorizeForUser($user, 'create', $skeleton);
        }

        // Write a pending placeholder so the status endpoint can respond before
        // the job runs. updateOrCreate so a re-connect replaces any prior row.
        $connection = IntegrationConnection::updateOrCreate(
            [
                'user_id' => $user->id,
                'platform' => $this->platform(),
                'resource_id' => $this->defaultResourceId(),
            ],
            [
                'payload' => null,
                'is_active' => false,
                'last_refreshed_at' => null,
                'last_refresh_status' => 'pending',
                'last_refresh_error' => null,
                'consecutive_failures' => 0,
            ],
        );

        InstagramConnectJob::dispatch($user->id, $username, $connection->id);

        return $this->success([
            'status' => 'pending',
            'statusUrl' => url('/api/integrations/instagram/connect/status'),
        ], 202);
    }

    // GET /api/platforms/instagram/connect/status — poll endpoint for the automatic
    // connect flow. Returns pending / ready (with payload) / failed. 404 when no
    // connection exists for the caller.
    public function connectStatus(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        $connection = $this->connectionFor($user);

        if (! $connection) {
            return $this->error('No Instagram connection found.', 404);
        }

        $status = $connection->last_refresh_status;

        if ($status === 'ok') {
            return $this->success([
                'status' => 'ready',
                'connection' => $connection->payload,
            ]);
        }

        if ($status === 'pending') {
            return $this->success(['status' => 'pending']);
        }

        // 'unavailable', 'error', or any other terminal failure state.
        return $this->success([
            'status' => 'failed',
            'error' => $connection->last_refresh_error,
        ]);
    }

    // GET /api/platforms/instagram/posts?username=X — recent posts + their image
    // urls (LIVE, un-mirrored) + profile, for the manual picker.
    public function posts(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $username = $this->validateUsername($request);
        if ($username instanceof JsonResponse) {
            return $username;
        }

        $profile = $this->scraper->fetchProfile($username, $user->id);
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
    public function saveSelection(SaveInstagramSelectionRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);

        $username = $this->validateUsername($request);
        if ($username instanceof JsonResponse) {
            return $username;
        }

        $profile = $this->scraper->fetchProfile($username, $user->id);
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

    // Pilot cost guard: 429 when the global daily Apify cap is hit or the user
    // is within their re-scrape cooldown; otherwise records the run and returns
    // null. Both the daily counter and the per-user cooldown are handled
    // atomically — Cache::increment is Redis INCR (atomic), so two concurrent
    // requests can't both slip through the cap boundary the way a
    // read-modify-write could.
    private function guardApifyBudget(User $user): ?JsonResponse
    {
        $dailyCap = (int) config('partna.limits.platforms.instagram.apify_daily_cap', 200);
        $cooldownSeconds = (int) config('partna.limits.platforms.instagram.apify_cooldown_seconds', 600);

        $dayKey = CacheKeyGenerator::instagramDailyLimit(now()->format('Y-m-d'));

        // Atomic daily cap: initialise the counter once (no-op if it already
        // exists, preserving its TTL), then INCR. Cache::increment is atomic, so two
        // concurrent connects can't both slip through the cap boundary the way a
        // Cache::get + Cache::put read-modify-write did. $count is the post-increment
        // value, so the Nth run sees N — reject when it exceeds the cap.
        // The daily-cap TTL is intentionally NOT jittered: it's a hard cost cap
        // and applyJitter is ±20%, which could expire the date-keyed counter
        // before the calendar day ends and reset the cap mid-day. A single global
        // counter has no stampede to spread anyway.
        Cache::add($dayKey, 0, now()->addDay());
        $count = Cache::increment($dayKey);
        if ($count > $dailyCap) {
            // Over capacity — release the slot we just claimed and 429 WITHOUT
            // touching the user's cooldown, so they can retry once capacity frees.
            Cache::decrement($dayKey);

            return $this->error('Instagram is busy right now — please try again later.', 429);
        }

        // Per-user cooldown: only consume it once a daily slot is secured.
        $cooldownKey = CacheKeyGenerator::instagramCooldown($user->id);
        // Jitter the soft per-user cooldown (±20%) so a synchronised burst of
        // re-connect attempts doesn't all clear at the same wall-clock second.
        if (! Cache::add($cooldownKey, 1, self::applyJitter($cooldownSeconds))) {
            // Within cooldown — release the daily slot we took (no scrape runs).
            Cache::decrement($dayKey);

            return $this->error('You refreshed Instagram recently — please wait a few minutes.', 429);
        }

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
