<?php

namespace App\Http\Middleware;

use App\Services\FeatureFlags\FeatureFlagService;
use Closure;
use Illuminate\Http\Request;

// V2: Launch-time feature gate. Resolves via FeatureFlagService (DB overrides +
// rollout % + config fallback) and short-circuits with 503 when the flag is off.
// Fails closed: an unknown flag key = off.
// Apply via route middleware: `->middleware('feature:<flag_key>')`.
//
// NOTE: No professional/brand context is passed to enabled() — per-tenant overrides
// are NOT evaluated here. This middleware is intentionally a global launch gate only.
// Per-tenant override semantics require resolving a Professional model first; use
// FeatureFlagService::enabled($key, $user) directly in controller/service code
// for tenant-aware checks.
class FeatureGate
{
    public function handle(Request $request, Closure $next, string $flag): mixed
    {
        if (! app(FeatureFlagService::class)->enabled($flag)) {
            return response()->json([
                'message' => 'Feature not available',
                'feature' => $flag,
            ], 503);
        }

        return $next($request);
    }
}
