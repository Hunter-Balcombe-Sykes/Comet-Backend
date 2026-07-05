<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// V2: Cache-Control headers — public GET endpoints get 15min cache; authenticated requests get no-store. Varies on X-Site-Subdomain for multi-tenant safety.
class AddPublicCacheHeaders
{
    /**
     * Public GET paths that are safe to cache at the CDN/proxy layer.
     * Anything NOT in this list will not receive public Cache-Control headers.
     * This is an allowlist — add new public-cacheable paths explicitly.
     */
    public const CACHEABLE_PATH_PREFIXES = [
        'api/public/site-by-slug',
    ];

    /**
     * Paths (or path prefixes) that must never be publicly cached because they
     * carry per-user tokens, affect subscription state, or expose invite details.
     * These receive Cache-Control: no-store even if they are otherwise GET/200.
     */
    private const NO_STORE_PATH_PREFIXES = [
        'api/public/unsubscribe/',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Never cache authenticated API responses.
        if ($request->headers->has('Authorization')) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $this->mergeVary($response, ['Authorization', 'Cookie', 'Accept-Encoding']);

            return $response;
        }

        $path = $request->path();

        // Tokenized / sensitive GET endpoints must never be publicly cached.
        foreach (self::NO_STORE_PATH_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
                $response->headers->set('Pragma', 'no-cache');

                return $response;
            }
        }

        // Only cache successful GET requests to explicitly allow-listed public paths.
        if ($request->isMethod('GET') && $response->isSuccessful()) {
            foreach (self::CACHEABLE_PATH_PREFIXES as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    // CFG-3: CDN/edge TTL is config-driven (default 15 min) — tunable without a redeploy.
                    $maxAge = (int) config('partna.cache.public_max_age', 900);
                    $response->headers->set('Cache-Control', "public, max-age={$maxAge}, s-maxage={$maxAge}");
                    // All *-by-slug routes resolve the tenant from the X-Site-Subdomain header,
                    // so CDN/proxies must vary their cache on it to avoid cross-tenant poisoning.
                    $this->mergeVary($response, ['X-Site-Subdomain', 'Accept-Encoding']);
                    if (! $response->headers->has('X-Cache-Status')) {
                        $response->headers->set('X-Cache-Status', 'MISS');
                    }

                    return $response;
                }
            }
        }

        return $response;
    }

    private function mergeVary(Response $response, array $tokens): void
    {
        $existing = (string) $response->headers->get('Vary', '');
        $varyMap = [];

        if ($existing !== '') {
            foreach (explode(',', $existing) as $token) {
                $trimmed = trim($token);
                if ($trimmed !== '') {
                    $varyMap[strtolower($trimmed)] = $trimmed;
                }
            }
        }

        foreach ($tokens as $token) {
            $trimmed = trim($token);
            if ($trimmed !== '') {
                $varyMap[strtolower($trimmed)] = $trimmed;
            }
        }

        if ($varyMap !== []) {
            $response->headers->set('Vary', implode(', ', array_values($varyMap)));
        }
    }
}
