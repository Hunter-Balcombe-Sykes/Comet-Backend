<?php

namespace App\Jobs\Streaming;

use App\Models\Core\Site\Block;
use App\Services\Streaming\LiveStatusPoller;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

// Polls live status for every block with live_check_enabled=true — vendor-first
// per platform via ScrapeCreators (Item 11d), Helix fallback for Twitch, Kick
// unchanged on its own API. Scheduled: every 2 minutes via routes/console.php.
class CheckStreamingLiveStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // MaxAttemptsExceeded hygiene (Nightwatch #339, open since July): tries=1
    // turned every REDELIVERY (worker killed mid-run, retry_after lapse) into
    // "has been attempted too many times" before the tick even ran. tries=3
    // lets a redelivered tick simply run — its writes are idempotent and the
    // WithoutOverlapping lock below drops true overlaps. maxExceptions=1 keeps
    // the old intent: a tick that actually THREW is never retried, because the
    // next scheduled tick two minutes out IS the retry.
    public int $tries = 3;

    public int $maxExceptions = 1;

    public int $backoff = 30;

    /**
     * Poll budget handed to LiveStatusPoller as a hard deadline: the vendor
     * legs cost one HTTP round-trip per handle, so this is what guarantees the
     * job finishes inside $timeout instead of dying to the worker's kill — the
     * OTHER historical source of the MaxAttemptsExceeded noise.
     */
    private const POLL_DEADLINE_SECONDS = 75;

    public function __construct()
    {
        // Isolated queue prevents the 90s polling window from blocking short jobs on default.
        $this->onQueue(config('partna.queues.streaming', 'streaming'));
    }

    public int $timeout = 90;

    /**
     * Drop overlapping ticks rather than re-queuing — the next scheduled tick covers any gap.
     * expireAfter(120) self-clears a crashed worker's lock (just over the 90s timeout).
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('streaming:live-status'))->dontRelease()->expireAfter(120)];
    }

    public function handle(LiveStatusPoller $poller): void
    {
        $streamingPlatforms = config('partna.streaming_platforms', []);

        /** @var array<string, list<string>> $handlesByPlatform */
        $handlesByPlatform = array_fill_keys($streamingPlatforms, []);

        // block_group='links' (NOT block_type='link') is the links/sections discriminator.
        // live_check_enabled + platform are promoted columns; handle stays in settings JSONB.
        Block::query()
            ->where('block_group', 'links')
            ->where('live_check_enabled', true)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->chunkById(500, function ($blocks) use (&$handlesByPlatform, $streamingPlatforms): void {
                foreach ($blocks as $block) {
                    $platform = $block->platform;
                    $settings = is_array($block->settings) ? $block->settings : [];
                    $handle = $settings['handle'] ?? null;

                    if (
                        $platform
                        && $handle
                        && in_array($platform, $streamingPlatforms, true)
                    ) {
                        $handlesByPlatform[$platform][] = $handle;
                    }
                }
            });

        // Short-circuit: no live-check-enabled blocks means nothing to poll, so skip
        // the Kick circuit-breaker read and the poll pipeline. Gathering handles first
        // (the query runs regardless) lets the every-2-min tick stay near-free — one
        // indexed query, no Redis round-trip — while the feature is unused.
        if (array_filter($handlesByPlatform) === []) {
            return;
        }

        try {
            $kickRateLimited = Redis::exists('streaming:kick:rate_limited');
        } catch (\Throwable $e) {
            // Redis-down is reported to Nightwatch here (OBS-11). Not $this->fail(): in the
            // sync/unit path $this->job is null so fail() — and thus failed()/its report() — no-ops.
            Log::error('streaming.redis_unavailable', ['message' => $e->getMessage()]);
            report($e);

            return;
        }

        if ($kickRateLimited) {
            Log::warning('streaming: skipping Kick — rate limited from previous cycle');
        }

        // One deadline across ALL platforms — a slow vendor day on the first
        // platform must not push the later ones past the worker's kill.
        $deadline = microtime(true) + self::POLL_DEADLINE_SECONDS;

        foreach ($handlesByPlatform as $platform => $handles) {
            if (empty($handles)) {
                continue;
            }

            if ($platform === 'kick' && $kickRateLimited) {
                continue;
            }

            try {
                $poller->poll($platform, $handles, $deadline);
            } catch (\Throwable $e) {
                report($e);
                Log::error('streaming.poll_error', [
                    'platform' => $platform,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        report($e);
        Log::error('streaming.job_failed', ['message' => $e->getMessage()]);
    }
}
