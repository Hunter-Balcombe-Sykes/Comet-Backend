<?php

namespace App\Jobs\Concerns;

use App\Jobs\Platforms\ThrottledByProvider;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Per-vendor burst gate for the pre-account scraping lane
 * (GeneratePreAccountSiteJob, ApproveEarlyAccessBuildJob). A spike — most
 * concretely a bulk early-access approval fanning one job per lead — must not
 * stampede an external vendor. The vendor differs by source_type:
 *
 *  - 'instagram'       → paid Apify, the SAME account the dashboard-connect IG
 *                        lane uses → rides the shared 'platform-connect' limiter
 *                        keyed 'instagram' (one global Apify budget).
 *  - 'google_business' → official Google Places API (a different vendor) → its
 *                        own 'preaccount-places' limiter.
 *
 * The using job must expose `public readonly string $sourceType` and implement
 * {@see ThrottledByProvider} (this trait supplies its
 * providerRateKey()). The retry-shape properties (tries=0, maxExceptions,
 * failOnTimeout) stay on each job so JobHygienePolicyTest still sees them.
 *
 * @property-read string $sourceType
 */
trait ThrottlesPreAccountScraping
{
    /** Provider rate-budget key — the Apify/Places actor slug the limiter keys on. */
    public function providerRateKey(): string
    {
        return $this->sourceType === 'instagram' ? 'instagram' : 'google-business';
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        // IG shares the paid-Apify 'platform-connect' budget; google_business
        // (official Places API) gets its own vendor lane.
        return [new RateLimited(
            $this->sourceType === 'instagram' ? 'platform-connect' : 'preaccount-places'
        )];
    }

    // Wall-clock deadline for rate-limit releases: a scrape parked behind its
    // vendor's per-minute cap keeps retrying until it slips through or 10 min
    // elapses, then lapses to failed()/return — never an infinite park. Kept ≤ the
    // 600s ShouldBeUnique window so a released job can't outlive its unique lock and
    // let a duplicate bill a second scrape (mirrors InstagramConnectJob's alignment).
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(10);
    }
}
