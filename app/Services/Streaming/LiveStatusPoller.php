<?php

namespace App\Services\Streaming;

use App\Exceptions\Streaming\KickRateLimitException;
use App\Exceptions\Streaming\TwitchRateLimitException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Polls live status for streaming platforms and writes results to Redis.
 * No DB writes — live status is ephemeral.
 *
 * Item 11d (2026-09-01): one vendor-first check per platform. Twitch asks
 * ScrapeCreators first and falls through to the untouched Helix batch path on
 * any miss — a bad vendor day degrades to today's behaviour, never to silence.
 * TikTok and YouTube are vendor-ONLY (they never had an incumbent client):
 * a miss there means status UNKNOWN, so nothing is written and the prior
 * Redis value keeps serving until its TTL lapses — never a false "offline".
 * Kick stays on KickApiClient — the vendor has no live surface for it and the
 * owner kept Kick link-only (Item 10a).
 *
 * Cold-handle demotion: handles offline for N consecutive reads get a longer
 * TTL, which skips them on subsequent cycles via filterStaleHandles. This is
 * the main scalability lever — most streaming handles are offline most of the
 * time; tiered TTLs let the poller spend its API/credit budget on likely-live
 * handles. It matters MORE now: the vendor legs are one billed call per
 * handle, so the tiers are what keep an every-2-min cadence affordable.
 *
 * ⚠️ ORPHANED READER (2026-09-04). Nothing in the application reads the
 * `streaming:live:<platform>:<handle>` keys any more. Their sole reader was
 * App\Services\Streaming\LiveStatusInjector, which post-processed the legacy
 * public-site payload and was deleted with GET /api/public/site,
 * /api/public/site-by-slug and PublicSiteController. The canonical public lane
 * (IndividualProfileController) never consumed live status. The writer side is
 * untouched: CheckStreamingLiveStatusJob is still scheduled everyTwoMinutes
 * (routes/console.php), so anything this poller writes now expires unread.
 *
 * The waste is LATENT, not current. Handles come only from blocks with
 * live_check_enabled=true, and there are none — 0 on dev (of 30 blocks), 0 on
 * prod (of 0), verified 2026-09-04 — so the job short-circuits on its handle
 * gather every tick and never reaches this poller at all. Present cost
 * is one indexed query per two minutes; vendor spend is zero. But the flag is
 * user-settable (StoreLinkBlockRequest / UpdateLinkBlockRequest), so the FIRST
 * owner who enables live-check on a twitch/tiktok/youtube link block starts
 * one billed vendor call per handle per tick, for a value nothing reads.
 * That makes this armed, not idle: removing or pausing it is an OWNER DECISION
 * about a live third-party billed surface, not a cleanup — do not unschedule
 * it as a tidy-up, and do not dismiss it as free because today's bill is 0.
 */
class LiveStatusPoller
{
    private const LIVE_KEY_PREFIX = 'streaming:live:';

    private const OFFLINE_COUNT_PREFIX = 'streaming:offline_count:';

    private const KICK_RATE_LIMITED_KEY = 'streaming:kick:rate_limited';

    private const KICK_RATE_LIMITED_TTL = 300;

    // TTL defaults — actual values read from config('partna.streaming.*') at runtime
    // so ops can tune demotion aggressiveness without a code deploy.
    private const LIVE_TTL_DEFAULT = 180;

    private const WARM_OFFLINE_DEFAULT = 180;

    private const COOL_OFFLINE_DEFAULT = 600;

    private const COLD_OFFLINE_DEFAULT = 1800;

    private const TTL_SKIP_DEFAULT = 60;

    private const TWITCH_BATCH_SIZE = 100;

    private const KICK_BATCH_SIZE = 50;           // Matches KickApiClient::KICK_BATCH_SIZE

    public function __construct(
        private TwitchApiClient $twitch,
        private KickApiClient $kick,
        private ScrapeCreatorsLiveClient $vendor
    ) {}

    /**
     * Poll $platform for the given $handles and write results to Redis.
     *
     * $deadline (a microtime(true) instant) bounds the vendor legs: each
     * vendor call is one HTTP round-trip per handle with a config-tunable
     * timeout, so an unbounded loop could outrun the calling job's own
     * $timeout — the exact worker-kill → MaxAttemptsExceeded class this
     * refactor retires. Past the deadline, remaining handles read as vendor
     * misses (Twitch still gets its Helix batch; the rest keep prior status).
     *
     * @param  string[]  $handles  Raw handles (may contain duplicates)
     */
    public function poll(string $platform, array $handles, ?float $deadline = null): void
    {
        $handles = array_values(array_unique($handles));
        $handles = $this->filterStaleHandles($platform, $handles);

        if (empty($handles)) {
            return;
        }

        match ($platform) {
            'twitch' => $this->pollTwitch($handles, $deadline),
            'kick' => $this->pollKick($handles),
            'tiktok', 'youtube' => $this->pollVendorOnly($platform, $handles, $deadline),
            default => Log::warning('streaming.unknown_platform', ['platform' => $platform]),
        };
    }

    /** @param string[] $handles */
    private function pollTwitch(array $handles, ?float $deadline = null): void
    {
        // Vendor-first: every miss (disabled lane, budget denied, transport,
        // husk, deadline) queues the handle for the untouched Helix path.
        $fallback = [];
        foreach ($handles as $handle) {
            $isLive = $this->deadlinePassed($deadline) ? null : $this->vendor->isLive('twitch', $handle);
            if ($isLive === null) {
                $fallback[] = $handle;

                continue;
            }
            $this->writeStatus('twitch', $handle, $isLive);
        }

        foreach (array_chunk($fallback, self::TWITCH_BATCH_SIZE) as $batch) {
            try {
                $liveSet = array_flip($this->twitch->getLiveHandles($batch));
                foreach ($batch as $handle) {
                    $this->writeStatus('twitch', $handle, isset($liveSet[$handle]));
                }
            } catch (TwitchRateLimitException $e) {
                Log::warning('streaming.rate_limit', ['platform' => 'twitch', 'retry_after' => $e->retryAfter]);

                // Stop polling Twitch this cycle; handles keep their prior status until TTL (no false-offline).
                return;
            }
        }
    }

    /** @param string[] $handles */
    private function pollKick(array $handles): void
    {
        foreach (array_chunk($handles, self::KICK_BATCH_SIZE) as $batch) {
            try {
                $liveSet = array_flip($this->kick->getLiveHandles($batch));
                foreach ($batch as $handle) {
                    $this->writeStatus('kick', $handle, isset($liveSet[$handle]));
                }
            } catch (KickRateLimitException $e) {
                Log::warning('streaming.rate_limit', [
                    'platform' => 'kick',
                    'retry_after' => $e->retryAfter,
                ]);
                // Flip the circuit breaker and stop polling Kick for this cycle
                // (and subsequent cycles until the flag expires).
                Redis::set(self::KICK_RATE_LIMITED_KEY, '1', 'EX', (int) config('partna.streaming.kick_rate_limited_ttl', self::KICK_RATE_LIMITED_TTL));

                return;
            }
        }
    }

    /**
     * TikTok/YouTube: the vendor IS the lane — a miss is "status unknown",
     * never "offline", so no write happens and the prior value serves until
     * its TTL lapses. Misses are logged as ONE aggregate line per platform
     * per cycle, not per handle (log-flood discipline).
     *
     * @param  string[]  $handles
     */
    private function pollVendorOnly(string $platform, array $handles, ?float $deadline): void
    {
        $unknown = 0;
        foreach ($handles as $handle) {
            $isLive = $this->deadlinePassed($deadline) ? null : $this->vendor->isLive($platform, $handle);
            if ($isLive === null) {
                $unknown++;

                continue;
            }
            $this->writeStatus($platform, $handle, $isLive);
        }

        if ($unknown > 0) {
            Log::info('streaming.vendor_status_unknown', ['platform' => $platform, 'handles' => $unknown]);
        }
    }

    private function deadlinePassed(?float $deadline): bool
    {
        return $deadline !== null && microtime(true) >= $deadline;
    }

    /**
     * Write live status + manage the consecutive-offline counter that drives TTL tiers.
     * Live writes reset the counter; offline writes increment and pick a tiered TTL.
     */
    private function writeStatus(string $platform, string $handle, bool $isLive): void
    {
        $liveKey = self::LIVE_KEY_PREFIX."{$platform}:{$handle}";
        $countKey = self::OFFLINE_COUNT_PREFIX."{$platform}:{$handle}";

        if ($isLive) {
            Redis::set($liveKey, '1', 'EX', (int) config('partna.streaming.live_ttl_seconds', self::LIVE_TTL_DEFAULT));
            Redis::del($countKey);

            return;
        }

        $count = (int) Redis::incr($countKey);
        // Counter survives a day of inactivity so rarely-polled cold handles
        // don't lose their tier when the 30-min TTL lapses between cycles.
        Redis::expire($countKey, 86400);

        $ttl = match (true) {
            $count >= 11 => (int) config('partna.streaming.cold_offline_ttl', self::COLD_OFFLINE_DEFAULT),
            $count >= 3 => (int) config('partna.streaming.cool_offline_ttl', self::COOL_OFFLINE_DEFAULT),
            default => (int) config('partna.streaming.warm_offline_ttl', self::WARM_OFFLINE_DEFAULT),
        };

        Redis::set($liveKey, '0', 'EX', $ttl);
    }

    /**
     * Returns handles whose Redis key is missing or has TTL <= threshold.
     * Handles with fresh entries are skipped — no API call needed.
     * This is where cold-handle demotion takes effect: demoted handles have
     * a longer TTL and are filtered out on most cycles.
     *
     * @param  string[]  $handles
     * @return string[]
     */
    private function filterStaleHandles(string $platform, array $handles): array
    {
        return array_values(array_filter($handles, function (string $handle) use ($platform): bool {
            $key = self::LIVE_KEY_PREFIX."{$platform}:{$handle}";
            $ttl = Redis::ttl($key);

            // -2 = key doesn't exist, -1 = no TTL, any value <= threshold = stale
            return $ttl < (int) config('partna.streaming.ttl_skip_threshold', self::TTL_SKIP_DEFAULT);
        }));
    }
}
