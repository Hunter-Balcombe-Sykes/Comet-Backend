<?php

namespace App\Services\Platforms;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Log;

// Shared "a platform connection meaningfully changed" side-effect: purge the
// user's public sitepage edge cache. Extracted from IntegrationConnectionObserver
// (FOUND-25 fix) so a controller whose connection row's `payload` never changes
// on a normal mutation (ShopController writes a static MARKER on every save —
// brand/product data lives in child tables now) can trigger the SAME refresh
// explicitly instead of relying on the observer's payload-dirty gate, which
// never fires for those writes.
//
// Design-kit presets no longer read connection data (ProfileDesignPresets is
// read-time, off core.users fields only), so this class purges cache ONLY.
class IntegrationConnectionCacheRefresher
{
    public function refresh(IntegrationConnection $connection): void
    {
        $this->purge($connection);
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
}
