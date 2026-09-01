<?php

namespace App\Services\Cache;

// Item 8 G2: the ScrapeCreators twin of ApifyBudget — same two-cap shape
// (per-source daily + global daily), same DailyCounterClaim mechanics, its
// own counters and config keys. Deliberately a separate class rather than a
// parameterised ApifyBudget: the two vendors' caps must be tunable apart
// (a vendor incident should throttle ONE lane, and the fallback then leans
// on the other's untouched budget), and their claim sites never overlap.
//
// A denied claim is not an error — the caller skips the vendor lane and the
// Apify fallback proceeds under ITS budget, exactly the primary-with-
// fallback contract Item 8 promises.
class ScrapeCreatorsBudget
{
    public function tryClaim(string $source): bool
    {
        $sourceCap = (int) config("partna.limits.scrapecreators.sources.{$source}", 0);
        $globalCap = (int) config('partna.limits.scrapecreators.global_daily_cap');
        $date = now()->format('Y-m-d');

        $globalKey = CacheKeyGenerator::scrapeCreatorsGlobalDailyLimit($date);
        $sourceKey = CacheKeyGenerator::scrapeCreatorsSourceDailyLimit($source, $date);

        if (! DailyCounterClaim::claim($globalKey, $globalCap)) {
            return false;
        }

        if (! DailyCounterClaim::claim($sourceKey, $sourceCap)) {
            DailyCounterClaim::release($globalKey);

            return false;
        }

        return true;
    }

    /** Mirror of ApifyBudget::release() — hand back a claimed-then-unspent slot. */
    public function release(string $source): void
    {
        $date = now()->format('Y-m-d');
        DailyCounterClaim::release(CacheKeyGenerator::scrapeCreatorsGlobalDailyLimit($date));
        DailyCounterClaim::release(CacheKeyGenerator::scrapeCreatorsSourceDailyLimit($source, $date));
    }
}
