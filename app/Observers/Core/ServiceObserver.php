<?php

namespace App\Observers\Core;

use App\Models\Core\User\Service;
use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheInvalidator;
use App\Services\Cache\UserCacheService;
use App\Services\User\SectionVisibilityService;
use Illuminate\Support\Facades\Log;

// V2: Invalidates cache and re-evaluates section visibility on service changes.
class ServiceObserver
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly UserCacheService $userCache,
        private readonly SectionVisibilityService $visibilityService,
        private readonly SiteCacheInvalidator $invalidator,
    ) {}

    private function bust(Service $service): ?User
    {
        $pro = null;
        try {
            $pro = User::query()->with('site')->find($service->user_id);
        } catch (\Throwable $e) {
            Log::warning('Professional lookup failed during cache bust', [
                'service_id' => $service->id,
                'user_id' => $service->user_id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        try {
            if ($pro) {
                // bustSite: false — touchParentSite() always follows in runHooks(), which fires
                // SiteObserver → invalidateSite(). Passing true would double-bust ~29 Redis keys.
                $this->userCache->invalidateUser($pro, bustSite: false);
            }
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on service change', [
                'service_id' => $service->id,
                'user_id' => $service->user_id,
                'message' => $e->getMessage(),
            ]);
        }

        return $pro;
    }

    /**
     * Columns whose change can affect whether the booking/services section block
     * is visible on the public page. Updates touching only unrelated columns
     * (sort_order, description, duration_minutes, currency_code) skip the
     * reevaluation to avoid unnecessary DB queries per save event.
     */
    private const VISIBILITY_AFFECTING_COLUMNS = ['is_active', 'price_cents', 'title', 'deleted_at'];

    public function created(Service $service): void
    {
        // New service always triggers visibility reevaluation — the block may need to appear.
        $this->runHooks($service, checkVisibility: true);
    }

    public function updated(Service $service): void
    {
        // Gate reevaluation on visibility-affecting column changes. Sort-order shuffles,
        // description edits, etc. still cache-bust (via bust() + touchParentSite) but
        // skip the extra DB queries for section visibility.
        $checkVisibility = $service->wasChanged(self::VISIBILITY_AFFECTING_COLUMNS);
        $this->runHooks($service, $checkVisibility);
    }

    public function deleted(Service $service): void
    {
        $this->runHooks($service, checkVisibility: true);
    }

    public function restored(Service $service): void
    {
        $this->runHooks($service, checkVisibility: true);
    }

    private function runHooks(Service $service, bool $checkVisibility = true): void
    {
        try {
            $pro = $this->bust($service);
            if ($checkVisibility) {
                $this->reevaluateBooking($service, $pro);
            }
            $this->touchParentSite($service, $pro);
        } catch (\Throwable $e) {
            Log::error('ServiceObserver hook failed', [
                'service_id' => $service->id,
                'user_id' => $service->user_id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Bump `sites.updated_at` so SiteObserver fires CloudflareCachePurgeJob and
     * the Redis cache key (`public.profile:{handle}:{updated_at_ts}`) rolls forward.
     *
     * Service field edits (title, description, price, duration) feed the public
     * profile payload via SitepageDataResolverService::buildServicesData but
     * don't otherwise mutate any Block — visibility re-evaluation is a no-op
     * when state is unchanged, so without this explicit touch a service edit
     * leaves the edge + Redis caches pinned on stale data for the full
     * subrequest TTL.
     */
    private function touchParentSite(Service $service, ?User $pro): void
    {
        $this->invalidator->touchSite($pro?->site, 'service mutation', [
            'service_id' => $service->id,
            'user_id' => $service->user_id,
        ]);
    }

    private function reevaluateBooking(Service $service, ?User $pro): void
    {
        try {
            $site = $pro?->site;
            if (! $site) {
                return;
            }

            foreach (['booking', 'services'] as $blockType) {
                $this->visibilityService->reevaluateEnabled(
                    (string) $service->user_id,
                    (string) $site->id,
                    $blockType,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Section visibility reevaluation failed on service change', [
                'service_id' => $service->id,
                'user_id' => $service->user_id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
