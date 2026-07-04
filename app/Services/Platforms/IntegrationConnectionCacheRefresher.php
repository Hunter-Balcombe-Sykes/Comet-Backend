<?php

namespace App\Services\Platforms;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Jobs\Design\AnalyzeConnectionWebsitesJob;
use App\Jobs\Design\ResolveDesignPresetsJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Design\Presets\DesignFactorRegistry;
use App\Services\Design\Presets\Factors\OutsideWebsitesFactor;
use Illuminate\Support\Facades\Log;

// Shared "a platform connection meaningfully changed" side-effect: purge the
// user's public sitepage edge cache + re-resolve design-kit preset
// contributions. Extracted from IntegrationConnectionObserver (FOUND-25 fix)
// so a controller whose connection row's `payload` never changes on a normal
// mutation (ShopController writes a static MARKER on every save — brand/
// product data lives in child tables now) can trigger the SAME refresh
// explicitly instead of relying on the observer's payload-dirty gate, which
// never fires for those writes.
class IntegrationConnectionCacheRefresher
{
    public function refresh(IntegrationConnection $connection): void
    {
        $this->purge($connection);
        $this->resolveDesignPresets($connection);
    }

    private function purge(IntegrationConnection $connection): void
    {
        try {
            $subdomain = User::query()
                ->with('site')
                ->find($connection->user_id)
                ?->site?->subdomain;

            if ($subdomain) {
                CloudflareCachePurgeJob::dispatch($subdomain);
            }
        } catch (\Throwable $e) {
            // Surface to Nightwatch — without report() a persistent failure (Redis
            // outage, broken user→site join) silently serves stale edge cache with
            // only a breadcrumb log. Do not re-throw: a cache refresh must never
            // crash the mutation that triggered it.
            report($e);
            Log::warning('IntegrationConnectionCacheRefresher purge failed', [
                'platform_connection_id' => $connection->id,
                'user_id' => $connection->user_id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    // Queue a per-user rebuild of design-kit preset contributions. Swallow +
    // report on failure: this must never crash the triggering write.
    private function resolveDesignPresets(IntegrationConnection $connection): void
    {
        try {
            $platform = (string) $connection->platform;

            // Only platforms that feed the preset layer trigger work: ones with
            // a registered connection factor, plus the outside-website sources
            // (custom links / shop brands) feeding the aggregate site factor.
            $hasFactor = app(DesignFactorRegistry::class)->factorsFor($platform) !== [];
            $isWebsiteSource = in_array($platform, OutsideWebsitesFactor::SOURCE_PLATFORMS, true);
            if (! $connection->user_id || (! $hasFactor && ! $isWebsiteSource)) {
                return;
            }

            // Outside websites lacking a style analysis get one queued; the
            // analyses job's write re-triggers a refresh with analyses present,
            // so this converges (no dispatch loop).
            if ($isWebsiteSource && ! $connection->trashed()
                && AnalyzeConnectionWebsitesJob::connectionNeedsAnalyses($connection)) {
                AnalyzeConnectionWebsitesJob::dispatch((string) $connection->user_id);
            }

            ResolveDesignPresetsJob::dispatch((string) $connection->user_id);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('IntegrationConnectionCacheRefresher preset resolve dispatch failed', [
                'platform_connection_id' => $connection->id,
                'user_id' => $connection->user_id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
