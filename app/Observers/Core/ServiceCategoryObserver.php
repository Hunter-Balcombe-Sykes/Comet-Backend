<?php

namespace App\Observers\Core;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\User\User;
use App\Models\Core\User\ServiceCategory;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// §28.17 CACHE-1 — ServiceCategory mutations bust the dashboard services
// cache. Prior to this observer, category renames/deletes/reorders left
// `UserCacheService::getDashboardServices` stale for up to the
// full 30-minute TTL because no observer was registered for the model.
//
// Mirrors ServiceObserver's pattern: $afterCommit so a transactional
// write triggers exactly one bust, each step in try/catch so a cache
// failure can't bubble up into the originating request as a 500.
class ServiceCategoryObserver
{
    public bool $afterCommit = true;

    public function saved(ServiceCategory $category): void
    {
        $this->bust($category);
    }

    public function deleted(ServiceCategory $category): void
    {
        $this->bust($category);
    }

    public function restored(ServiceCategory $category): void
    {
        $this->bust($category);
    }

    private function bust(ServiceCategory $category): void
    {
        $userId = trim((string) ($category->user_id ?? ''));
        if ($userId === '') {
            return;
        }

        // Only the services keys are stale after a category rename/reorder/delete.
        // Calling invalidateUser() would nuke 13+ keys (hydrated model,
        // payloads, ID maps, customer count) causing unnecessary Postgres round-trips.
        try {
            Cache::deleteMultiple([
                CacheKeyGenerator::professionalDashboardServices($userId),
                CacheKeyGenerator::professionalDashboardServices($userId).':stale',
                CacheKeyGenerator::professionalServices($userId),
                CacheKeyGenerator::professionalServices($userId).':stale',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Services cache bust failed on ServiceCategory change', [
                'category_id' => $category->id,
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
        }

        // Category titles are embedded in the public site payload's services array
        // (SitepageDataResolverService::buildServicesData), so a rename also stales
        // the cached page. Call invalidateSitePayload() directly (not invalidateSite)
        // because no media rows changed — the 18 image-view variant keys are still valid.
        // Dispatch CloudflareCachePurgeJob directly rather than routing through touch() +
        // SiteObserver, which would also bust professionalModel + emailBrand as collateral.
        try {
            $pro = User::query()->with('site')->find($userId);
            $site = $pro?->site;
            if ($site) {
                app(SiteCacheService::class)->invalidateSitePayload($site);
                CloudflareCachePurgeJob::dispatch($site->subdomain);
            }
        } catch (\Throwable $e) {
            Log::warning('Site cache bust failed on ServiceCategory change', [
                'category_id' => $category->id,
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
