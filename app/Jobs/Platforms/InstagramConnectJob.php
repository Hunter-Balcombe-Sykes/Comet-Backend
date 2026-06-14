<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Platforms\InstagramScraper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

// Performs the full Instagram automatic-connect pipeline in the background so
// connect() can return 202 immediately instead of blocking the PHP-FPM worker
// for up to 150s (110s Apify + serial image mirroring).
//
// Pipeline:
//   1. Apify scrape via InstagramScraper::fetchProfile (up to 110s).
//   2. Parallel image mirror via Http::pool (8 images × ~8s collapsed into one round-trip).
//   3. Upsert the connection row with last_refresh_status='ok'/'unavailable'.
//
// The connection row is written with last_refresh_status='pending' by the
// controller BEFORE this job is dispatched, so the status endpoint can respond
// before the job runs.
class InstagramConnectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Apify can take up to 110s; allow headroom for image mirroring on top.
    public int $timeout = 150;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [30, 120];

    // One content failure (e.g. Apify returned bad data) should surface quickly
    // rather than silently retrying twice.
    public int $maxExceptions = 2;

    // Instagram CDN hosts we'll fetch from. URLs come from the scraper, but we
    // still (1) restrict to known CDN hosts and (2) fetch with redirects DISABLED
    // (withoutRedirecting below) so an allow-listed CDN URL can't 30x-redirect us
    // to an internal address. Together these replace the per-hop SSRF guard the
    // serial SafeUrlFetcher path gave us before this job parallelised mirroring.
    private const ALLOWED_IMAGE_HOSTS = ['cdninstagram.com', 'fbcdn.net'];

    private const AUTO_IMAGE_COUNT = 8;

    // Per-image fetch timeout for the pool (IG CDN, usually fast).
    private const IMAGE_TIMEOUT_SECONDS = 10;

    public function __construct(
        public readonly string $userId,
        public readonly string $username,
        public readonly string $connectionId,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    public function handle(InstagramScraper $scraper): void
    {
        // ::find() respects the soft-delete scope, so a null result already covers
        // both "never existed" and "user disconnected (soft-deleted) while queued".
        $connection = IntegrationConnection::find($this->connectionId);
        if (! $connection) {
            return;
        }

        $profile = $scraper->fetchProfile($this->username, $this->userId);

        if (! $profile) {
            $this->markFailed($connection, 'apify_fetch_failed');

            return;
        }

        $folder = 'platforms/instagram/'.$connection->created_at->timestamp;
        $coverUrls = $scraper->recentCoverImages($profile, self::AUTO_IMAGE_COUNT);
        $images = $this->mirrorAllParallel($coverUrls, $folder);

        $picSrc = $scraper->profilePicUrl($profile);
        $profilePic = $picSrc ? $this->mirrorOne($picSrc, "{$folder}/profile.jpg") : null;

        $selection = [
            'username' => $this->username,
            'fullName' => data_get($profile, 'fullName'),
            'profilePicUrl' => $profilePic,
            'businessCategory' => data_get($profile, 'businessCategoryName'),
            'followersCount' => data_get($profile, 'followersCount'),
            'postsCount' => data_get($profile, 'postsCount'),
            'mode' => 'automatic',
            'images' => $images,
            'imagesDropped' => count($coverUrls) - count($images),
            // Internal: R2 prefix these mirrored files live under, so the observer
            // can reclaim them on disconnect/overwrite (CONS-21). Stripped from the
            // public endpoint by PublicIntegrationConnectionResource.
            '_folder' => $folder,
        ];

        $connection->update([
            'payload' => $selection,
            'is_active' => true,
            'last_refreshed_at' => now(),
            'last_refresh_status' => 'ok',
            'last_refresh_error' => null,
            'consecutive_failures' => 0,
        ]);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('instagram.connect_job.failed', [
            'user_id' => $this->userId,
            'username' => $this->username,
            'connection_id' => $this->connectionId,
            'error' => $e->getMessage(),
        ]);

        $connection = IntegrationConnection::find($this->connectionId);
        if ($connection) {
            $this->markFailed($connection, 'job_failed');
        }
    }

    /**
     * Mirror images concurrently via Http::pool — one batch round-trip instead
     * of up to 8 serial fetches. The pool key is the index so $responses[i]
     * matches $urls[i].
     *
     * @param  list<string>  $urls
     * @return list<string> public R2 URLs for successfully mirrored images
     */
    private function mirrorAllParallel(array $urls, string $folder): array
    {
        $allowed = array_values(array_filter($urls, fn ($u) => $this->isAllowedImageHost($u)));
        if ($allowed === []) {
            return [];
        }

        // Fetch all images concurrently. Redirects are DISABLED per-request: an
        // allow-listed CDN URL that 30x-redirects must not be followed to a
        // possibly-internal target (SSRF). A 3xx is treated as a drop below.
        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (int $i, string $url) => $pool->as((string) $i)
                ->timeout(self::IMAGE_TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withHeaders(['Accept' => 'image/*'])
                ->get($url),
            array_keys($allowed),
            $allowed,
        ));

        $out = [];
        foreach ($allowed as $i => $url) {
            $response = $responses[(string) $i] ?? null;
            // Drop anything that isn't a clean 2xx — including 3xx redirects we refused to follow.
            if (! ($response instanceof Response) || $response->status() >= 300) {
                continue;
            }

            $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            if (! str_starts_with($contentType, 'image/')) {
                continue;
            }

            $path = "{$folder}/img-{$i}.jpg";
            try {
                Storage::disk('media')->put($path, $response->body());
                $out[] = Storage::disk('media')->url($path);
            } catch (Throwable) {
                // Single image write failure — skip, don't abort the whole batch.
            }
        }

        return $out;
    }

    // Mirror a single image (used for the profile pic — only one, pool overhead not worth it).
    private function mirrorOne(string $url, string $path): ?string
    {
        if (! $this->isAllowedImageHost($url)) {
            return null;
        }

        try {
            $response = Http::timeout(self::IMAGE_TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withHeaders(['Accept' => 'image/*'])
                ->get($url);

            // Drop non-2xx, including 3xx redirects we refuse to follow (SSRF guard).
            if ($response->status() >= 300) {
                return null;
            }

            $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            if (! str_starts_with($contentType, 'image/')) {
                return null;
            }

            Storage::disk('media')->put($path, $response->body());

            return Storage::disk('media')->url($path);
        } catch (Throwable) {
            return null;
        }
    }

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

    private function markFailed(IntegrationConnection $connection, string $error): void
    {
        $connection->forceFill([
            'last_refresh_status' => 'unavailable',
            'last_refresh_error' => $error,
            'consecutive_failures' => (int) $connection->consecutive_failures + 1,
        ])->saveQuietly();
    }
}
