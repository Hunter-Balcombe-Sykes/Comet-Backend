<?php

namespace App\Observers\Core;

use App\Models\Core\Site\Block;
use App\Observers\Concerns\LogsWithRequestContext;
use Illuminate\Support\Facades\Log;

// V2: Invalidates site cache when any block (link, section) is created, updated, or deleted.
class BlockObserver
{
    use LogsWithRequestContext;

    public bool $afterCommit = true;

    public function created(Block $block): void
    {
        $this->onBlockMutated($block, 'create');
    }

    public function updated(Block $block): void
    {
        $this->onBlockMutated($block, 'update');
    }

    public function deleted(Block $block): void
    {
        $this->onBlockMutated($block, 'delete');
    }

    /**
     * Shared propagate path for every mutation.
     *
     * `touch()` advances `sites.updated_at`, which fires `SiteObserver::saved`.
     * That observer handles everything: Redis invalidation (invalidateSite),
     * Cloudflare edge-cache purge (CloudflareCachePurgeJob), and cache warm-up
     * (WarmPublicSiteCacheJob). We do NOT call invalidateSite directly here —
     * that would fire two identical Redis DEL sweeps on every block change.
     *
     * `touch()` only changes `updated_at`. SiteObserver's other dispatches
     * (SyncSubdomainToKvJob) gate on `wasChanged('subdomain')` and won't fire
     * here — we pay exactly one UPDATE + one CF purge enqueue, no KV side-effects.
     */
    private function onBlockMutated(Block $block, string $action): void
    {
        if (! $block->site) {
            return;
        }

        try {
            $block->site->touch();
        } catch (\Throwable $e) {
            // touch() failure means Redis invalidation AND Cloudflare purge are both
            // skipped (they run via SiteObserver::saved). Log with full context so a
            // single entry is enough to diagnose the paired failure.
            Log::warning('Block observer: site touch() failed — Redis + CF purge skipped on block '.$action, $this->logContext(__METHOD__, [
                'block_id' => $block->id,
                'site_id' => $block->site->id,
                'message' => $e->getMessage(),
            ]));
        }
    }
}
