<?php

namespace App\Services\Cache;

use Illuminate\Support\Facades\Cache;

// The shared daily Apify scrape budget (SCALE-2). Every paid entry point —
// Instagram connect, Google Business enrichment, menu scrapes — claims a slot
// here before spending, so a runaway in one integration can't exhaust the paid
// Apify account and take the others down with it.
//
// TWO caps enforced atomically: a per-actor daily cap AND a global daily cap
// across all actors. Lives in the cache layer (GS-1) so raw Cache::* stays
// canonical; atomic Cache::add + increment (never get+put) means two concurrent
// claims can't both slip past the boundary. Generalises the former
// InstagramApifyBudget.
class ApifyBudget
{
    /**
     * Try to claim one scrape slot for $actor today. Returns false when EITHER the
     * per-actor or the global daily cap is already reached — the caller skips the
     * scrape (429 on the Instagram manual path; null/[] elsewhere so the prior
     * payload is kept).
     */
    public function tryClaim(string $actor): bool
    {
        $actorCap = (int) config("partna.limits.apify.actors.{$actor}", 0);
        $globalCap = (int) config('partna.limits.apify.global_daily_cap');
        $date = now()->format('Y-m-d');
        $expiry = now()->addDay(); // date-keyed key rotates at midnight; TTL just outlives the day

        $globalKey = CacheKeyGenerator::apifyGlobalDailyLimit($date);
        $actorKey = CacheKeyGenerator::apifyActorDailyLimit($actor, $date);

        Cache::add($globalKey, 0, $expiry);
        Cache::add($actorKey, 0, $expiry);

        $global = Cache::increment($globalKey);
        $actorCount = Cache::increment($actorKey);

        // Over EITHER ceiling → release both counters and reject.
        if ($global > $globalCap || $actorCount > $actorCap) {
            Cache::decrement($globalKey);
            Cache::decrement($actorKey);

            return false;
        }

        return true;
    }

    /**
     * Advisory remaining headroom for $actor today = min(actor remaining, global
     * remaining), floored at 0. Racy vs concurrent claims — for coarse "should I
     * keep dispatching?" decisions (SCALE-4), not as a hard gate. tryClaim() is the
     * only authority on whether a scrape may proceed.
     */
    public function remaining(string $actor): int
    {
        $actorCap = (int) config("partna.limits.apify.actors.{$actor}", 0);
        $globalCap = (int) config('partna.limits.apify.global_daily_cap');
        $date = now()->format('Y-m-d');

        $global = (int) Cache::get(CacheKeyGenerator::apifyGlobalDailyLimit($date), 0);
        $actorCount = (int) Cache::get(CacheKeyGenerator::apifyActorDailyLimit($actor, $date), 0);

        return max(0, min($actorCap - $actorCount, $globalCap - $global));
    }
}
