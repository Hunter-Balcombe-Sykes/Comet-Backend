<?php

namespace App\Http\Middleware;

use App\Services\BotProtection\CaptchaManager;
use App\Services\BotProtection\CircuitBreaker;
use App\Services\BotProtection\Exceptions\CaptchaProviderException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class VerifyBotToken
{
    public function __construct(
        private readonly CaptchaManager $captcha,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function handle(Request $request, Closure $next, string $action = 'default'): Response
    {
        $mode = (string) config('partna.bot_protection.mode', 'off');

        // Off mode: zero work, zero network, zero Redis call.
        if ($mode === 'off') {
            return $next($request);
        }

        $driver = (string) config('partna.bot_protection.driver', 'null');
        $failOpen = (bool) config('partna.bot_protection.fail_open', true);
        $token = $this->extractToken($request);

        if ($token === null) {
            Log::info('bot_protection.missing_token', ['action' => $action]);
            if ($mode === 'shadow') {
                Log::info('bot_protection.shadow_reject', ['driver' => $driver, 'action' => $action, 'codes' => ['captcha_missing'], 'has_token' => false]);

                return $next($request);
            }

            return $this->reject('captcha_missing');
        }

        // Breaker check — if Redis is down, treat as breaker-unavailable + fail-open
        // (or 503 unavailable when fail_open=false).
        try {
            if ($this->breaker->isOpen($driver)) {
                $this->throttledFailReport(
                    "bot_protection:fail_open_logged:{$driver}:circuit_open",
                    'bot_protection.fail_open',
                    [
                        'driver' => $driver, 'reason' => 'circuit_open', 'action' => $action,
                        'route' => $request->path(), 'ip_hash' => $this->hashedIp($request),
                        'request_id' => $request->header('X-Request-Id'),
                    ],
                    "bot_protection fail-open [{$driver}:circuit_open] action={$action}"
                );

                return $this->failOpenOrReject($failOpen, $mode, $next, $request);
            }
        } catch (Throwable $e) {
            $this->throttledFailReport(
                "bot_protection:breaker_unavailable_logged:{$driver}",
                'bot_protection.breaker_unavailable',
                ['driver' => $driver, 'action' => $action, 'route' => $request->path()],
                "bot_protection circuit-breaker unavailable [{$driver}] action={$action}"
            );

            return $this->failOpenOrReject($failOpen, $mode, $next, $request);
        }

        $timeoutMs = $mode === 'shadow'
            ? (int) config('partna.bot_protection.shadow_timeout_ms', 500)
            : (int) config('partna.bot_protection.enforce_timeout_ms', 3000);

        try {
            $result = $this->captcha->verify($token, $request->ip(), $action, $timeoutMs);
        } catch (CaptchaProviderException $e) {
            $this->safelyRecord(fn () => $this->breaker->recordFailure($driver), 'record_failure');

            try {
                // Log EVERY provider failure (Redis-independent, as before) so a vendor outage
                // is fully visible in logs; throttle only the Nightwatch report() to one per
                // cooldown window so a sustained outage doesn't flood alerts (OBS-4).
                Log::warning('bot_protection.fail_open', [
                    'driver' => $driver, 'reason' => 'provider_error', 'action' => $action,
                    'route' => $request->path(), 'ip_hash' => $this->hashedIp($request),
                    'request_id' => $request->header('X-Request-Id'),
                ]);
                if ($this->firstHitInWindow("bot_protection:fail_open_reported:{$driver}:provider_error")) {
                    report(new \RuntimeException("bot_protection fail-open [{$driver}:provider_error] action={$action}"));
                }
            } catch (Throwable $e) {
                // Observability must never break a request.
            }

            return $this->failOpenOrReject($failOpen, $mode, $next, $request);
        }

        $this->safelyRecord(fn () => $result->success ? $this->breaker->recordSuccess($driver) : null, 'record_success');

        if ($result->success) {
            return $next($request);
        }

        if ($mode === 'shadow') {
            Log::info('bot_protection.shadow_reject', [
                'driver' => $driver, 'action' => $action, 'codes' => $result->errorCodes, 'score' => $result->score, 'has_token' => true,
            ]);

            return $next($request);
        }

        // Map provider's timeout-or-duplicate sentinel to user-friendly captcha_expired.
        $userError = in_array('captcha_expired', $result->errorCodes, true) ? 'captcha_expired' : 'captcha_failed';

        // Server-side log keeps the raw provider codes; user response does not (info disclosure).
        Log::info('bot_protection.failed', ['driver' => $driver, 'action' => $action, 'codes' => $result->errorCodes]);

        return $this->reject($userError);
    }

    private function extractToken(Request $request): ?string
    {
        $raw = $request->header('X-Captcha-Token')
            ?? $request->input('captcha_token')
            ?? $request->input('cf_turnstile_response');  // legacy alias — accepted for one release

        if (! is_string($raw)) {
            return null;
        }

        return blank($raw) ? null : trim($raw);
    }

    private function reject(string $error): Response
    {
        return response()->json([
            'message' => 'Verification failed.',
            'error' => $error,
            'captcha' => [
                'should_retry' => true,
                'should_rerender' => true,
            ],
        ], 422);
    }

    // 503 path used when fail_open=false and the CAPTCHA backend is unreachable
    // (provider exception, Redis-backed breaker unavailable, or breaker open).
    // Distinct from 422 captcha_failed so the frontend can render a
    // "try again shortly" UX rather than re-rendering the widget.
    private function unavailable(): Response
    {
        return response()->json([
            'message' => 'Verification temporarily unavailable.',
            'error' => 'captcha_unavailable',
            'captcha' => [
                'should_retry' => true,
                'should_rerender' => false,
            ],
        ], 503);
    }

    // Centralises the fail_open switch so every fail-open branch routes through
    // the same decision. Shadow mode always passes through regardless of fail_open —
    // shadow is observation-only and must never reject in production.
    private function failOpenOrReject(bool $failOpen, string $mode, Closure $next, Request $request): Response
    {
        if ($failOpen || $mode === 'shadow') {
            return $next($request);
        }

        return $this->unavailable();
    }

    private function safelyRecord(Closure $op, string $opLabel): void
    {
        try {
            $op();
        } catch (Throwable $e) {
            // Breaker bookkeeping failures must never break a request. Still worth a
            // throttled breadcrumb (not a page — this is best-effort bookkeeping, not
            // a fail-open decision) so a flapping breaker store doesn't go unnoticed.
            $this->throttledFailReport(
                "bot_protection:breaker_record_failed:{$opLabel}",
                'bot_protection.breaker_record_failed',
                ['op' => $opLabel, 'reason' => $e->getMessage()],
                null
            );
        }
    }

    /**
     * Keyed HMAC-SHA256 of the client IP for log correlation without storing raw PII.
     * Uses hash_hmac with app.key — same scheme as HashesClientData and ContentReportService
     * so cross-system IP hashes are correlatable (SEC-14).
     */
    private function hashedIp(Request $request): string
    {
        $ip = (string) $request->ip();

        return substr(hash_hmac('sha256', $ip, config('app.key')), 0, 16);
    }

    /**
     * Atomic "first hit in this cooldown window?" check — single Lua round-trip:
     * INCR then EXPIRE-only-on-first-hit, so a crash between commands can't orphan
     * the TTL. Returns true at most once per cooldown window per key. Reuses
     * circuit_breaker.cooldown_seconds as the dedup TTL.
     * Redis unavailable → false (skip observability; must never break a request).
     */
    private function firstHitInWindow(string $dedupKey): bool
    {
        try {
            $ttl = (int) config('partna.bot_protection.circuit_breaker.cooldown_seconds', 300);
            $count = Redis::eval(
                "local c = redis.call('INCR', KEYS[1]) if c == 1 then redis.call('EXPIRE', KEYS[1], ARGV[1]) end return c",
                1,
                $dedupKey,
                $ttl
            );

            return (int) $count === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Dedup BOTH the log AND the report() to once per cooldown window per dedup key.
     * Used where under-logging during an extended outage is acceptable (circuit_open,
     * breaker_unavailable, breaker-bookkeeping failures). NOT used on the provider_error
     * path — that logs unconditionally and only throttles the report (OBS-4).
     *
     * $reportMessage === null means "throttled warning only, no Nightwatch page" —
     * used for best-effort bookkeeping failures that don't need paging.
     *
     * @param  array<string, mixed>  $context
     */
    private function throttledFailReport(string $dedupKey, string $logEvent, array $context, ?string $reportMessage = null): void
    {
        if (! $this->firstHitInWindow($dedupKey)) {
            return;
        }

        try {
            Log::warning($logEvent, $context);
            if ($reportMessage !== null) {
                report(new \RuntimeException($reportMessage));
            }
        } catch (Throwable $e) {
            // Observability must never break a request — a fail-open decision is already made.
        }
    }
}
