<?php

namespace App\Jobs\Cloudflare;

use App\Models\Core\Site\Site;
use App\Services\Cloudflare\CloudflarePurgeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Purges the Cloudflare edge cache for one professional's public profile URL.
// Dispatched on every site mutation that changes payload visible at the edge
// (SiteObserver::saved, account_type transitions, future block/media writes).
//
// Why a dedicated retry policy (not HasCloudflareRetryPolicy):
//   The KV policy targets the KV REST API's failure profile (rare, slow). Cache
//   purge has its own 4xx/5xx semantics — short retries with exponential backoff
//   are enough; a third retry at 60s is wasted because the underlying mutation
//   has long since settled. Keep this distinct from the KV trait.
class CloudflareCachePurgeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15, 60];

    // Short-circuit permanent failures (e.g. revoked token) so failed()/Nightwatch fires after 2 attempts, not 3.
    public int $maxExceptions = 2;

    public int $timeout = 15;

    /**
     * Coalesce window: while a purge for this handle is queued/running, duplicate
     * dispatches from the same request's observer cascade (or a rapid burst of
     * edits) are dropped. Exceeds $timeout so a slow purge can't release the lock
     * early and let a duplicate through.
     */
    public int $uniqueFor = 120;

    public function uniqueId(): string
    {
        // Include the custom domain so a purge that must also bust the custom
        // domain isn't coalesced into a handle-only purge already in flight.
        return strtolower(trim($this->handle)).'|'.strtolower(trim((string) $this->customDomain));
    }

    public function __construct(
        public readonly string $handle,
        public readonly ?string $customDomain = null,
    ) {
        // Isolated from user-facing work so a burst of site mutations can't
        // delay notifications or mail delivery.
        $this->onQueue(config('partna.queues.cloudflare', 'cloudflare'));
    }

    public function handle(CloudflarePurgeService $purge): void
    {
        $h = strtolower(trim($this->handle));
        if ($h === '') {
            return;
        }

        // Resolve the active custom domain from the handle when a dispatcher didn't
        // pass one, so EVERY purge — from any observer/job, present or future — busts
        // the custom-domain edge cache too, not just the .partna.au URLs. (Fix
        // 2026-06-16: Instagram/service/media changes dispatched handle-only, leaving
        // custom domains like tuesdae.co stale until a manual dashboard "purge
        // everything". SiteObserver already passed it; the others didn't.) Only an
        // 'active' custom domain is actually served, so only that is purged.
        $customDomain = $this->customDomain;
        if ($customDomain === null) {
            $site = Site::query()
                ->where('subdomain', $h)
                ->first(['custom_domain', 'custom_domain_status']);
            if ($site && $site->custom_domain_status === 'active' && $site->custom_domain) {
                $customDomain = (string) $site->custom_domain;
            }
        }

        $purge->purgeHandle($h, $customDomain);
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('cloudflare.cache_purge.failed', [
            'handle' => $this->handle,
            'error' => $e->getMessage(),
        ]);
    }
}
