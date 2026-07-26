<?php

namespace App\Services\V5\Scraping\Budget;

use Illuminate\Support\Facades\Cache;

// V5 ApifyBudget — per-actor daily caps + global daily cap.
// Mirrors the old ApifyBudget but scoped to V5 platform system.
class ApifyBudget
{
    private string $cachePrefix = 'v5_apify_budget_';

    public function __construct(
        private readonly int $globalDailyCap = 1000,
        private readonly array $actorDailyCaps = [],
    ) {}

    public function isExhausted(string $actorName): bool
    {
        // Check global cap
        $globalUsed = (int) Cache::get($this->cachePrefix.'global', 0);
        if ($globalUsed >= $this->globalDailyCap) return true;

        // Check per-actor cap
        $cap = $this->actorDailyCaps[$actorName] ?? $this->globalDailyCap;
        $actorUsed = (int) Cache::get($this->cachePrefix.$actorName, 0);
        if ($cap > 0 && $actorUsed >= $cap) return true;

        return false;
    }

    public function record(string $actorName, int $itemCount): void
    {
        $ttl = now()->endOfDay()->diffInSeconds(now());

        Cache::increment($this->cachePrefix.'global', $itemCount);
        Cache::increment($this->cachePrefix.$actorName, $itemCount);

        // Set TTL on first increment
        if (! Cache::has($this->cachePrefix.'global')) {
            Cache::put($this->cachePrefix.'global', $itemCount, $ttl);
        }
    }

    public function remaining(string $actorName): int
    {
        $cap = $this->actorDailyCaps[$actorName] ?? $this->globalDailyCap;
        $used = (int) Cache::get($this->cachePrefix.$actorName, 0);
        return max(0, $cap - $used);
    }
}
