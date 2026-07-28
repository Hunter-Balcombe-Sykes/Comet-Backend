<?php

namespace App\Services\Cache\Concerns;

trait DefersRecompute
{
    /**
     * Whether an SWR recompute may run after the response instead of in-request.
     *
     * The console check is load-bearing, not incidental: defer() also flushes
     * after queued jobs (JobAttempted) and artisan commands (CommandFinished),
     * so without it WarmPublicSiteCacheJob would report success before writing
     * the cache entry it exists to warm.
     */
    private function shouldDeferRecompute(): bool
    {
        return (bool) config('partna.cache.swr_defer_recompute', true)
            && ! app()->runningInConsole();
    }
}
