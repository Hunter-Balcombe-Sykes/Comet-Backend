<?php

use App\Http\Middleware\AddETagHeaders;
use App\Http\Middleware\AddPublicCacheHeaders;
use App\Http\Middleware\Auth\EnsurePartnaAdmin;
use App\Http\Middleware\Auth\EnsurePartnaStaff;
use App\Http\Middleware\Auth\RequireAal2;
use App\Http\Middleware\Auth\RequireEmailVerified;
use App\Http\Middleware\Auth\VerifySupabaseEmailHookSignature;
use App\Http\Middleware\Auth\VerifySupabaseJwt;
use App\Http\Middleware\Context\LoadCurrentProfessional;
use App\Http\Middleware\FeatureGate;
use App\Http\Middleware\Logging\LogLeadRateLimits;
use App\Http\Middleware\Logging\RecordStaffAuditEntry;
use App\Http\Middleware\SecureHeaders;
use App\Http\Middleware\VerifyTurnstileCaptcha;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        \App\Console\Commands\BackfillHourlyAnalytics::class,
        \App\Console\Commands\CompactHourlyAnalytics::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust all proxy IPs — the app is exclusively behind Cloudflare, so every
        // inbound connection is from a Cloudflare edge node. Without this, $request->ip()
        // returns the Cloudflare edge IP and all rate-limit keys collapse to the same value.
        $middleware->trustProxies(at: '*');

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
            \Illuminate\Routing\Middleware\ThrottleRequests::class,
            VerifySupabaseJwt::class,
        );

        $middleware->alias([
            'supabase.jwt' => VerifySupabaseJwt::class,
            'require.email_verified' => RequireEmailVerified::class,
            'current.pro' => LoadCurrentProfessional::class,
            'staff' => EnsurePartnaStaff::class,
            'staff.admin' => EnsurePartnaAdmin::class,
            'staff.audit' => RecordStaffAuditEntry::class,
            'lead.log' => LogLeadRateLimits::class,
            'supabase.email-hook' => VerifySupabaseEmailHookSignature::class,
            'feature' => FeatureGate::class,
            'captcha' => VerifyTurnstileCaptcha::class,
            'require.aal2' => RequireAal2::class,
        ]);
    })

    ->withExceptions(function (Exceptions $exceptions): void {
        // Handle API exceptions with JSON responses
        $exceptions->render(function (Throwable $e, Request $request) {
            // Only handle API routes
            if (! $request->is('api/*')) {
                return null;
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
                \Illuminate\Support\Facades\Log::warning('Access denied', [
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

            // Generic error handling
            else {
                $statusCode = 500;
                if ($e instanceof HttpException) {
                    $statusCode = $e->getStatusCode();
                }

                // Log full exception for debugging even in production
                if ($statusCode >= 500) {
                    \Illuminate\Support\Facades\Log::error('API Error', [
                        'exception' => $e,
                        'status' => $statusCode,
                    ]);
                }

                // Don't expose internal errors in production
                $message = config('app.debug')
                    ? $e->getMessage()
                    : 'An error occurred';

                $response = response()->json([
                    'message' => $message,
                ], $statusCode);
            }

            // Ensure CORS headers are present on all API error responses.
            // HandleCors middleware adds these during normal flow, but when
            // an exception propagates past it the rendered response skips
            // the CORS header injection. Laravel Cloud's proxy also strips
            // CORS headers on some error responses. This guard ensures the
            // browser can always read the error body.
            if ($response !== null
                && ! $response->headers->has('Access-Control-Allow-Origin')
            ) {
                $response->headers->set('Access-Control-Allow-Origin', '*');
            }

            return $response;
        });
    })->create();
