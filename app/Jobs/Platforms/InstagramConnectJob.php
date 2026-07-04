<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Http\SafeUrlException;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\InstagramScraper;
use App\Services\Platforms\Payloads\InstagramPayload;
use App\Services\Platforms\Registry\Platform;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

// Performs the full Instagram automatic-connect pipeline in the background so
// connect() can return 202 immediately instead of blocking the PHP-FPM worker
// for up to 150s (110s Apify + media mirroring).
//
// Pipeline:
//   1. Apify scrape via InstagramScraper::fetchProfile (up to 110s).
//   2. Mirror the SINGLE latest post to R2 — its cover image always, plus the
//      reel mp4 when the latest is a video.
//   3. Upsert the connection row with last_refresh_status='ok'/'unavailable'.
//
// The connection row is written with last_refresh_status='pending' by the
// controller BEFORE this job is dispatched, so the status endpoint can respond
// before the job runs.
class InstagramConnectJob implements ShouldBeUnique, ShouldQueue, ThrottledByProvider
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Apify can take up to 110s; allow headroom for media mirroring on top.
    public int $timeout = 150;

    // Unlimited attempts, bounded by retryUntil() below — the 'platform-connect'
    // RateLimited middleware RELEASES this job when the Apify actor is over-limit, and
    // every release counts as an attempt. A finite $tries would mass-fail connects
    // during the exact signup spike the gate exists to absorb. Genuine errors stay
    // capped by $maxExceptions, so a broken scrape still fails fast.
    public int $tries = 0;

    /** @var list<int> Backoff between exception-triggered retries (not rate-limit releases). */
    public array $backoff = [30, 120];

    // One content failure (e.g. Apify returned bad data) should surface quickly
    // rather than silently retrying twice.
    public int $maxExceptions = 2;

    // One auto-connect per connection at a time. The window matches retryUntil() so a
    // job parked in rate-limit purgatory can't have a duplicate slip in behind it, bill
    // a second Apify scrape, and tear the connection row (LIFE-1). The lock also releases
    // on completion/failure, so this is the worst-case backstop, not the common path.
    public int $uniqueFor = 900;

    // Instagram CDN hosts we'll fetch media from. URLs come from the scraper, but
    // we still (1) restrict to known CDN hosts and (2) fetch with redirects
    // DISABLED (withoutRedirecting below) so an allow-listed CDN URL can't
    // 30x-redirect us to an internal address (SSRF guard).
    private const ALLOWED_HOSTS = ['cdninstagram.com', 'fbcdn.net'];

    // Per-image fetch timeout (IG CDN, usually fast).
    private const IMAGE_TIMEOUT_SECONDS = 10;

    // Reels are larger + slower than a still, so they get their own timeout.
    private const VIDEO_TIMEOUT_SECONDS = 30;

    // Hard cap on a mirrored reel (50 MB). Instagram reels are short, so this is
    // generous; it stops a pathological file filling the temp disk / R2, and an
    // oversized reel simply falls back to its poster (no autoplay).
    private const MAX_VIDEO_BYTES = 52428800;

    // Hard cap on a mirrored still image (15 MB). Instagram photos are tiny
    // (<1 MB in practice); 15 MB is deliberately generous to future-proof against
    // high-res uploads while still preventing a pathological response from
    // buffering/storing a huge payload.
    private const MAX_IMAGE_BYTES = 15728640;

    public function __construct(
        public readonly string $userId,
        public readonly string $username,
        public readonly string $connectionId,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    // Key on connection + username: dedups a true duplicate (retry / double-submit
    // of the same connect, which would re-bill Apify and tear the row), but NOT a
    // legitimate account switch — the connection row is reused via updateOrCreate,
    // so connecting a different username must still run (no per-user cooldown).
    public function uniqueId(): string
    {
        return $this->connectionId.':'.$this->username;
    }

    /** Apify actor for the 'platform-connect' rate budget. */
    public function providerRateKey(): string
    {
        return Platform::Instagram->value;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new RateLimited('platform-connect')];
    }

    // Wall-clock deadline for rate-limit releases. A connect held behind its actor's
    // per-minute limit keeps retrying until it slips through or 15 min elapses, then
    // lapses to failed() (terminal, user can reconnect) — never an infinite park.
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(15);
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
            // Hard-fail loudly so Horizon records a failure and Nightwatch alerts —
            // a silent markFailed()+return made Horizon mark the job "succeeded",
            // hiding a broken auto-connect (JOB-4). No retry: re-running re-bills the
            // Apify scrape. failed() marks the connection 'unavailable' for the user.
            $this->fail(new \RuntimeException(
                "Instagram scrape returned no profile for @{$this->username} (user {$this->userId})"
            ));

            return;
        }

        $folder = 'platforms/instagram/'.$connection->created_at->timestamp;

        // The most-recent photo AND the most-recent reel, picked independently. The
        // photo mirrors as the image; the reel mirrors its mp4 plus its own poster.
        // An oversized / failed video mirror leaves videoUrl null so a skeleton falls
        // back to the photo.
        $media = $scraper->latestMedia($profile, $this->userId);

        $images = [];
        if ($media['photo'] && $media['photo']['thumbnailUrl']) {
            $photo = $this->mirrorOne($media['photo']['thumbnailUrl'], "{$folder}/photo.jpg");
            if ($photo) {
                $images = [$photo];
            }
        }

        $videoUrl = null;
        $videoPoster = null;
        if ($media['video'] && $media['video']['videoUrl']) {
            $videoUrl = $this->mirrorVideo($media['video']['videoUrl'], "{$folder}/reel.mp4");
            // Mirror the reel's poster only once its mp4 mirrored — it's the <video>'s
            // poster frame, useless without the video.
            if ($videoUrl && $media['video']['thumbnailUrl']) {
                $videoPoster = $this->mirrorOne($media['video']['thumbnailUrl'], "{$folder}/reel-cover.jpg");
            }
        }

        $picSrc = $scraper->profilePicUrl($profile);
        $profilePic = $picSrc ? $this->mirrorOne($picSrc, "{$folder}/profile.jpg") : null;

        // Reclaim stale mirrors of a media type no longer present this run (e.g. a
        // prior reel + its cover when the account now leads with a photo, or a
        // removed profile pic). The folder is stable per connection
        // (created_at-derived) and the filenames are fixed, so a reconnect
        // overwrites the live files in place — only the *complement* below (the
        // fixed names NOT re-written this run) can linger. Deleting them here,
        // in-job and AFTER the writes, is race-free: unlike a separately-queued
        // delete it can never run after a fresh re-mirror and wipe it. $images is
        // non-empty only when photo.jpg was written; Storage::delete on an absent
        // key is a safe no-op.
        $written = array_filter([
            $images ? "{$folder}/photo.jpg" : null,
            $videoUrl ? "{$folder}/reel.mp4" : null,
            $videoPoster ? "{$folder}/reel-cover.jpg" : null,
            $profilePic ? "{$folder}/profile.jpg" : null,
        ]);
        $stale = array_values(array_diff([
            "{$folder}/photo.jpg",
            "{$folder}/reel.mp4",
            "{$folder}/reel-cover.jpg",
            "{$folder}/profile.jpg",
        ], $written));
        if ($stale) {
            Storage::disk('media')->delete($stale);
        }

        $selection = [
            'username' => $this->username,
            'fullName' => data_get($profile, 'fullName'),
            'profilePicUrl' => $profilePic,
            'businessCategory' => data_get($profile, 'businessCategoryName'),
            'followersCount' => data_get($profile, 'followersCount'),
            'postsCount' => data_get($profile, 'postsCount'),
            'mode' => 'automatic',
            // Single element: the most-recent photo, mirrored. Kept as a list so
            // existing consumers (skeleton-4 fallback, dashboard) read images[0].
            'images' => $images,
            // The most-recent reel: its mp4 + poster, both on R2. null when the
            // account has no reel (or the mp4 mirror was dropped). Skeletons go
            // video-first, falling back to images[0].
            'videoUrl' => $videoUrl,
            'videoPoster' => $videoPoster,
            // Internal: R2 prefix these mirrored files live under, so the observer
            // can reclaim them on disconnect/overwrite (CONS-21). Stripped from the
            // public endpoint by PublicIntegrationConnectionResource.
            '_folder' => $folder,
        ];

        // Preserve the google-business origin tag across a re-scrape (it drives the
        // /synced "Change to" flow). Read it typed; the scrape WRITE below stays literal.
        if (($source = InstagramPayload::fromArray($connection->payload)->source) !== null) {
            $selection['source'] = $source;
        }

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

    // Mirror a single image (cover or profile pic) to R2.
    //
    // SSRF guard (layered):
    //   1. CDN host allowlist — only cdninstagram.com / fbcdn.net subdomains pass.
    //   2. IP-resolution check — even an allow-listed hostname must resolve to a
    //      public address (defence-in-depth; the allowlist alone can't catch a
    //      compromised CDN hostname). Routes through SafeUrlFetcher::assertSafe(),
    //      the same guard used by every other outbound fetch in the subsystem.
    //   3. Redirects refused — a 3xx response is dropped, not followed (withoutRedirecting).
    //   4. Content-type enforced — only image/* is stored.
    //   5. Byte cap — rejects before store if Content-Length signals an oversized
    //      file, with a hard strlen check as fallback for absent/wrong headers.
    private function mirrorOne(string $url, string $path): ?string
    {
        if (! $this->isAllowedHost($url)) {
            return null;
        }

        // IP-resolution guard: cdninstagram.com / fbcdn.net always resolve to
        // public IPs, so a SafeUrlException here is anomalous and worth surfacing.
        try {
            app(SafeUrlFetcher::class)->assertSafe($url);
        } catch (SafeUrlException $e) {
            report($e);

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

            // Fast rejection when the server declares the size upfront.
            $contentLength = $response->header('Content-Length');
            if ($contentLength !== null && (int) $contentLength > self::MAX_IMAGE_BYTES) {
                return null;
            }

            $body = $response->body();

            // Hard cap enforced after buffering — covers absent or inaccurate
            // Content-Length headers. Nothing over the limit reaches R2.
            if (strlen($body) > self::MAX_IMAGE_BYTES) {
                return null;
            }

            Storage::disk('media')->put($path, $body);

            return Storage::disk('media')->url($path);
        } catch (Throwable $e) {
            // Report so a systemic mirror/R2 failure surfaces in Nightwatch (OBS-7).
            report($e);

            return null;
        }
    }

    // Mirror a reel's mp4 to R2, STREAMED via a temp file so a large video never
    // buffers fully in worker memory, and size-capped so a pathological file can't
    // fill disk/R2. Same layered SSRF guard as mirrorOne (host allowlist +
    // IP-resolution check + redirects refused). Returns null on any miss → the
    // caller falls back to the poster.
    private function mirrorVideo(string $url, string $path): ?string
    {
        if (! $this->isAllowedHost($url)) {
            return null;
        }

        // IP-resolution guard matching mirrorOne — defence-in-depth.
        try {
            app(SafeUrlFetcher::class)->assertSafe($url);
        } catch (SafeUrlException $e) {
            report($e);

            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'igreel');
        if ($tmp === false) {
            return null;
        }

        try {
            $response = Http::timeout(self::VIDEO_TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withHeaders(['Accept' => 'video/*'])
                ->sink($tmp)
                ->get($url);

            // Drop non-2xx, including 3xx redirects we refuse to follow (SSRF guard).
            if ($response->status() >= 300) {
                return null;
            }

            $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            if (! str_starts_with($contentType, 'video/')) {
                return null;
            }

            // Oversized reel → drop the video (the poster still renders).
            if ((int) filesize($tmp) > self::MAX_VIDEO_BYTES) {
                return null;
            }

            // Stream temp file → R2 (no second in-memory copy of the video).
            $stream = fopen($tmp, 'r');
            if ($stream === false) {
                return null;
            }
            Storage::disk('media')->put($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return Storage::disk('media')->url($path);
        } catch (Throwable $e) {
            report($e);

            return null;
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }

    private function isAllowedHost(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach (self::ALLOWED_HOSTS as $allowed) {
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
