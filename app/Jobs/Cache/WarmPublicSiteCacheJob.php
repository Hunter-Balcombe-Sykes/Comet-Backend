<?php

namespace App\Jobs\Cache;

use App\Models\Core\User\User;
use App\Models\Core\Site\Site;
use App\Services\Cache\CacheLockService;
use App\Services\Cache\SiteCacheService;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// V2: Pre-warms public site cache after publish events. Prevents cold-cache latency for first visitor.
//
// Audit #12: for individuals, the legacy SiteCacheService::warmSiteCache populates
// a cache key that visitors of `<handle>.partna.au` never read — they hit the §28.8
// endpoint (IndividualProfileController) which uses its own CacheLockService key.
// This job now ALSO pre-fills that §28.8 key when the subdomain belongs to an
// individual, sharing the canonical builder + cache-key helpers so the two paths
// can't drift.
class WarmPublicSiteCacheJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    public int $timeout = 10;

    /**
     * Coalesce window: a single edit can touch the site more than once (media +
     * section-visibility), each firing SiteObserver. Without this, every touch
     * re-dispatches a full payload rebuild. Exceeds $timeout to avoid early release.
     */
    public int $uniqueFor = 120;

    public function uniqueId(): string
    {
        return strtolower($this->subdomain);
    }

    public function __construct(
        public string $subdomain
    ) {
        // Use the 'default' queue so standard workers pick this up automatically.
        // Previously dispatched to 'cache', a named queue that may not be consumed
        // in all worker deployments, which would silently prevent cache warming.
        $this->onQueue('default');
    }

    public function handle(
        SiteCacheService $siteCache,
        CacheLockService $cacheLock,
        IndividualProfilePayloadBuilder $builder,
    ): void {
        $subdomain = strtolower($this->subdomain);
        $siteCache->warmSiteCache($subdomain);

        // §28.8 warm — best-effort. A miss here costs the first visitor full
        // payload assembly but never breaks correctness; swallow errors so a
        // transient Professional/Site lookup failure doesn't trip job retries.
        try {
            $pro = User::query()->where('handle_lc', $subdomain)->first();
            if (! $pro) {
                return;
            }

            $site = Site::query()->where('user_id', $pro->id)->first();
            $cacheLock->rememberLocked(
                $builder->cacheKey($subdomain, $site, $pro),
                $builder->cacheTtl(),
                fn () => $builder->build($pro, $site),
            );
        } catch (\Throwable $e) {
            Log::warning('WarmPublicSiteCacheJob: §28.8 warm failed', [
                'subdomain' => $subdomain,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        report($e);
    }
}
