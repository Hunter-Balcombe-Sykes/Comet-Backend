<?php

namespace App\Services\Cache;

// The shared daily AI menu-structuring spend budget (Mistral OCR +
// DeepSeek structuring, via MenuAiExtractor). Same two-cap shape as
// ApifyBudget (per-vendor-call daily cap + a global daily cap) over the shared
// DailyCounterClaim mechanism — kept as its own class/namespace rather than
// folded into ApifyBudget since these are a different vendor family entirely,
// not because the mechanism differs. Added 2026-07-23: this spend previously
// had no ceiling at all across its three callers (PDF scan, Google-photo scan,
// HTML scan).
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

        $globalKey = CacheKeyGenerator::aiSpendGlobalDailyLimit($date);
        $actorKey = CacheKeyGenerator::aiSpendActorDailyLimit($actor, $date);

        if (! DailyCounterClaim::claim($globalKey, $globalCap)) {
            return false;
        }

        if (! DailyCounterClaim::claim($actorKey, $actorCap)) {
            DailyCounterClaim::release($globalKey);

            return false;
        }

        return true;
    }
}
