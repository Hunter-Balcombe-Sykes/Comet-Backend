<?php

namespace App\Services\V5\Scraping\Budget;

// V5 FetchBudget — wall-clock bounding for time-intensive scrapes.
// Prevents a single scrape from running indefinitely.
class FetchBudget
{
    private float $startedAt;
    private int $tickCount = 0;

    public function __construct(
        private readonly int $maxSeconds = 30,
        private readonly int $maxTicks = 50,
    ) {
        $this->startedAt = microtime(true);
    }

    public function isExhausted(): bool
    {
        if ($this->remainingSeconds() <= 0) return true;
        if ($this->tickCount >= $this->maxTicks) return true;
        return false;
    }

    public function tick(): void
    {
        $this->tickCount++;
    }

    public function remainingSeconds(): float
    {
        return max(0, $this->maxSeconds - (microtime(true) - $this->startedAt));
    }

    public function tickCount(): int
    {
        return $this->tickCount;
    }
}
