<?php

namespace App\Observers\Core;

use App\Models\Core\Professional\User;
use App\Models\Core\Professional\ServiceCategory;
use App\Services\Cache\ProfessionalCacheService;
use Illuminate\Support\Facades\Log;

// §28.17 CACHE-1 — ServiceCategory mutations bust the dashboard services
// cache. Prior to this observer, category renames/deletes/reorders left
// `ProfessionalCacheService::getDashboardServices` stale for up to the
// full 30-minute TTL because no observer was registered for the model.
//
// Mirrors ServiceObserver's pattern: $afterCommit so a transactional
// write triggers exactly one bust, each step in try/catch so a cache
// failure can't bubble up into the originating request as a 500.
class ServiceCategoryObserver
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly ProfessionalCacheService $professionalCache,
    ) {}

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
        $professionalId = trim((string) ($category->professional_id ?? ''));
        if ($professionalId === '') {
            return;
        }

        try {
            $pro = User::query()->find($professionalId);
        } catch (\Throwable $e) {
            Log::warning('Professional lookup failed during ServiceCategory bust', [
                'category_id' => $category->id,
                'professional_id' => $professionalId,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        if (! $pro) {
            return;
        }

        try {
            $this->professionalCache->invalidateProfessional($pro);
        } catch (\Throwable $e) {
            Log::warning('Professional cache invalidation failed on ServiceCategory change', [
                'category_id' => $category->id,
                'professional_id' => $professionalId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
