<?php

// app/Services/Analytics/AnalyticsDedupGuard.php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

// Redis-backed fixed-window dedup via atomic SETNX (Cache::add) — same family as
// LogLeadRateLimits. The key stores the minted UUID as its value so a duplicate can
// echo back the ORIGINAL event id (preserving today's "return existing id" contract).
//
// Fixed window (resets every TTL), not the SQL path's sliding window — a genuine
// repeat is re-registered once per TTL rather than suppressed indefinitely.
//
// Fail-open: any cache fault is swallowed and treated as novel, so a Redis blip
// degrades to a possible duplicate rather than a dropped beacon or a 500.
class AnalyticsDedupGuard
{
    /** @return array{novel: bool, id: string} */
    public function claim(string $key, string $mintedUuid, int $ttlSeconds): array
    {
        try {
            if (Cache::add($key, $mintedUuid, $ttlSeconds)) {
                return ['novel' => true, 'id' => $mintedUuid];
            }

            $original = Cache::get($key);

            // TOCTOU: a 3s key can expire between the failed add() and get(). Never
            // echo null into the response body — fall back to the minted uuid.
            return ['novel' => false, 'id' => is_string($original) ? $original : $mintedUuid];
        } catch (Throwable $e) {
            Log::warning('analytics.dedup_fault', ['key' => $key, 'error' => $e->getMessage()]);

            return ['novel' => true, 'id' => $mintedUuid];
        }
    }
}
