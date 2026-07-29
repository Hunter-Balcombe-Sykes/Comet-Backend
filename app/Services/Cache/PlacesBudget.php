<?php

namespace App\Services\Cache;

use Illuminate\Support\Facades\Cache;
use Throwable;

// RV-6: the Google Places spend ceiling. Places was the only paid API in the
// system with no ceiling in code — Google's own "budgets" are alerts, not
// caps. Claims through the shared DailyCounterClaim mechanism, like
// ApifyBudget/AiSpendBudget/ProbeBudget — one atomic server-side step per
// counter, so two concurrent claims can't both slip past a boundary and no
// counter is left without a TTL — PLUS a per-user dimension the Apify/AI
// budgets don't have: one account cannot drain the platform's daily allowance.
//
// Every BILLED Places request must claim here — one per HTTP call, never once
// per logical fetchPlaceDetails() (R4-RES-1's lesson: a claim taken once per
// logical operation undercounts by exactly the retry/fan-out factor, and here
// that factor is up to 16×). Both billed call sites live inside
// GoogleBusinessService; this class holds no opinion about where its callers are.
class PlacesBudget
{
    /**
     * Try to claim one $sku ('details'|'photos') slot for $userId today.
     * Three counters move atomically: global (all SKUs, all users), per-SKU,
     * and per-user. Over any ceiling releases all three and denies — the user
     * counter is checked first since it's the most specific and actionable
     * reason to surface to the caller.
     *
     * Fails CLOSED: a cache-layer Throwable during the initial add/increment
     * returns Unavailable rather than letting a spend gate silently pass during
     * an outage. The rollback decrements below are guarded the same way — a
     * Throwable there is reported but never escapes claim() uncaught, so the
     * verdict already reached (UserCapReached/PlatformCapReached) still returns
     * instead of surfacing as an unhandled exception the caller doesn't catch.
     */
    public function claim(string $sku, string $userId): PlacesClaim
    {
        $date = now()->format('Y-m-d');

        $globalKey = CacheKeyGenerator::placesGlobalDailyLimit($date);
        $skuKey = CacheKeyGenerator::placesSkuDailyLimit($sku, $date);
        $userKey = CacheKeyGenerator::placesUserDailyLimit($userId, $date);

        $globalCap = (int) config('partna.limits.places.global_daily_cap');
        $skuCap = (int) config("partna.limits.places.skus.{$sku}", 0);
        $userCap = (int) config('partna.limits.places.per_user_daily_cap');

        // Claimed one dimension at a time, USER FIRST, so a denial can name the
        // most specific and actionable reason — the same precedence the former
        // increment-all-three-then-check form gave by testing $userCount first.
        // Each claim only ever releases counters this call itself took.
        try {
            if (! DailyCounterClaim::claim($userKey, $userCap)) {
                return PlacesClaim::UserCapReached;
            }

            if (! DailyCounterClaim::claim($globalKey, $globalCap)) {
                $this->rollback($userKey);

                return PlacesClaim::PlatformCapReached;
            }

            if (! DailyCounterClaim::claim($skuKey, $skuCap)) {
                $this->rollback($userKey, $globalKey);

                return PlacesClaim::PlatformCapReached;
            }
        } catch (Throwable $e) {
            report($e);

            return PlacesClaim::Unavailable;
        }

        return PlacesClaim::Granted;
    }

    /**
     * Release the counters this call already took. Caught and reported rather
     * than left to propagate: a Throwable here is an infrastructure failure
     * DURING a rollback, not a fresh budget decision — the caller must still
     * get back the verdict claim() already reached, not an uncaught exception
     * that turns a 429/degrade into a 500 (see class docblock).
     */
    private function rollback(string ...$keys): void
    {
        try {
            foreach ($keys as $key) {
                DailyCounterClaim::release($key);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Advisory remaining headroom for $sku (and, if given, $userId) today —
     * min across whichever dimensions apply, floored at 0. Racy vs concurrent
     * claims, same as ApifyBudget::remaining — for coarse "should I keep
     * dispatching?" decisions, not a hard gate. claim() is the sole authority.
     */
    public function remaining(string $sku, ?string $userId = null): int
    {
        $date = now()->format('Y-m-d');

        $globalCap = (int) config('partna.limits.places.global_daily_cap');
        $skuCap = (int) config("partna.limits.places.skus.{$sku}", 0);

        $global = (int) Cache::get(CacheKeyGenerator::placesGlobalDailyLimit($date), 0);
        $skuCount = (int) Cache::get(CacheKeyGenerator::placesSkuDailyLimit($sku, $date), 0);

        $remaining = min($globalCap - $global, $skuCap - $skuCount);

        if ($userId !== null) {
            $userCap = (int) config('partna.limits.places.per_user_daily_cap');
            $userCount = (int) Cache::get(CacheKeyGenerator::placesUserDailyLimit($userId, $date), 0);
            $remaining = min($remaining, $userCap - $userCount);
        }

        return max(0, $remaining);
    }
}
