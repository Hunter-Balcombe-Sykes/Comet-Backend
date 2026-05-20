<?php

namespace App\Observers\Brand;

use App\Models\Brand\BrandStoreSettings;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

// V2: Busts the dashboard /api/me cache helper for brand store settings on
// any write. Without this the dashboard would lag store-settings edits by up
// to 30 minutes (the cache helper's TTL).
//
// Master Pattern 15: also busts the Hydrogen brand-config cache — theme_id and
// default_commission_rate are read into that payload, so changes must flow
// through to Hydrogen within the bust window, not 60s of TTL.
class BrandStoreSettingsObserver
{
    public bool $afterCommit = true;

    public function __construct(
        private readonly SiteCacheService $siteCache,
    ) {}

    public function saved(BrandStoreSettings $settings): void
    {
        $this->bust($settings);
    }

    public function deleted(BrandStoreSettings $settings): void
    {
        $this->bust($settings);
    }

    private function bust(BrandStoreSettings $settings): void
    {
        $professionalId = trim((string) ($settings->professional_id ?? ''));
        if ($professionalId === '') {
            return;
        }

        try {
            $key = CacheKeyGenerator::brandStoreSettings($professionalId);
            // §28.17 CACHE-2 — also delete the SWR `:stale` companion key.
            // Cache::forget alone leaves the stale-while-revalidate copy live;
            // any request hitting CacheLockService::rememberLocked between the
            // primary delete and the next fresh write would serve outdated
            // brand settings (commission rate, theme, payout hold). Matches
            // the CustomerObserver / ProfessionalIntegrationObserver pattern.
            Cache::deleteMultiple([$key, $key.':stale']);
        } catch (\Throwable $e) {
            Log::warning('BrandStoreSettings cache invalidation failed', [
                'professional_id' => $professionalId,
                'message' => $e->getMessage(),
            ]);
        }

        try {
            $this->siteCache->forgetHydrogenBrandConfig($professionalId);
        } catch (\Throwable $e) {
            Log::warning('BrandStoreSettings: Hydrogen brand-config bust failed', [
                'professional_id' => $professionalId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
