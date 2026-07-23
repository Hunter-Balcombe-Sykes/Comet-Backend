<?php

namespace App\Jobs\Concerns;

use App\Models\Core\Site\SiteMedia;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

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
     * The lock instance acquired by acquireProcessingLock(), held so
     * releaseProcessingLock() releases the SAME instance (R3-CCH-1). A fresh
     * Cache::lock() call mints a new random owner token; release() on that
     * new instance would silently no-op instead of releasing the lock this
     * job actually holds — this property is what keeps acquire/release
     * pinned to one owner within a single job invocation.
     */
    protected ?Lock $processingLock = null;

    /**
     * Acquire the in-flight processing lock for this media row.
     *
     * Cache::lock() with a TTL of $timeout + 60s so the lock auto-expires
     * after a crashed worker, letting a later retry re-acquire. Returns false
     * when another worker already holds it. Routed through Cache::lock()
     * (not a bare Redis::set) so the lock lands on the dedicated cache_locks
     * connection (config/database.php), matching GoogleBusinessEnrichJob,
     * EnrichLinkCardJob, and ConnectFetchJob.
     */
    protected function acquireProcessingLock(string $lockKey): bool
    {
        $this->processingLock = Cache::lock($lockKey, $this->timeout + 60);

        return $this->processingLock->get();
    }

    /**
     * Release the in-flight processing lock (call in a finally block).
     * No-ops if this instance never acquired the lock (e.g. acquire returned
     * false) — release() checks ownership before deleting the key.
     */
    protected function releaseProcessingLock(string $lockKey): void
    {
        $this->processingLock?->release();
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
