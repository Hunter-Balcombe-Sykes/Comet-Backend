<?php

namespace App\Services\Cache;

use Illuminate\Support\Facades\Cache;

// The shared daily AI menu-structuring spend budget (Mistral OCR +
// DeepSeek structuring, via MenuAiExtractor). Mirrors ApifyBudget's exact
// two-cap atomic pattern (per-vendor-call daily cap + a global daily cap) —
// kept as its own class/namespace rather than folded into ApifyBudget since
// these are a different vendor family entirely, not because the mechanism
// differs. Added 2026-07-23: this spend previously had no ceiling at all
// across its three callers (PDF scan, Google-photo scan, HTML scan).
class AiSpendBudget
{
    /**
     * Try to claim one AI call slot for $actor ('mistral_ocr'|'deepseek_structure')
     * today. Returns false when EITHER the per-actor or the global daily cap is
     * already reached — the caller skips the call (MenuAiExtractor logs and
     * returns null, same "not configured"-shaped early exit its callers already
     * handle).
     */
    public function tryClaim(string $actor): bool
    {
        $actorCap = (int) config("partna.limits.ai_spend.actors.{$actor}", 0);
        $globalCap = (int) config('partna.limits.ai_spend.global_daily_cap');
        $date = now()->format('Y-m-d');
        $expiry = now()->addDay();

        $globalKey = CacheKeyGenerator::aiSpendGlobalDailyLimit($date);
        $actorKey = CacheKeyGenerator::aiSpendActorDailyLimit($actor, $date);

        Cache::add($globalKey, 0, $expiry);
        Cache::add($actorKey, 0, $expiry);

        $global = Cache::increment($globalKey);
        $actorCount = Cache::increment($actorKey);

        if ($global > $globalCap || $actorCount > $actorCap) {
            Cache::decrement($globalKey);
            Cache::decrement($actorKey);

            return false;
        }

        return true;
    }
}
