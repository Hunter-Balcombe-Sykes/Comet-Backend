<?php

namespace App\Observers\Core;

use App\Models\Core\Professional\User;
use App\Models\Core\Professional\Service;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Professional\SectionVisibilityService;
use Illuminate\Support\Facades\Log;

// V2: Invalidates cache and re-evaluates section visibility on service changes.
class ServiceObserver
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly ProfessionalCacheService $professionalCache,
        private readonly SectionVisibilityService $visibilityService,
    ) {}

    private function bust(Service $service): ?User
    {
        $pro = null;
        try {
            $pro = User::query()->with('site')->find($service->professional_id);
        } catch (\Throwable $e) {
            Log::warning('Professional lookup failed during cache bust', [
                'service_id' => $service->id,
                'professional_id' => $service->professional_id,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        try {
            if ($pro) {
                $this->professionalCache->invalidateProfessional($pro);
            }
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on service change', [
                'service_id' => $service->id,
                'professional_id' => $service->professional_id,
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
        } catch (\Throwable $e) {
            Log::error('ServiceObserver hook failed', [
                'service_id' => $service->id,
                'professional_id' => $service->professional_id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
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
                    (string) $service->professional_id,
                    (string) $site->id,
                    $blockType,
                );
            }
        } catch (\Throwable $e) {
            Log::warning('Section visibility reevaluation failed on service change', [
                'service_id' => $service->id,
                'professional_id' => $service->professional_id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
