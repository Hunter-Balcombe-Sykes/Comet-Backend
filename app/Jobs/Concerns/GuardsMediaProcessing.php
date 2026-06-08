<?php

namespace App\Jobs\Concerns;

use App\Models\Core\Site\SiteMedia;
use Illuminate\Support\Facades\Redis;

/**
 * Shared concurrency + reprocessing guards for the media-variant jobs
 * (ProcessImageVariantsJob, ProcessVideoVariantsJob). Extracted so the lock
 * strategy and the terminal-state set live in exactly one place — they were
 * previously duplicated and the jobs' own comments cross-referenced each other
 * (SLOP-12, minimal extraction).
 *
 * The using job must declare a public int $timeout property.
 */
trait GuardsMediaProcessing
{
    /**
     * Acquire the in-flight processing lock for this media row.
     *
     * SET NX with a TTL of $timeout + 60s so the lock auto-expires after a
     * crashed worker, letting a later retry re-acquire. Returns false when
     * another worker already holds it.
     */
    protected function acquireProcessingLock(string $lockKey): bool
    {
        return (bool) Redis::set($lockKey, '1', 'EX', $this->timeout + 60, 'NX');
    }

    /**
     * Release the in-flight processing lock (call in a finally block).
     */
    protected function releaseProcessingLock(string $lockKey): void
    {
        Redis::del($lockKey);
    }

    /**
     * Whether the media row has already reached a terminal processing state.
     * Used to skip redelivered jobs that would otherwise overwrite ready/failed
     * back to processing.
     */
    protected function isInTerminalState(SiteMedia $siteMedia): bool
    {
        return in_array(
            $siteMedia->processing_state,
            [SiteMedia::PROCESSING_STATE_READY, SiteMedia::PROCESSING_STATE_FAILED],
            true
        );
    }
}
