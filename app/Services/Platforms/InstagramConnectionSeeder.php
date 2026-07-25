<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Http\SafeUrlException;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Media\MediaDiskResolver;
use App\Services\Platforms\Payloads\InstagramPayload;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

// Extracted verbatim from InstagramConnectJob::handle() (lines 149-261) so
// PreAccount\Generators\InstagramSourceGenerator can reuse the EXACT same
// mirror + selection-build + auto-sync + row-update pipeline an authenticated
// user's connect() gets — no forked/duplicated logic between the two callers.
// InstagramConnectJob still owns the scrape (fetchProfile) and the async
// dispatch/failure semantics; this class owns everything after a profile is
// in hand.
class InstagramConnectionSeeder
{
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

    // R1: a reel's CDN URL is short-lived and signed — a bad status or a
    // dropped connection on the first attempt is often a momentary blip, not
    // a real absence of video. One retry, not unbounded: a genuinely
    // oversized or wrong-content-type response would just fail identically
    // again.
    private const VIDEO_MIRROR_MAX_ATTEMPTS = 2;

    public function __construct(
        private readonly InstagramScraper $scraper,
        private readonly InstagramAutoSync $autoSync,
        private readonly InstagramIdentitySync $identitySync,
        private readonly CustomLinkSeeder $linkSeeder,
        // WebsiteLinkHarvester dropped 2026-07-25: autoSaveUnmatchedLinks() no
        // longer re-classifies to decide probe-vs-custom — LinkRouter does that.
    ) {}

    /**
     * Mirror the profile's latest media to R2, auto-sync its bio links, and
     * persist the selection onto $connection. Returns the persisted selection.
     *
     * @return array<string, mixed>
     */
    public function seed(IntegrationConnection $connection, string $username, string $userId, array $profile): array
    {
        $folder = 'platforms/instagram/'.$connection->created_at->timestamp;

        // The most-recent photo AND the most-recent reel, picked independently. The
        // photo mirrors as the image; the reel mirrors its mp4 plus its own poster.
        // An oversized / failed video mirror leaves videoUrl null so a skeleton falls
        // back to the photo.
        $media = $this->scraper->latestMedia($profile, $userId);

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

        $picSrc = $this->scraper->profilePicUrl($profile);
        $profilePic = $picSrc ? $this->mirrorOne($picSrc, "{$folder}/profile.jpg") : null;

        // BE2: bio links — externalUrl + externalUrls[].url + URLs regexed out of
        // biography, defensively (Apify actor field names vary by version; today's
        // actor returns none of these at all, so bioLinks() safely returns []).
        $bioLinks = $this->scraper->bioLinks($profile);
        $website = data_get($profile, 'externalUrl');
        $website = is_string($website) && preg_match('~^https?://~i', trim($website)) ? trim($website) : null;

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
        // Best-effort cleanup — must never abort a connection that otherwise
        // succeeded. "Delete on an absent key is a safe no-op" holds for S3's
        // own API in general, but isn't guaranteed for every backend/config, and
        // a first-ever connect (nothing stale yet) is exactly the case with
        // nothing to actually delete — matches mirrorOne()/mirrorVideo()'s own
        // try/catch + report() convention elsewhere in this file.
        if ($stale) {
            try {
                $this->mediaDisk()->delete($stale);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $selection = [
            'username' => $username,
            'fullName' => data_get($profile, 'fullName'),
            'profilePicUrl' => $profilePic,
            'businessCategory' => data_get($profile, 'businessCategoryName'),
            'followersCount' => data_get($profile, 'followersCount'),
            'postsCount' => data_get($profile, 'postsCount'),
            'mode' => 'automatic',
            // Single element: the most-recent photo, mirrored. Kept as a list so
            // existing consumers (flow-skeleton fallback, dashboard) read images[0].
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
            // Internal (R1): latestMedia()'s diagnostics trail — what Apify actually
            // returned this run (post/video counts, per-video mp4 presence) — so a
            // "reel didn't mirror" report is diagnosable from stored data. Never
            // added to InstagramPayload/InstagramConnectionResource, so it can't
            // leak to any wire response (same leading-underscore convention as _folder).
            // data_get, not ?? — the concrete scraper always returns this key (so
            // PHPStan reads `['diagnostics'] ?? null` as dead code), but test
            // doubles of latestMedia() legitimately omit it.
            '_mediaDiagnostics' => data_get($media, 'diagnostics'),
            // BE2: the profile's own "website" field + every bio link found (both
            // internal — never emitted by InstagramConnectionResource).
            'website' => $website,
            'bioLinks' => $bioLinks,
        ];

        // Preserve the google-business origin tag across a re-scrape (it drives the
        // /synced "Change to" flow). Read it typed; the scrape WRITE below stays literal.
        if (($source = InstagramPayload::fromArray($connection->payload)->source) !== null) {
            $selection['source'] = $source;
        }

        // BE2: harvest the bio links into social/booking connections the same way
        // Google Business connect harvests its own found links — auto-add what's
        // missing, flag conflicts, surface leftovers for "add as custom link".
        // Best-effort: InstagramAutoSync isolates each link in its own try/catch,
        // so a bad link can't fail this job. Findings persist alongside the profile
        // in ONE write (not a follow-up save) so /synced never sees a half-written row.
        $sync = $this->autoSync->seed($userId, $bioLinks);
        $selection['syncFindings'] = $sync['findings'];
        $selection['unmatched'] = $sync['unmatched'];

        // Fold Instagram's own identity fields (industry/name/handle/contact)
        // into the user's real records, fill-if-empty. $userId is only a
        // string in this scope — resolve the model explicitly.
        $user = User::find($userId);
        if ($user !== null) {
            $this->identitySync->applyIdentity($user, $profile);
            $this->autoSaveUnmatchedLinks($user, $sync['unmatched']);
        }

        // PWL-7 (job/seeder half): the media mirroring + auto-sync + identity-sync
        // above are all vendor I/O / heavy work — they stay OUTSIDE the lock, same
        // discipline as ConnectFetchJob::handle(). Only the authoritative row write
        // below is contended (a dashboard highlights save or a scheduled refresh can
        // race it via the SAME platformConnectionLock key), so only it is locked.
        $key = CacheKeyGenerator::platformConnectionLock($connection->platform, (string) $connection->user_id);
        try {
            Cache::lock($key, 10)->block(5, function () use ($connection, $selection) {
                $connection->update([
                    'payload' => $selection,
                    'is_active' => true,
                    'last_refreshed_at' => now(),
                    'last_refresh_status' => 'ok',
                    'last_refresh_error' => null,
                    'consecutive_failures' => 0,
                ]);
            });
        } catch (LockTimeoutException) {
            // SYNC-DRIVER CAVEAT: QUEUE_CONNECTION=sync means release()/retry never
            // happens (InstagramConnectJob's caller has no queue to catch a throw
            // and re-run). A swallowed timeout here would leave the row 'pending'
            // forever, so a terminal 'unavailable' write is mandatory — mirrors
            // ConnectFetchJob::handle()'s LockTimeoutException branch.
            Log::warning('instagram.connect_seeder.lock_timeout', [
                'connection_id' => $connection->id,
                'user_id' => (string) $connection->user_id,
            ]);
            $connection->forceFill([
                'last_refresh_status' => 'unavailable',
                'last_refresh_error' => "We couldn't save your Instagram connection just then — please try again.",
                'consecutive_failures' => (int) $connection->consecutive_failures + 1,
            ])->saveQuietly();
        }

        return $selection;
    }

    /** @param  list<array<string,mixed>>  $unmatched */
    private function autoSaveUnmatchedLinks(User $user, array $unmatched): void
    {
        // LinkRouter (inside CustomLinkSeeder::seed()) handles classification,
        // routing, commerce probes, and custom-link fallback. The re-classify
        // + probe dispatch this method used to do is now inside the router.
        //
        // ONE context for the whole list — it carries the per-run commerce probe
        // budget that replaced this class's own MAX_COMMERCE_PROBES counter
        // (signup-v2 C4). Per-link contexts would uncap it.
        $ctx = new RouteContext;

        foreach ($unmatched as $entry) {
            $url = is_array($entry) ? ($entry['url'] ?? null) : null;
            if (! is_string($url)) {
                continue;
            }

            $this->linkSeeder->seed($user, $url, $ctx);
        }
    }

    // Mirror a single image (cover or profile pic) to R2, STREAMED via a temp
    // file — same pattern as mirrorVideo below — so a large response never
    // buffers fully in worker memory (SCALE-102: the old body()/strlen() path
    // held the whole image in a PHP string before the size check even ran).
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
    //      file, with a check on the sunk temp file's actual on-disk size as a
    //      backstop for absent/inaccurate headers (replaces the old strlen check
    //      — same guarantee, now against a file instead of an in-memory string).
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

        $tmp = tempnam(sys_get_temp_dir(), 'igimg');
        if ($tmp === false) {
            return null;
        }

        try {
            $response = Http::timeout(self::IMAGE_TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withHeaders(['Accept' => 'image/*'])
                ->sink($tmp)
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

            // Hard cap enforced on what actually landed on disk — covers absent
            // or inaccurate Content-Length headers. Nothing over the limit
            // reaches R2.
            if ((int) filesize($tmp) > self::MAX_IMAGE_BYTES) {
                return null;
            }

            // Stream temp file → R2 (no in-memory copy of the image body).
            $stream = fopen($tmp, 'r');
            if ($stream === false) {
                return null;
            }
            $this->mediaDisk()->put($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return $this->mediaDisk()->url($path);
        } catch (Throwable $e) {
            // Report so a systemic mirror/R2 failure surfaces in Nightwatch (OBS-7).
            report($e);

            return null;
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }

    // Mirror a reel's mp4 to R2, retrying once on a transient failure (R1).
    // Every drop reason a genuine miss is logged so a "reel didn't mirror"
    // report is diagnosable from stored data instead of a guess.
    private function mirrorVideo(string $url, string $path): ?string
    {
        for ($attempt = 1; $attempt <= self::VIDEO_MIRROR_MAX_ATTEMPTS; $attempt++) {
            $transient = false;
            $result = $this->attemptMirrorVideo($url, $path, $transient);

            if ($result !== null || ! $transient) {
                return $result;
            }
            if ($attempt < self::VIDEO_MIRROR_MAX_ATTEMPTS) {
                usleep(300_000); // 300ms — long enough for a momentary CDN blip to clear
            }
        }
        $this->logVideoMirrorDrop('retries_exhausted', $url);

        return null;
    }

    /**
     * Every mirrorVideo() drop reason must be observable — a silent null
     * return here is indistinguishable from "no reel existed" after the
     * fact, which is exactly the ambiguity that made the broken-oven
     * investigation unable to confirm root cause. Host only, never the full
     * URL (SEC — avoid logging a CDN URL that could carry a signed/expiring
     * token).
     */
    private function logVideoMirrorDrop(string $reason, string $url): void
    {
        Log::info('instagram.mirror_video.dropped', [
            'reason' => $reason,
            'host' => parse_url($url, PHP_URL_HOST),
        ]);
    }

    // Single mirror attempt, STREAMED via a temp file so a large video never
    // buffers fully in worker memory, and size-capped so a pathological file
    // can't fill disk/R2. Same layered SSRF guard as mirrorOne (host
    // allowlist + IP-resolution check + redirects refused). Returns null on
    // any miss → the caller falls back to the poster. $transient is set true
    // only for failure classes that plausibly self-resolve on a bare retry
    // (a bad HTTP status, or a connection-level exception) — never for a
    // disallowed host, a SafeUrlException, a content-type mismatch, or
    // either oversize check, none of which would ever succeed on a retry.
    private function attemptMirrorVideo(string $url, string $path, bool &$transient): ?string
    {
        if (! $this->isAllowedHost($url)) {
            $this->logVideoMirrorDrop('host_not_allowed', $url);

            return null;
        }

        // IP-resolution guard matching mirrorOne — defence-in-depth. Already
        // report()-ed (Nightwatch); this is an anomalous case for an
        // allow-listed CDN host, not a routine drop reason.
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
                $transient = true;
                $this->logVideoMirrorDrop('bad_status_'.$response->status(), $url);

                return null;
            }

            $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
            if (! str_starts_with($contentType, 'video/')) {
                $this->logVideoMirrorDrop('bad_content_type', $url);

                return null;
            }

            // Fast rejection when the server declares the size upfront (mirrors
            // mirrorOne — an oversized reel shouldn't finish streaming to disk
            // before we notice).
            $contentLength = $response->header('Content-Length');
            if ((int) $contentLength > self::MAX_VIDEO_BYTES) {
                $this->logVideoMirrorDrop('oversize_header', $url);

                return null;
            }

            // Oversized reel → drop the video (the poster still renders). Covers
            // absent or inaccurate Content-Length headers.
            if ((int) filesize($tmp) > self::MAX_VIDEO_BYTES) {
                $this->logVideoMirrorDrop('oversize_actual', $url);

                return null;
            }

            // Stream temp file → R2 (no second in-memory copy of the video).
            $stream = fopen($tmp, 'r');
            if ($stream === false) {
                return null;
            }
            $this->mediaDisk()->put($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return $this->mediaDisk()->url($path);
        } catch (ConnectionException) {
            $transient = true;
            $this->logVideoMirrorDrop('connection_exception', $url);

            return null;
        } catch (Throwable $e) {
            report($e);

            return null;
        } finally {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        }
    }

    // The media disk MUST resolve through MediaDiskResolver, never the literal
    // 'media' disk name: on Laravel Cloud the 'media' disk's config-cached
    // credentials go stale (platform R2 creds are injected at runtime, after
    // config:cache), so every hardcoded disk('media') write/delete came back
    // Unauthorized — the 2026-07-23 root cause of profile pics, post photos and
    // reels silently never mirroring (and of the UnableToDeleteFile noise).
    // Resolved per call, not memoized: the resolver's own superglobal probe is
    // the source of truth and is cheap.
    private function mediaDisk(): FilesystemAdapter
    {
        return Storage::disk(MediaDiskResolver::resolve());
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
}
