<?php

namespace App\Jobs\Cache;

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Cache\CacheLockService;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

// V2: Pre-warms the public sitepage cache after publish events, so the first
// visitor does not pay a cold build.
//
// Warms the IndividualProfileController key only. The legacy
// SiteCacheService::warmSiteCache key was removed 2026-09-04 with the rest of
// the payload lane — Audit #12 had already recorded that visitors of
// `<handle>.partna.au` never read it.
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
        // Isolated on its own queue so a burst of cache-warm dispatches after a
        // publish event doesn't compete with user-facing notifications or mail.
        // Workers are configured in config/horizon.php under supervisor-cache-warm.
        $this->onQueue(config('partna.queues.cache_warm', 'cache-warm'));
    }

    public function handle(
        CacheLockService $cacheLock,
        IndividualProfilePayloadBuilder $builder,
    ): void {
        $subdomain = strtolower($this->subdomain);

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
            report($e);
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
