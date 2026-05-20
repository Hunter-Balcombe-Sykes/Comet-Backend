<?php

namespace App\Observers\Core;

use App\Models\Core\Site\Block;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Log;

// V2: Invalidates site cache when any block (link, section) is created, updated, or deleted.
class BlockObserver
{
    use LogsWithRequestContext;

    public bool $afterCommit = true;

    public function __construct(
        private SiteCacheService $siteCache
    ) {}

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
     * Shared invalidate + propagate path for every mutation.
     *
     * Two steps that must both run:
     *
     *  1. `invalidateSite($block->site)` busts the local Redis caches
     *     (site:payload + site_blocks:* + the Hydrogen affiliate response).
     *  2. `$block->site->touch()` advances `sites.updated_at`, which fires
     *     `SiteObserver::saved` → dispatches `CloudflareCachePurgeJob`.
     *     Without this step, Cloudflare's edge cache would hold the
     *     pre-mutation HTML for the full `s-maxage` window (~5 min) before
     *     re-fetching. That was the visible bug pre-fix: link adds /
     *     bio edits / section toggles didn't reflect on `<handle>.partna.au`
     *     for up to 5–15 min.
     *
     * `touch()` only changes `updated_at`. SiteObserver's other dispatches
     * (SyncSubdomainToKvJob, ProvisionBrandDnsJob, RetireBrandDnsJob) gate
     * on `wasChanged('subdomain')` and won't fire here — we pay the cost
     * of exactly one extra UPDATE + one CF purge enqueue, no expensive
     * KV / DNS side-effects.
     */
    private function onBlockMutated(Block $block, string $action): void
    {
        if (! $block->site) {
            return;
        }

        try {
            $this->siteCache->invalidateSite($block->site);
        } catch (\Throwable $e) {
            Log::warning('Site cache invalidation failed on block '.$action, $this->logContext(__METHOD__, [
                'block_id' => $block->id,
                'site_id' => $block->site->id,
                'message' => $e->getMessage(),
            ]));
        }

        try {
            $block->site->touch();
        } catch (\Throwable $e) {
            Log::warning('Parent site touch() failed on block '.$action, $this->logContext(__METHOD__, [
                'block_id' => $block->id,
                'site_id' => $block->site->id,
                'message' => $e->getMessage(),
            ]));
        }
    }
}
