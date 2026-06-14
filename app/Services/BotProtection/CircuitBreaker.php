<?php

namespace App\Services\BotProtection;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

final class CircuitBreaker
{
    private int $failureThreshold;

    private int $windowSeconds;

    private int $cooldownSeconds;

    public function __construct(
        ?int $failureThreshold = null,
        ?int $windowSeconds = null,
        ?int $cooldownSeconds = null,
    ) {
        // Coalesce to config so ops can tune thresholds without a code deploy.
        // Explicit args (e.g. from tests) override config when supplied.
        $this->failureThreshold = $failureThreshold ?? (int) config('partna.bot_protection.circuit_breaker.failure_threshold', 5);
        $this->windowSeconds = $windowSeconds ?? (int) config('partna.bot_protection.circuit_breaker.window_seconds', 60);
        $this->cooldownSeconds = $cooldownSeconds ?? (int) config('partna.bot_protection.circuit_breaker.cooldown_seconds', 300);
    }

    public function isOpen(string $driver): bool
    {
        return (bool) Redis::get($this->openKey($driver));
    }

    public function recordFailure(string $driver): void
    {
        $key = $this->failuresKey($driver);

        // Pipeline INCR + EXPIRE — both commands sent together. Unconditionally
        // refreshing the TTL eliminates the "expire only on count==1" race that
        // could leave the counter without a TTL. Window slides slightly with
        // each failure (acceptable per spec §15.9).
        $results = Redis::pipeline(function ($pipe) use ($key) {
            $pipe->incr($key);
            $pipe->expire($key, $this->windowSeconds);
        });
        $count = (int) ($results[0] ?? 0);

        if ($count >= $this->failureThreshold) {
            $this->trip($driver);
        }
    }

    public function recordSuccess(string $driver): void
    {
        // Clear the failure counter, but leave the open key alone — cooldown TTL
        // owns auto-recovery. Trade-off: flapping during extended outages
        // (open → cooldown → re-trip), accepted per spec §15.2.
        Redis::del($this->failuresKey($driver));
    }

    public function reset(string $driver): void
    {
        Redis::del($this->openKey($driver), $this->failuresKey($driver));
    }

    private function trip(string $driver): void
    {
        $wasOpen = $this->isOpen($driver);
        Redis::setex($this->openKey($driver), $this->cooldownSeconds, (string) now()->timestamp);
        if (! $wasOpen) {
            Log::warning('bot_protection.circuit_open', ['driver' => $driver]);
        }
        // Silent re-trip during sustained outage (no repeat log).
    }

    private function openKey(string $driver): string
    {
        return "bot_protection:cb:{$driver}:open";
    }

    private function failuresKey(string $driver): string
    {
        return "bot_protection:cb:{$driver}:failures";
    }
}
