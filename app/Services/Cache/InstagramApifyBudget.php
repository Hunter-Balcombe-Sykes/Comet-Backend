<?php

namespace App\Services\Cache;

use Illuminate\Support\Facades\Cache;

// The GLOBAL daily Apify scrape budget for Instagram — a hard cost ceiling
// shared across every entry point that can trigger a paid Instagram scrape (the
// manual connect + the Google Business auto-sync), so they draw on one cap.
// Lives in the cache layer (GS-1) so the raw Cache::* calls stay canonical;
// atomic Cache::add + increment means two concurrent claims can't both slip past
// the cap boundary the way a get + put read-modify-write could.
class InstagramApifyBudget
{
    /**
     * Try to claim one scrape slot for today. Returns false when the global
     * daily cap is already reached — the caller skips the scrape (429 on the
     * manual path; silently skip on the auto-sync path).
     */
    public function tryClaim(): bool
    {
        $cap = (int) config('partna.limits.platforms.instagram.apify_daily_cap', 200);
        $key = CacheKeyGenerator::instagramDailyLimit(now()->format('Y-m-d'));

        // Initialise once (no-op if present, preserving its TTL), then INCR. The
        // post-increment value is the Nth claim, so reject when it exceeds the
        // cap. The TTL is intentionally NOT jittered: a hard cost cap whose
        // date-keyed counter must survive to the calendar day's end.
        Cache::add($key, 0, now()->addDay());
        if (Cache::increment($key) > $cap) {
            Cache::decrement($key);   // over capacity — release the slot we claimed

            return false;
        }

        return true;
    }
}
