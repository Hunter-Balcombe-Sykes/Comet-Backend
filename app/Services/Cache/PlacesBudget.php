<?php

namespace App\Services\Cache;

use Illuminate\Support\Facades\Cache;
use Throwable;

// RV-6: the Google Places spend ceiling. Places was the only paid API in the
// system with no ceiling in code — Google's own "budgets" are alerts, not
// caps. Mirrors ApifyBudget/AiSpendBudget's atomic Cache::add + increment
// pattern (never get+put, so two concurrent claims can't both slip past the
// boundary), PLUS a per-user dimension neither of those has: one account
// cannot drain the platform's daily allowance.
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
        $expiry = now()->addDay(); // date-keyed key rotates at midnight; TTL just outlives the day

        $globalKey = CacheKeyGenerator::placesGlobalDailyLimit($date);
        $skuKey = CacheKeyGenerator::placesSkuDailyLimit($sku, $date);
        $userKey = CacheKeyGenerator::placesUserDailyLimit($userId, $date);

        try {
            Cache::add($globalKey, 0, $expiry);
            Cache::add($skuKey, 0, $expiry);
            Cache::add($userKey, 0, $expiry);

            $global = Cache::increment($globalKey);
            $skuCount = Cache::increment($skuKey);
            $userCount = Cache::increment($userKey);
        } catch (Throwable $e) {
            report($e);

            return PlacesClaim::Unavailable;
        }

        $globalCap = (int) config('partna.limits.places.global_daily_cap');
        $skuCap = (int) config("partna.limits.places.skus.{$sku}", 0);
        $userCap = (int) config('partna.limits.places.per_user_daily_cap');

        if ($userCount > $userCap) {
            $this->rollback($globalKey, $skuKey, $userKey);

            return PlacesClaim::UserCapReached;
        }

        if ($global > $globalCap || $skuCount > $skuCap) {
            $this->rollback($globalKey, $skuKey, $userKey);

            return PlacesClaim::PlatformCapReached;
        }

        return PlacesClaim::Granted;
    }

    /**
     * Release a denied claim's three counters. Caught and reported rather than
     * left to propagate: a Throwable here is an infrastructure failure DURING
     * a rollback, not a fresh budget decision — the caller must still get back
     * the verdict claim() already reached, not an uncaught exception that turns
     * a 429/degrade into a 500 (see class docblock).
     */
    private function rollback(string $globalKey, string $skuKey, string $userKey): void
    {
        try {
            Cache::decrement($globalKey);
            Cache::decrement($skuKey);
            Cache::decrement($userKey);
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
