<?php

namespace App\Jobs\Cache;

use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Site\Site;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

// Invalidates public-payload cache keys for every active affiliate linked to a brand.
// Uses chunkById(500) to avoid loading thousands of BrandPartnerLink rows into memory
// in a single query. Replaces the inline O(N) dispatch loop in SiteCacheService::invalidateSite().
class InvalidateBrandAffiliatesCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [5, 15, 30];

    // Worst-case: many affiliates × two Redis DEL calls each. 60s is generous.
    public int $timeout = 60;

    public function __construct(
        public string $brandProfessionalId
    ) {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        BrandPartnerLink::query()
            ->where('brand_professional_id', $this->brandProfessionalId)
            ->chunkById(500, function ($links): void {
                $affiliateIds = $links->pluck('affiliate_professional_id')->filter()->all();
                if (empty($affiliateIds)) {
                    return;
                }

                // One query per chunk to resolve subdomains for all affiliates at once.
                $subdomains = Site::query()
                    ->whereIn('professional_id', $affiliateIds)
                    ->pluck('subdomain')
                    ->filter(fn ($s): bool => is_string($s) && trim($s) !== '')
                    ->map(fn ($s): string => strtolower((string) $s))
                    ->all();

                // Delete ONLY the primary key, not the :stale twin. A brand edit
                // can fan out to hundreds of affiliates at once; keeping :stale
                // lets the SWR fast path serve last-good content while a single
                // background worker rebuilds each key, avoiding a fleet-wide
                // synchronised cold-rebuild stampede.
                $keys = [];
                foreach ($subdomains as $subdomain) {
                    $keys[] = CacheKeyGenerator::publicSitePayload($subdomain);
                }

                if (! empty($keys)) {
                    Cache::deleteMultiple(array_values(array_unique($keys)));
                }
            }, 'id');
    }

    public function failed(\Throwable $e): void
    {
        report($e);
    }
}
