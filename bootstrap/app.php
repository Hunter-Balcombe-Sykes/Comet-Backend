<?php

use App\Console\Commands\BackfillHourlyAnalytics;
use App\Console\Commands\CompactHourlyAnalytics;
use App\Console\Commands\Moderation\ModerationRedactReporterPiiCommand;
use App\Console\Commands\Moderation\ModerationShowCaseCommand;
use App\Console\Commands\Moderation\ModerationSlaScanCommand;
use App\Contracts\HttpStatusCodeInterface;
use App\Http\Middleware\AddETagHeaders;
use App\Http\Middleware\AddPublicCacheHeaders;
use App\Http\Middleware\Auth\EnsurePartnaAdmin;
use App\Http\Middleware\Auth\EnsurePartnaStaff;
use App\Http\Middleware\Auth\RequireAal2;
use App\Http\Middleware\Auth\RequireEmailVerified;
use App\Http\Middleware\Auth\RequireStrongAuth;
use App\Http\Middleware\Auth\RequireVerifiedRevocation;
use App\Http\Middleware\Auth\VerifyManyChatWebhook;
use App\Http\Middleware\Auth\VerifyResendWebhookSignature;
use App\Http\Middleware\Auth\VerifySupabaseHookSignature;
use App\Http\Middleware\Auth\VerifySupabaseJwt;
use App\Http\Middleware\Context\ArmRedisRequestBreaker;
use App\Http\Middleware\Context\EnsurePlatformAvailable;
use App\Http\Middleware\Context\LoadCurrentUser;
use App\Http\Middleware\FeatureGate;
use App\Http\Middleware\IdempotencyKey;
use App\Http\Middleware\Logging\LogLeadRateLimits;
use App\Http\Middleware\Logging\RecordStaffAuditEntry;
use App\Http\Middleware\SecureHeaders;
use App\Http\Middleware\VerifyBotToken;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    // Application::configure() calls ->withEvents() itself (discover: true by
    // default), which auto-binds any app/Listeners class onto every event its
    // handle() signature mentions. AppServiceProvider::boot() ALSO registers
    // RecordCacheMetrics, RecordScheduledTaskHeartbeat, and
    // BlockSuppressedRecipients via explicit Event::listen() calls, so without
    // this every one of those three listeners double-fires (#CACHE-2 — 66
    // spurious cache-hit-rate SLO alerts in 7 days, verified via
    // Event::getRawListeners() showing two bindings per event). Disabling
    // discovery keeps AppServiceProvider's explicit list the single source of truth.
    ->withEvents(discover: false)
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        BackfillHourlyAnalytics::class,
        CompactHourlyAnalytics::class,
        ModerationRedactReporterPiiCommand::class,
        ModerationSlaScanCommand::class,
        ModerationShowCaseCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Must run FIRST — resets RedisRequestBreaker before any other
        // middleware (or the routes/controllers behind them) can touch
        // Redis, so every request starts with a clean breaker state.
        $middleware->prepend(ArmRedisRequestBreaker::class);

        // The app is exclusively behind Cloudflare, so every inbound connection's
        // REMOTE_ADDR should be a Cloudflare edge node (confirmed by the comment
        // this replaced: "$request->ip() returns the Cloudflare edge IP" describes
        // observed behaviour, not a hypothetical). `at: '*'` trusted EVERY immediate
        // peer's X-Forwarded-For unconditionally — an attacker who reaches the origin
        // directly (bypassing Cloudflare) could set X-Forwarded-For to anything and
        // have it believed, which is also what made CF-Connecting-IP unsafe to read
        // directly elsewhere (AppServiceProvider's rate limiters, PreAccountBuild
        // Controller) — that header has no trust boundary of its own; it is only
        // as trustworthy as knowing the request actually came via Cloudflare.
        //
        // Restricting to Cloudflare's published ranges (cloudflare.com/ips-v4,
        // /ips-v6, fetched 2026-08-24 — refetch and diff periodically, Cloudflare
        // adds ranges occasionally) makes $request->ip() the single source of
        // truth: correct via X-Forwarded-For when REMOTE_ADDR really is Cloudflare,
        // and safely falls back to the untrusted-but-real REMOTE_ADDR otherwise —
        // never an attacker-supplied header. A wrong or stale range list here
        // breaks per-IP rate limiting for everyone (REMOTE_ADDR would no longer be
        // trusted, collapsing every key to itself) rather than opening a hole, but
        // it is still verified against a live request's CF-Connecting-IP before and
        // after landing this (see the handoff), and lands as its own revertible
        // commit for exactly that reason.
        $middleware->trustProxies(at: [
            // IPv4
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            // IPv6
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ]);

        $middleware->append(SecureHeaders::class);

        // Apply public-cache headers to every API route. The middleware itself
        // only emits Cache-Control/Vary headers for the allow-listed public paths;
        // all other routes pass through untouched.
        $middleware->appendToGroup('api', AddPublicCacheHeaders::class);

        // Set ETag on cacheable public GET responses; return 304 when If-None-Match
        // matches. Shares CACHEABLE_PATH_PREFIXES with AddPublicCacheHeaders so the
        // two lists can never drift out of sync.
        $middleware->appendToGroup('api', AddETagHeaders::class);

        // Pin VerifySupabaseJwt before ThrottleRequests in the middleware priority list.
        // Without this, Laravel's SortedMiddleware moves ThrottleRequests (priority 6)
        // ahead of SubstituteBindings (priority 9, injected by the `api` group), which
        // drags it ahead of every unlisted middleware between them — including the JWT
        // verifier. The per-uid rate limiters in AppServiceProvider then fire before
        // `supabase_uid` is set on the request and throw RuntimeException.
        $middleware->prependToPriorityList(
            ThrottleRequests::class,
            VerifySupabaseJwt::class,
        );

        // Pin IdempotencyKey before ThrottleRequests for the same reason — a
        // successful replay must not consume rate-limit budget. The middleware
        // also depends on `supabase_uid` being set, which means it has to run
        // AFTER VerifySupabaseJwt; the natural priority-list order does that.
        $middleware->prependToPriorityList(
            ThrottleRequests::class,
            IdempotencyKey::class,
        );

        // Pin the strict-revocation gate ahead of IdempotencyKey (and therefore
        // ahead of ThrottleRequests too, which IdempotencyKey already precedes).
        //
        // WHY THIS IS NOT COSMETIC. Unlisted middleware keep their natural
        // position, which put this gate LAST — after both throttles. Drill 03
        // (2026-08-05) measured the consequence: during a Redis outage every
        // strict route 503'd from FailOpenThrottleRequests before the gate ever
        // ran, because no strict route's limiter is in
        // FailOpenThrottleRequests::FAIL_OPEN_LIMITERS (all five are public).
        // The protection looked correct and was entirely accidental — one
        // allow-list edit away from silently vanishing. Ahead of throttle, the
        // gate is the layer that answers, and it decides from one request
        // attribute rather than from a Redis round-trip.
        //
        // Precisely: the PASS-THROUGH branch touches nothing. The DENY branch does
        // one `cache_locks` write to throttle its Nightwatch report. That write is
        // itself short-circuited in the outage case, because RedisRequestBreaker is
        // one breaker for all connections and VerifySupabaseJwt will already have
        // tripped it — but "the gate never touches Redis" would be too strong a
        // claim, and this pin should not rest on one.
        //
        // Ahead of IdempotencyKey specifically so a replayed Idempotency-Key
        // cannot re-serve a cached response for a session whose revocation
        // status is unknown.
        //
        // It still runs AFTER VerifySupabaseJwt: that middleware is pinned
        // earlier in the list by the call above, and the gate depends on the
        // `supabase_revocation_verified` attribute it sets. An invalid token
        // therefore still 401s from the verifier and never reaches this.
        // StrictRevocationTest pins all three orderings.
        $middleware->prependToPriorityList(
            IdempotencyKey::class,
            RequireVerifiedRevocation::class,
        );

        $middleware->alias([
            'supabase.jwt' => VerifySupabaseJwt::class,
            'require.email_verified' => RequireEmailVerified::class,
            'current.pro' => LoadCurrentUser::class,
            'staff' => EnsurePartnaStaff::class,
            'staff.admin' => EnsurePartnaAdmin::class,
            'staff.audit' => RecordStaffAuditEntry::class,
            'lead.log' => LogLeadRateLimits::class,
            'supabase.auth-hook' => VerifySupabaseHookSignature::class.':services.supabase.auth_hook_secret,supabase.auth_hook,Auth',
            'supabase.email-hook' => VerifySupabaseHookSignature::class.':services.supabase.email_hook_secret,supabase.email_hook,Email',
            'resend.webhook' => VerifyResendWebhookSignature::class,
            'manychat.webhook' => VerifyManyChatWebhook::class,
            'feature' => FeatureGate::class,
            'bot.token' => VerifyBotToken::class,
            'require.aal2' => RequireAal2::class,
            'require.strong_auth' => RequireStrongAuth::class,
            // Selective fail-closed revocation. NOT redundant with require.aal2:
            // that reads a JWT claim and proves MFA happened at LOGIN; this
            // proves the session has not been revoked SINCE. Different questions,
            // so staff routes carry both.
            'revocation.strict' => RequireVerifiedRevocation::class,
            'idempotent' => IdempotencyKey::class,
            'platform.available' => EnsurePlatformAvailable::class,
        ]);

        // Named group for the standard authenticated user route stack.
        // Applies JWT verification, email verification, and professional resolution
        // in one alias so route files stay readable and can't accidentally omit one.
        $middleware->appendToGroup('user.api', [
            'supabase.jwt',
            'require.email_verified',
            'current.pro',
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle API exceptions with JSON responses
        $exceptions->render(function (Throwable $e, Request $request) {
            // For non-API routes (QR-code SVG path), apply security headers on
            // HttpException responses — returning null for other exception types
            // lets Laravel's default renderer produce the correct JSON/HTTP status
            // (e.g. ValidationException → 422 when Accept: application/json) (#P2-40).
            if (! $request->is('api/*')) {
                if (! $e instanceof HttpException) {
                    return null;
                }
                // Keep the exception's own headers — the Horizon gate's 401 carries
                // WWW-Authenticate, without which browsers never prompt for Basic auth.
                $nonApiResponse = response('', $e->getStatusCode(), $e->getHeaders());
                SecureHeaders::apply($nonApiResponse, $request);

                return $nonApiResponse;
            }

            $response = null;

            // Validation errors (422)
            if ($e instanceof ValidationException) {
                $response = response()->json([
                    'message' => 'Validation failed',
                    'errors' => $e->errors(),
                ], 422);
            }

            // Model not found (404)
            elseif ($e instanceof ModelNotFoundException) {
                $response = response()->json([
                    'message' => 'Resource not found',
                ], 404);
            }

            // Route not found (404)
            elseif ($e instanceof NotFoundHttpException) {
                $response = response()->json([
                    'message' => 'Endpoint not found',
                ], 404);
            }

            // Policy denials via prepareException() — AuthorizationException(hasStatus=true)
            // becomes HttpException(status). denyAsNotFound() → HttpException(404), distinct
            // from NotFoundHttpException (route not found). denyWithStatus(423) → HttpException(423).
            elseif ($e instanceof HttpException
                && ! $e instanceof NotFoundHttpException
                && ($e->getStatusCode() === 404 || $e->getStatusCode() === 423)
            ) {
                $response = $e->getStatusCode() === 404
                    ? response()->json(['message' => 'Resource not found'], 404)
                    : response()->json(['message' => $e->getMessage() ?: 'Account is pending deletion.'], 423);
            }

            // Forbidden (403)
            elseif ($e instanceof AccessDeniedHttpException) {
                Log::warning('Access denied', [
                    'path' => $request->path(),
                    'message' => $e->getMessage(),
                ]);

                $response = response()->json([
                    'message' => $e->getMessage() ?: 'Access denied',
                ], 403);
            }

            // Preserve explicit response exceptions (e.g. throttle 429)
            elseif ($e instanceof HttpResponseException) {
                $response = $e->getResponse();
            }

            // Domain exceptions that declare their own HTTP contract (e.g. 429, 409).
            elseif ($e instanceof HttpStatusCodeInterface) {
                $response = response()->json(
                    ['message' => $e->getMessage()],
                    $e->getHttpStatusCode()
                );
                foreach ($e->getHttpHeaders() as $header => $value) {
                    if (! is_string($header)) {
                        continue; // interface contract requires string keys; skip malformed entries silently
                    }
                    $response->headers->set($header, (string) $value);
                }
            }

            // Generic error handling
            else {
                $statusCode = 500;
                if ($e instanceof HttpException) {
                    $statusCode = $e->getStatusCode();
                }

                // Log full exception for debugging even in production
                if ($statusCode >= 500) {
                    Log::error('API Error', [
                        'exception' => $e,
                        'status' => $statusCode,
                    ]);
                }

                // Pass through non-empty 4xx messages — these come from intentional
                // abort() calls with explicit messaging. Only 5xx errors get the
                // generic fallback in production to prevent internal detail leakage (#P2-30).
                $message = ($e instanceof HttpException && $statusCode < 500 && $e->getMessage() !== '')
                    ? $e->getMessage()
                    : (config('app.debug') ? $e->getMessage() : 'An error occurred');

                $response = response()->json([
                    'message' => $message,
                ], $statusCode);
            }

            // Delegate to SecureHeaders — single source of truth for all security
            // headers (XFO, CSP, HSTS, nosniff, Referrer-Policy, Permissions-Policy)
            // AND CORS. Without this, error responses ship un-headered because the
            // exception renderer runs after middleware has unwound (#P2-40, #P3-11).
            if ($response !== null) {
                // Prevent browsers from heuristically caching error responses — the
                // AddPublicCacheHeaders middleware never runs on the exception path (#P2-41).
                $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
                $response->headers->set('Pragma', 'no-cache');
                SecureHeaders::apply($response, $request);
            }

            return $response;
        });
    })->create();
