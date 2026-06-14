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

// Polls Twitch and Kick for live status of all blocks with live_check_enabled=true.
// Scheduled: every 2 minutes via routes/console.php.
class CheckStreamingLiveStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // tries=1 means no retry, so $backoff is moot at runtime — but JobHygienePolicyTest
    // requires every ShouldQueue job to declare $tries, $backoff, and $timeout.
    public int $backoff = 0;

    public function __construct()
    {
        // Isolated queue prevents the 90s polling window from blocking short jobs on default.
        $this->onQueue('streaming');
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
        try {
            $kickRateLimited = Redis::exists('streaming:kick:rate_limited');
        } catch (\Throwable $e) {
            Log::error('streaming.redis_unavailable', ['message' => $e->getMessage()]);
            report($e);

            return;
        }

        if ($kickRateLimited) {
            Log::warning('streaming: skipping Kick — rate limited from previous cycle');
        }

        $streamingPlatforms = config('partna.streaming_platforms', []);

        /** @var array<string, list<string>> $handlesByPlatform */
        $handlesByPlatform = array_fill_keys($streamingPlatforms, []);

        // block_group='links' (NOT block_type='link') is the links/sections discriminator
        // in site.blocks. All other queries in the codebase use block_group.
        Block::query()
            ->where('block_group', 'links')
            ->whereRaw("settings->>'live_check_enabled' = ?", ['true'])
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->chunkById(500, function ($blocks) use (&$handlesByPlatform, $streamingPlatforms): void {
                foreach ($blocks as $block) {
                    $settings = is_array($block->settings) ? $block->settings : [];
                    $platform = $settings['platform'] ?? null;
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

        foreach ($handlesByPlatform as $platform => $handles) {
            if (empty($handles)) {
                continue;
            }

            if ($platform === 'kick' && $kickRateLimited) {
                continue;
            }

            try {
                $poller->poll($platform, $handles);
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
