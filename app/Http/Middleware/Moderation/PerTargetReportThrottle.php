<?php

namespace App\Http\Middleware\Moderation;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-(IP, target) rate limit on the public report endpoint.
 *
 * Sliding-window counter in Redis keyed on
 *   moderation:report:ip:{ip_hash}:target:{type}:{handle}
 * TTL matches config('partna.moderation.reporting.per_target_throttle.window_minutes').
 * Cap: config('partna.moderation.reporting.per_target_throttle.requests').
 *
 * Returns 429 with retry hint if exceeded. Layered ON TOP of the framework's
 * IP throttle (applied via route middleware 'throttle:partna.moderation.report').
 */
class PerTargetReportThrottle
{
    public function handle(Request $request, Closure $next): Response
    {
        $cap = config('partna.moderation.reporting.per_target_throttle.requests', 3);
        $window = config('partna.moderation.reporting.per_target_throttle.window_minutes', 60);

        // hash_hmac (not plain hash) so the IP hash matches the analytics/HashesClientData scheme — same IP, same hash, correlatable (SEC-14).
        $ipHash = hash_hmac('sha256', (string) $request->ip(), config('app.key'));
        $type = $request->input('target_type', 'unknown');
        $handle = strtolower((string) $request->input('target_handle', 'unknown'));
        $key = "moderation:report:ip:{$ipHash}:target:{$type}:{$handle}";

        // Atomic INCR + first-hit EXPIRE via a single Lua round-trip — a crash
        // between separate INCR/EXPIRE calls could otherwise leave the key
        // permanently un-expiring (LIFE-1).
        //
        // `app`, not the bare facade default — request path, no blocking
        // command, so it takes the 3.0s bound instead of `default`'s 15.0s
        // (reserved for queue workers' BLPOP). See drill 03 (2026-08-05).
        $count = (int) Redis::connection('app')->eval(
            "local c = redis.call('INCR', KEYS[1]) if c == 1 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end return c",
            1,
            $key,
            $window * 60
        );

        if ($count > $cap) {
            return response()->json([
                'error' => 'TARGET_RATE_LIMITED',
                'message' => 'Hold on a sec — try again later.',
            ], 429);
        }

        return $next($request);
    }
}
