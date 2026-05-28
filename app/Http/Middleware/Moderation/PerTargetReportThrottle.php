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
        $cap    = config('partna.moderation.reporting.per_target_throttle.requests', 3);
        $window = config('partna.moderation.reporting.per_target_throttle.window_minutes', 60);

        $ipHash = hash('sha256', $request->ip() . '|' . config('app.key'));
        $type   = $request->input('target_type', 'unknown');
        $handle = strtolower((string) $request->input('target_handle', 'unknown'));
        $key    = "moderation:report:ip:{$ipHash}:target:{$type}:{$handle}";

        $count = (int) Redis::incr($key);
        if ($count === 1) {
            Redis::expire($key, $window * 60);
        }

        if ($count > $cap) {
            return response()->json([
                'error'   => 'TARGET_RATE_LIMITED',
                'message' => "Hold on a sec — try again later.",
            ], 429);
        }

        return $next($request);
    }
}
