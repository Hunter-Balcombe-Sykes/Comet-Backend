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
                'user_id' => $site->user_id,
                'subdomain' => $site->subdomain,
                'message' => $e->getMessage(),
            ]));
        }

        // Cloudflare edge cache purge (§28.7). Pass the custom domain (when active)
        // so its host-keyed edge cache is busted too — not just <handle>.partna.au.
        $handle = strtolower(trim((string) ($site->subdomain ?? '')));
        if ($handle !== '') {
            $customDomain = $site->custom_domain_status === 'active' && $site->custom_domain
                ? (string) $site->custom_domain
                : null;
            try {
                CloudflareCachePurgeJob::dispatch($handle, $customDomain)->afterCommit();
            } catch (\Throwable $e) {
                Log::warning('CloudflareCachePurgeJob dispatch failed on site save', $this->logContext(__METHOD__, [
                    'site_id' => $site->id,
                    'user_id' => $site->user_id,
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
                    'user_id' => $site->user_id,
                    'subdomain' => $site->subdomain,
                    'message' => $e->getMessage(),
                ]));
            }
        }

        // Sync KV when site is first created or subdomain changes.
        if ($site->wasRecentlyCreated || $site->wasChanged('subdomain')) {
            $userId = (string) ($site->user_id ?? '');

            try {
                SyncSubdomainToKvJob::dispatch($userId)->afterCommit();
            } catch (\Throwable $e) {
                Log::warning('SiteObserver: KV sync dispatch failed on subdomain change', $this->logContext(__METHOD__, [
                    'site_id' => $site->id,
                    'user_id' => $userId,
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
                'user_id' => $site->user_id,
                'subdomain' => $site->subdomain,
                'message' => $e->getMessage(),
            ]));
        }

        // Purge the Cloudflare edge cache so a deleted site isn't served from
        // the edge for up to 24h (primary) / 7d (stale shadow) after deletion.
        // (Site is hard-deleted — no SoftDeletes — so `deleted` fires on the real
        // row removal; account-purge cascades via DB FK and bypasses this observer,
        // invalidating the edge separately.)
        $handle = strtolower(trim((string) ($site->subdomain ?? '')));
        if ($handle !== '') {
            $customDomain = $site->custom_domain_status === 'active' && $site->custom_domain
                ? (string) $site->custom_domain
                : null;
            try {
                CloudflareCachePurgeJob::dispatch($handle, $customDomain)->afterCommit();
            } catch (\Throwable $e) {
                Log::warning('CloudflareCachePurgeJob dispatch failed on site delete', $this->logContext(__METHOD__, [
                    'site_id' => $site->id,
                    'user_id' => $site->user_id,
                    'subdomain' => $handle,
                    'message' => $e->getMessage(),
                ]));
            }
        }
    }
}
