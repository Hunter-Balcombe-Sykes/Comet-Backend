<?php

namespace App\Observers\Core;

use App\Jobs\Cache\WarmPublicSiteCacheJob;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Site\Site;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Log;

// V2: Invalidates site cache, purges Cloudflare edge cache, and dispatches
// KV sync on subdomain change for individual accounts.
class SiteObserver
{
    use LogsWithRequestContext;

    public bool $afterCommit = true;

    public function __construct(
        private readonly SiteCacheService $siteCache,
    ) {}

    public function saved(Site $site): void
    {
        try {
            $this->siteCache->invalidateSite($site);
        } catch (\Throwable $e) {
            Log::warning('Site cache invalidation failed on save', $this->logContext(__METHOD__, [
                'site_id' => $site->id,
                'professional_id' => $site->professional_id,
                'subdomain' => $site->subdomain,
                'message' => $e->getMessage(),
            ]));
        }

        // Cloudflare edge cache purge (§28.7).
        $handle = strtolower(trim((string) ($site->subdomain ?? '')));
        if ($handle !== '') {
            try {
                CloudflareCachePurgeJob::dispatch($handle)->afterCommit();
            } catch (\Throwable $e) {
                Log::warning('CloudflareCachePurgeJob dispatch failed on site save', $this->logContext(__METHOD__, [
                    'site_id' => $site->id,
                    'professional_id' => $site->professional_id,
                    'subdomain' => $handle,
                    'message' => $e->getMessage(),
                ]));
            }
        }

        // Warm cache asynchronously if published.
        if ($site->is_published) {
            try {
                WarmPublicSiteCacheJob::dispatch(strtolower($site->subdomain))->afterCommit();
            } catch (\Throwable $e) {
                Log::warning('WarmPublicSiteCacheJob dispatch failed', $this->logContext(__METHOD__, [
                    'site_id' => $site->id,
                    'professional_id' => $site->professional_id,
                    'subdomain' => $site->subdomain,
                    'message' => $e->getMessage(),
                ]));
            }
        }

        // Sync KV when site is first created or subdomain changes.
        if ($site->wasRecentlyCreated || $site->wasChanged('subdomain')) {
            $professionalId = (string) ($site->professional_id ?? '');

            try {
                SyncSubdomainToKvJob::dispatch($professionalId);
            } catch (\Throwable $e) {
                Log::warning('SiteObserver: KV sync dispatch failed on subdomain change', $this->logContext(__METHOD__, [
                    'site_id' => $site->id,
                    'professional_id' => $professionalId,
                    'message' => $e->getMessage(),
                ]));
            }
        }
    }

    public function deleted(Site $site): void
    {
        try {
            $this->siteCache->invalidateSite($site);
        } catch (\Throwable $e) {
            Log::warning('Site cache invalidation failed on delete', $this->logContext(__METHOD__, [
                'site_id' => $site->id,
                'professional_id' => $site->professional_id,
                'subdomain' => $site->subdomain,
                'message' => $e->getMessage(),
            ]));
        }
    }
}
