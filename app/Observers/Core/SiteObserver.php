<?php

namespace App\Observers\Core;

use App\Jobs\Cache\WarmPublicSiteCacheJob;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Cloudflare\ProvisionBrandDnsJob;
use App\Jobs\Cloudflare\RetireBrandDnsJob;
use App\Jobs\Cloudflare\SyncSubdomainToKvJob;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\Cache\SiteCacheService;
use Illuminate\Support\Facades\Log;

// V2: Invalidates site cache and dispatches cache warm job on publish.
// Also syncs Cloudflare KV routing table on subdomain change, cascading to
// all connected affiliates when this site belongs to a brand.
class SiteObserver
{
    use LogsWithRequestContext;

    public bool $afterCommit = true;

    public function __construct(
        private readonly SiteCacheService $siteCache,
    ) {}

    public function updating(Site $site): void
    {
        if ($site->isDirty('subdomain')) {
            // Stash on the model so saved() can dispatch retirement of the old CNAME.
            $site->_oldSubdomainPendingRetire = $site->getOriginal('subdomain');
        }
    }

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

        // Cloudflare edge cache purge (§28.7). Fires on every save (not only on
        // subdomain change) because any site.* mutation can change the rendered
        // payload an Astro Worker reads for individual public profiles.
        // After-commit dispatch + observer's `$afterCommit = true` ensures we
        // never purge before the DB row is visible to the next read.
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

        // Warm cache asynchronously if published
        if ($site->is_published) {
            try {
                WarmPublicSiteCacheJob::dispatch(strtolower($site->subdomain))->afterCommit();
            } catch (\Throwable $e) {
                // Never fail the write path because cache warming failed.
                Log::warning('WarmPublicSiteCacheJob dispatch failed', $this->logContext(__METHOD__, [
                    'site_id' => $site->id,
                    'professional_id' => $site->professional_id,
                    'subdomain' => $site->subdomain,
                    'message' => $e->getMessage(),
                ]));
            }
        }

        // Sync KV when site is first created or subdomain changes.
        // Also cascade to all affiliates when this site belongs to a brand,
        // since their site_url (brand.partna.au/affiliate) depends on the brand subdomain.
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

            try {
                $this->cascadeAffiliateKvSync($professionalId);
            } catch (\Throwable $e) {
                Log::warning('SiteObserver: KV affiliate cascade failed', $this->logContext(__METHOD__, [
                    'site_id' => $site->id,
                    'professional_id' => $professionalId,
                    'message' => $e->getMessage(),
                ]));
            }

            // Provision DNS for brand sites only. Affiliates use the wildcard.
            $pro = Professional::query()->find($professionalId);
            if ($pro?->isBrand()) {
                try {
                    ProvisionBrandDnsJob::dispatch($professionalId);
                } catch (\Throwable $e) {
                    Log::warning('SiteObserver: ProvisionBrandDnsJob dispatch failed', $this->logContext(__METHOD__, [
                        'site_id' => $site->id,
                        'professional_id' => $site->professional_id,
                        'message' => $e->getMessage(),
                    ]));
                }
            }

            // If the subdomain changed, retire the old CNAME (only meaningful for brands;
            // for affiliates it's a no-op since there's no per-affiliate CNAME).
            if (isset($site->_oldSubdomainPendingRetire) && $pro?->isBrand()) {
                try {
                    RetireBrandDnsJob::dispatch((string) $site->_oldSubdomainPendingRetire);
                } catch (\Throwable $e) {
                    Log::warning('SiteObserver: RetireBrandDnsJob dispatch failed', $this->logContext(__METHOD__, [
                        'site_id' => $site->id,
                        'professional_id' => $site->professional_id,
                        'old_subdomain' => $site->_oldSubdomainPendingRetire,
                        'message' => $e->getMessage(),
                    ]));
                }
                unset($site->_oldSubdomainPendingRetire);
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

    // Dispatch KV sync for every affiliate connected to this brand professional.
    // No-op if the professional has no affiliate connections (i.e., they're not a brand).
    private function cascadeAffiliateKvSync(string $brandProfessionalId): void
    {
        if ($brandProfessionalId === '') {
            return;
        }

        BrandPartnerLink::query()
            ->where('brand_professional_id', $brandProfessionalId)
            ->pluck('affiliate_professional_id')
            ->each(function (string $affiliateId): void {
                SyncSubdomainToKvJob::dispatch($affiliateId);
            });
    }
}
