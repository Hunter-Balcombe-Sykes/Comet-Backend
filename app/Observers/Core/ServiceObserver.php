<?php

namespace App\Observers\Core;

use App\Models\Core\User\User;
use App\Models\Core\User\Service;
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
                $this->userCache->invalidateUser($pro);
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

    public function saved(Service $service): void
    {
        $this->runHooks($service);
    }

    public function deleted(Service $service): void
    {
        $this->runHooks($service);
    }

    public function restored(Service $service): void
    {
        $this->runHooks($service);
    }

    private function runHooks(Service $service): void
    {
        try {
            $pro = $this->bust($service);
            $this->reevaluateBooking($service, $pro);
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
        try {
            $pro?->site?->touch();
        } catch (\Throwable $e) {
            Log::warning('Parent site touch() failed on service mutation', [
                'service_id' => $service->id,
                'user_id' => $service->user_id,
                'message' => $e->getMessage(),
            ]);
        }
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
