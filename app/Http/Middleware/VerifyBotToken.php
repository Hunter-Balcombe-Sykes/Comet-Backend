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

        // SCALE-1: 3000ms enforce timeout evaluated and deliberately kept, not tightened —
        // this is the conversion-sensitive signup/enquiry path, and false-rejecting a
        // legitimate user on a slow-but-valid provider round-trip is worse than the bounded
        // worker-hold, which the CircuitBreaker below already caps for sustained failure.
        $timeoutMs = $mode === 'shadow'
            ? (int) config('partna.bot_protection.shadow_timeout_ms', 500)
            : (int) config('partna.bot_protection.enforce_timeout_ms', 3000);

        // SCALE-2: no proactive outbound rate-limit to the CAPTCHA provider on purpose —
        // CircuitBreaker is reactive (opens after failures), and a client-side token-bucket
        // is a documented won't-do for now (pre-pilot, no traffic warrants it yet). If needed
        // later, use Illuminate\Support\Facades\RateLimiter (same named-limiter pattern as
        // outbound provider calls in PlatformRegistryServiceProvider).
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
                // !== false (not a bare truthy check): null means the dedup store itself
                // is unreachable, so we cannot tell "already alerted" from "can't tell" —
                // must report rather than risk self-muting during a Redis outage (WHK-3).
                if ($this->firstHitInWindow("bot_protection:fail_open_reported:{$driver}:provider_error") !== false) {
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
        ], 400); // 400, not 422 — 422 collides with Laravel's FormRequest validation-error shape (API-2)
    }

    // 503 path used when fail_open=false and the CAPTCHA backend is unreachable
    // (provider exception, Redis-backed breaker unavailable, or breaker open).
    // Distinct from the 400 captcha_failed so the frontend can render a
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
     *
     * Tri-state on purpose (WHK-3): collapsing "already alerted this window" and
     * "the dedup store itself is unreachable" into the same `false` self-mutes
     * the alert on every request during a Redis outage — worst on the
     * breaker_unavailable caller, which is reached BECAUSE the breaker's own
     * Redis call just threw. `null` lets the caller tell the two apart and
     * choose not to throttle when it can't.
     */
    private function firstHitInWindow(string $dedupKey): ?bool
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
            return null;
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
     * firstHitInWindow()'s `null` (dedup store unreachable) falls through to an
     * unconditional log+report rather than returning early like `false` does —
     * we cannot throttle without the store, so staying silent here would
     * self-mute the one alert that says "the throttle store — and usually the
     * breaker itself — is down" (WHK-3). A stateless sample (mt_rand, no
     * store needed — see EscalatesRepeatedFaults' Tier 2) was a real option
     * for exactly this "the counter's own store just failed" case, but this
     * middleware follows the closer shipped precedent instead:
     * IdempotencyKey::logFailOpen throttles report() via Cache::lock and, on
     * catch(Throwable) — lock store unreachable — reports unconditionally
     * with no sampling. Same shape here, and this path only guards a handful
     * of low-volume public form endpoints already sitting behind a
     * per-identifier throttle: middleware, so unconditional reporting can't
     * flood Nightwatch the way high-frequency analytics traffic could.
     *
     * @param  array<string, mixed>  $context
     */
    private function throttledFailReport(string $dedupKey, string $logEvent, array $context, ?string $reportMessage = null): void
    {
        if ($this->firstHitInWindow($dedupKey) === false) {
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
