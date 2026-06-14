<?php

namespace App\Services\Streaming;

use App\Exceptions\Streaming\KickRateLimitException;
use App\Exceptions\Streaming\TwitchRateLimitException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Polls Twitch and Kick APIs for live status and writes results to Redis.
 * No DB writes — live status is ephemeral.
 *
 * Cold-handle demotion: handles offline for N consecutive reads get a longer
 * TTL, which skips them on subsequent cycles via filterStaleHandles. This is
 * the main scalability lever — most streaming handles are offline most of the
 * time; tiered TTLs let the poller spend its API budget on likely-live handles.
 */
class LiveStatusPoller
{
    private const LIVE_KEY_PREFIX = 'streaming:live:';

    private const OFFLINE_COUNT_PREFIX = 'streaming:offline_count:';

    private const KICK_RATE_LIMITED_KEY = 'streaming:kick:rate_limited';

    private const KICK_RATE_LIMITED_TTL = 300;

    // TTLs by tier. Keys stale at TTL <= TTL_SKIP_THRESHOLD are re-polled.
    private const LIVE_TTL_SECONDS = 180;        // Live handle — freshness 2 min

    private const WARM_OFFLINE_TTL = 180;         // 1-2 offline reads — still poll every cycle

    private const COOL_OFFLINE_TTL = 600;         // 3-10 offline reads — poll ~every 10 min

    private const COLD_OFFLINE_TTL = 1800;        // 11+ offline reads — poll ~every 30 min

    private const TTL_SKIP_THRESHOLD = 60;        // Skip handles whose TTL hasn't dropped under 60s yet

    private const TWITCH_BATCH_SIZE = 100;

    private const KICK_BATCH_SIZE = 50;           // Matches KickApiClient::KICK_BATCH_SIZE

    public function __construct(
        private TwitchApiClient $twitch,
        private KickApiClient $kick
    ) {}

    /**
     * Poll $platform for the given $handles and write results to Redis.
     *
     * @param  string[]  $handles  Raw handles (may contain duplicates)
     */
    public function poll(string $platform, array $handles): void
    {
        $handles = array_values(array_unique($handles));
        $handles = $this->filterStaleHandles($platform, $handles);

        if (empty($handles)) {
            return;
        }

        match ($platform) {
            'twitch' => $this->pollTwitch($handles),
            'kick' => $this->pollKick($handles),
            default => Log::warning('streaming.unknown_platform', ['platform' => $platform]),
        };
    }

    /** @param string[] $handles */
    private function pollTwitch(array $handles): void
    {
        foreach (array_chunk($handles, self::TWITCH_BATCH_SIZE) as $batch) {
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
                Redis::set(self::KICK_RATE_LIMITED_KEY, '1', 'EX', self::KICK_RATE_LIMITED_TTL);

                return;
            }
        }
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
            Redis::set($liveKey, '1', 'EX', self::LIVE_TTL_SECONDS);
            Redis::del($countKey);

            return;
        }

        $count = (int) Redis::incr($countKey);
        // Counter survives a day of inactivity so rarely-polled cold handles
        // don't lose their tier when the 30-min TTL lapses between cycles.
        Redis::expire($countKey, 86400);

        $ttl = match (true) {
            $count >= 11 => self::COLD_OFFLINE_TTL,
            $count >= 3 => self::COOL_OFFLINE_TTL,
            default => self::WARM_OFFLINE_TTL,
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
            return $ttl < self::TTL_SKIP_THRESHOLD;
        }));
    }
}
