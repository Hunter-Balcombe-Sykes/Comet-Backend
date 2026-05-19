<?php

namespace App\Http\Middleware;

use App\Models\Core\Professional\Professional;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates endpoints that are only valid for brand-type professionals.
 * LoadCurrentProfessional sets `professional` on request attributes before
 * this runs; if missing, return 401 (auth pipeline misconfiguration).
 *
 * Resource-level ownership is still enforced by the relevant Policy after
 * this middleware passes — this only gates endpoint eligibility.
 */
class EnsureBrandAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $pro = $request->attributes->get('professional');

        if (! $pro instanceof Professional) {
            return new JsonResponse(['error' => 'Unauthenticated.'], 401);
        }

        // Reads account_type via isBrand() — falls back to professional_type
        // during the dual-write window so legacy rows without account_type
        // still gate correctly.
        if (! $pro->isBrand()) {
            return new JsonResponse(
                ['error' => 'This endpoint is only available for brand accounts.'],
                403,
            );
        }

        return $next($request);
    }
}
