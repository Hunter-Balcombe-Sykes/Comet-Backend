<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// V2: Cache-Control headers — public GET endpoints get 15min cache; authenticated requests get no-store. Vary tokens are prefix-specific (see VARY_BY_PREFIX).
class AddPublicCacheHeaders
{
    /**
     * Public GET paths that are safe to cache at the CDN/proxy layer.
     * Anything NOT in this list will not receive public Cache-Control headers.
     * This is an allowlist — add new public-cacheable paths explicitly.
     */
    public const CACHEABLE_PATH_PREFIXES = [
        'api/public/site-by-slug',
        // API-4: highest-traffic CDN-critical route — the Astro Worker's SSR
        // subrequest target (see CloudflarePurgeService::purgeHandle docblock).
        // Covers /profiles/{handle}, /integrations, /platforms, /menu.
        'api/public/profiles',
    ];

    /**
     * Paths (or path prefixes) that must never be publicly cached because they
     * carry per-user tokens, affect subscription state, or expose invite details.
     * These receive Cache-Control: no-store even if they are otherwise GET/200.
     */
    private const NO_STORE_PATH_PREFIXES = [
        'api/public/unsubscribe/',
    ];

    /**
     * Per-prefix Vary tokens. Only site-by-slug resolves the tenant from a
     * client-supplied header, so only it must vary on X-Site-Subdomain;
     * profile routes key on the {handle} path segment (which a cache already
     * keys on natively via the URL), so they only need Accept-Encoding.
     * Any cacheable prefix not listed here falls back to DEFAULT_VARY.
     */
    private const VARY_BY_PREFIX = [
        // SEC-1: no shared cache in front of this route honors Vary today —
        // the router Worker passes /api straight through, and this endpoint
        // has no CDN edge cache in the current topology. This header is a
        // forward-guard: if someone later adds a Cloudflare Cache Rule
        // ("Cache Everything") for this path WITHOUT a matching custom Cache
        // Key on X-Site-Subdomain, one tenant's response could be served to
        // another. Not a live exposure today — kept defensively.
        'api/public/site-by-slug' => ['X-Site-Subdomain', 'Accept-Encoding'],
    ];

    private const DEFAULT_VARY = ['Accept-Encoding'];

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
                    // Vary tokens are prefix-specific — see VARY_BY_PREFIX above.
                    $this->mergeVary($response, self::VARY_BY_PREFIX[$prefix] ?? self::DEFAULT_VARY);
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
