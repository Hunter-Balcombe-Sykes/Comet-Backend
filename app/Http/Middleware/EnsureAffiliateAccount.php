<?php

namespace App\Http\Middleware;

use App\Models\Core\Professional\Professional;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inverse of EnsureBrandAccount — gates endpoints that are only valid for
 * affiliates (non-brand professionals selecting brand products, claiming
 * invites, etc.). Resource ownership still enforced by the Policy layer.
 */
class EnsureAffiliateAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $pro = $request->attributes->get('professional');

        if (! $pro instanceof Professional) {
            return new JsonResponse(['error' => 'Unauthenticated.'], 401);
        }

        // Reads account_type via isBrand() — falls back to professional_type
        // during the dual-write window. Partner + individual both pass.
        if ($pro->isBrand()) {
            return new JsonResponse(
                ['error' => 'Brand accounts cannot use this endpoint.'],
                403,
            );
        }

        return $next($request);
    }
}
