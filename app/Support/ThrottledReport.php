<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Throwable;

// Shared "first-hit-in-cooldown" reporter for low-frequency, high-consequence
// vendor faults (B3, #W1-OBS-1 / #W2-OBS-4 / #W2-OBS-5). The shape is lifted
// verbatim from IdempotencyKey::logFailOpen (app/Http/Middleware/IdempotencyKey.php:171-182),
// which already shipped it twice more (VerifySupabaseJwt::jwksOutage,
// VerifyBotToken::throttledFailReport) — extracted here so a THIRD call site
// doesn't mean a third hand-copy. Those three existing callers are NOT
// retrofitted onto this class this pass (IdempotencyKey is auth-adjacent).
//
// Two invariants a caller may rely on:
//   1. This method never throws into a fail-open path — every branch ends in
//      report(), never in an unhandled exception.
//   2. A dead lock store REPORTS rather than self-mutes (WHK-3): if
//      Cache::lock() itself is unreachable, the catch still calls report($e)
//      unthrottled rather than swallowing the fault because the throttle
//      mechanism broke.
// Cache::lock() resolves the isolated cache_locks connection (Redis DB 4),
// so a throttle window never competes with the queue/cache/session keyspaces.
class ThrottledReport
{
    public static function once(string $key, Throwable $e, int $ttl = 3600): void
    {
        try {
            $lock = Cache::lock($key, $ttl);
            if ($lock->get()) {
                report($e);
            }
        } catch (Throwable) {
            report($e);
        }
    }
}
