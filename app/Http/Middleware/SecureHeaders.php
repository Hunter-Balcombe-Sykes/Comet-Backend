<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single source of truth for response security headers (XFO, CSP, HSTS, nosniff,
 * Referrer-Policy, Permissions-Policy) AND for the CORS allowlist used on the
 * exception-render fallback path (#P2-40 / #P3-11).
 *
 * Two call sites invoke the static `apply()` helper:
 *   1. This middleware's `handle()` — covers normal request flow.
 *   2. The exception render closure in bootstrap/app.php — covers error responses
 *      that propagate past middleware (without this, 4xx/5xx ship un-headered).
 */
class SecureHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        self::apply($response, $request);

        return $response;
    }

    /**
     * Apply the full security-header set + CORS to $response. Idempotent: existing
     * headers are not overwritten, so the exception path will not clobber a header
     * already set by upstream middleware.
     */
    public static function apply(Response $response, Request $request): void
    {
        self::applyCors($response, $request);

        // Suppress PHP's X-Powered-By version banner (ZAP passive rule 10037,
        // CWE-497 — it hands an attacker the exact PHP patch level to match
        // against known CVEs). Two things about this are load-bearing:
        //
        //   1. It MUST be header_remove(), not $response->headers->remove().
        //      PHP's SAPI injects this header itself when expose_php=On; it
        //      never enters Symfony's HeaderBag, so removing it there strips a
        //      header that was never present. Verified 2026-07-31 against a live
        //      `artisan serve`: the HeaderBag removal left the banner intact.
        //   2. It lives in apply(), not handle(), so BOTH call sites are covered
        //      — an exception that propagates past middleware is rendered by the
        //      closure in bootstrap/app.php, which calls apply() directly.
        //
        // Belt-and-braces against server config: the real remedy is expose_php=Off
        // in php.ini, which is not ours to set on Laravel Cloud. This makes the
        // app correct regardless of how the runtime is configured.
        //
        // No PHPUnit guard is possible — a SAPI-level header is invisible to a
        // Symfony Response object and headers_list() is empty under the CLI SAPI.
        // The regression guard is the DAST active lane: ZAP rule 10037 reappears
        // in audits/dast/<date>/REPORT.md if this stops working.
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        $set = static function (string $name, string $value) use ($response): void {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        };

        $set('X-Frame-Options', 'DENY');
        $set('X-Content-Type-Options', 'nosniff');
        $set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Horizon ships its dashboard as inline <style> / <script type="module"> blocks
        // (vendor/laravel/horizon/src/Horizon.php::css/js) plus a webfont from fonts.bunny.net.
        // The default `default-src 'none'` policy strips all of them and the dashboard
        // renders as un-styled, oversized SVG blobs. Loosen CSP for Horizon's path only —
        // the admin gate in AppServiceProvider::authorizeHorizonRequest contains the trust.
        if ($request->is('horizon', 'horizon/*')) {
            // Horizon is the ONLY rendered-HTML surface in this app, so it is the
            // only place CSP actually evaluates and the only place report-uri can
            // ever produce traffic. JSON API responses do not trigger CSP at all.
            $set(
                'Content-Security-Policy',
                "default-src 'self'; "
                ."style-src 'self' 'unsafe-inline' https://fonts.bunny.net; "
                // 'unsafe-eval' is required by Vue's runtime template compiler bundled
                // into Horizon's app.js — without it, mount() throws EvalError on every
                // screen render and the dashboard never appears.
                ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
                ."font-src 'self' https://fonts.bunny.net; "
                .'img-src \'self\' data:; '
                ."connect-src 'self'; "
                ."form-action 'self'; "
                ."base-uri 'self'; "
                ."frame-ancestors 'none'; "
                .'report-uri /api/internal/csp-report'
            );
        } else {
            // Public API surface: hardest possible policy. form-action and base-uri
            // close two long-known CSP-bypass vectors (form hijacking and <base href>
            // rewriting). report-uri is omitted: JSON responses never trigger CSP,
            // so the directive would only add bytes for zero reports.
            $set(
                'Content-Security-Policy',
                "default-src 'none'; "
                ."form-action 'self'; "
                ."base-uri 'self'; "
                ."frame-ancestors 'none'"
            );
        }

        if (! app()->environment('local', 'testing')) {
            // HSTS without `preload` — keeping the door reversible. Adding `preload`
            // is a one-way commitment (browsers bake the entry in for months after
            // submission); revisit only when prod is verified HTTPS-only forever.
            $set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }

    /**
     * Apply CORS headers when the request's Origin is in the allowlist (exact or
     * pattern match against config/cors.php). HandleCors normally does this during
     * the request flow; this static helper re-derives the same decision for the
     * exception-render path, which executes after middleware has unwound.
     *
     * Behavior:
     *   - No `Origin` header on request → no CORS headers added (server-to-server
     *     or same-origin request; CORS does not apply).
     *   - Origin matches allowlist → echo back exact Origin + Vary: Origin.
     *   - Origin does not match → no CORS headers added; browser blocks the read.
     */
    public static function applyCors(Response $response, ?Request $request = null): void
    {
        // Always emit Vary: Origin when an Origin header is present, even if we
        // ultimately deny CORS — caches must not serve a no-CORS response to an
        // allowlisted origin (or vice versa).
        $origin = $request?->headers->get('Origin');
        if ($origin === null || $origin === '') {
            return;
        }

        // Append (not set) so we don't clobber an existing Vary value.
        $existingVary = $response->headers->get('Vary');
        if ($existingVary === null || ! preg_match('/(^|,\s*)Origin($|\s*,)/i', $existingVary)) {
            $response->headers->set(
                'Vary',
                trim(($existingVary ? $existingVary.', ' : '').'Origin'),
            );
        }

        if (! self::originAllowed($origin)) {
            return;
        }

        // If HandleCors already set this (normal flow), don't double-write.
        if (! $response->headers->has('Access-Control-Allow-Origin')) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }
    }

    /**
     * Mirror config/cors.php's allowlist + pattern check. Kept here so the
     * exception-render path can re-derive the decision without invoking the
     * full HandleCors middleware (which has already unwound by then).
     */
    private static function originAllowed(string $origin): bool
    {
        $exact = (array) config('partna.frontend_origins', []);
        if (in_array($origin, $exact, true)) {
            return true;
        }

        $patterns = (array) config('cors.allowed_origins_patterns', []);
        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }
            $result = preg_match($pattern, $origin);
            if ($result === 1) {
                return true;
            }
            if ($result === false) {
                // Malformed regex in config: surface loudly so the typo is fixed,
                // rather than silently dropping every match against this pattern.
                Log::error('cors.pattern_invalid', [
                    'pattern' => $pattern,
                    'error' => preg_last_error_msg(),
                ]);
            }
        }

        return false;
    }
}
