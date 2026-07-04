<?php

namespace App\Services\Cache;

use App\Models\Core\Site\Site;
use Illuminate\Support\Facades\Log;

/**
 * Shared "propagate a public-payload edit to the caches" helper for observers
 * whose model is NOT site.sites but feeds the public profile payload.
 *
 * Contract: calls touch() on the site, which fires SiteObserver::saved — the
 * real hub (Redis invalidateSite + CloudflareCachePurgeJob + WarmPublicSiteCacheJob).
 * Callers MUST NOT also dispatch CloudflareCachePurgeJob — that double-fires the
 * purge and the Redis DEL sweep for a single edit.
 *
 * Failure is swallowed + logged (never re-thrown): a cache-bookkeeping error
 * must not crash the originating write.
 */
class SiteCacheInvalidator
{
    /**
     * @param  \Closure|Site|null  $site  Pass a Closure (() => ?Site) for lazy resolution —
     *                                    the Closure is evaluated inside the try/catch, so relation-load errors (e.g. missing
     *                                    table in tests) are swallowed just like touch() failures.
     * @param  string  $reason  Mutation verb for the log (e.g. 'create', 'update', 'delete').
     * @param  array<string, mixed>  $context  Callsite fields for the log (model id, tenant id).
     */
    public function touchSite(\Closure|Site|null $site, string $reason, array $context = []): void
    {
        try {
            $resolved = $site instanceof \Closure ? $site() : $site;
            if ($resolved === null) {
                return;
            }
            $resolved->touch();
        } catch (\Throwable $e) {
            Log::warning('Site cache propagation via touch() failed — Redis invalidation + CF purge skipped', array_merge([
                'reason' => $reason,
            ], $context, ['message' => $e->getMessage()]));
        }
    }
}
