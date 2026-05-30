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
                $this->logFailOpenOnce($driver, $action, $request, 'circuit_open');

                return $this->failOpenOrReject($failOpen, $mode, $next, $request);
            }
        } catch (Throwable $e) {
            $this->logBreakerUnavailable($driver, $action, $request);

            return $this->failOpenOrReject($failOpen, $mode, $next, $request);
        }

        $timeoutMs = $mode === 'shadow'
            ? (int) config('partna.bot_protection.shadow_timeout_ms', 500)
            : (int) config('partna.bot_protection.enforce_timeout_ms', 3000);

        try {
            $result = $this->captcha->verify($token, $request->ip(), $action, $timeoutMs);
        } catch (CaptchaProviderException $e) {
            $this->safelyRecord(fn () => $this->breaker->recordFailure($driver));
            Log::warning('bot_protection.fail_open', [
                'driver' => $driver,
                'reason' => 'provider_error',
                'action' => $action,
                'route' => $request->path(),
                'ip_hash' => $this->hashedIp($request),
                'request_id' => $request->header('X-Request-Id'),
            ]);

            return $this->failOpenOrReject($failOpen, $mode, $next, $request);
        }

        $this->safelyRecord(fn () => $result->success ? $this->breaker->recordSuccess($driver) : null);

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

    private function safelyRecord(Closure $op): void
    {
        try {
            $op();
        } catch (Throwable $e) {
            // Breaker bookkeeping failures must never break a request.
        }
    }

    /**
     * Keyed SHA-256 of the client IP for log correlation without storing raw PII.
     * Mirrors the reporter_ip_hash scheme used elsewhere (sha256 of ip + app key).
     */
    private function hashedIp(Request $request): string
    {
        $ip = (string) $request->ip();

        return substr(hash('sha256', $ip . '|' . config('app.key')), 0, 16);
    }

    private function logFailOpenOnce(string $driver, string $action, Request $request, string $reason): void
    {
        // Dedup via Redis: log once per cooldown window per driver. If Redis is dead
        // we already passed-through in the caller; we just skip logging here.
        // Note: reuses circuit_breaker.cooldown_seconds as the dedup TTL — tuning
        // cooldown also shifts how often we re-log during an extended outage.
        try {
            $key = "bot_protection:fail_open_logged:{$driver}:{$reason}";
            $count = Redis::incr($key);
            if ($count === 1) {
                Redis::expire($key, (int) config('partna.bot_protection.circuit_breaker.cooldown_seconds', 300));
                Log::warning('bot_protection.fail_open', [
                    'driver' => $driver, 'reason' => $reason, 'action' => $action,
                    'route' => $request->path(), 'ip_hash' => $this->hashedIp($request),
                    'request_id' => $request->header('X-Request-Id'),
                ]);
            }
        } catch (Throwable $e) {
            // Silent — observability failure must not break the request.
        }
    }

    private function logBreakerUnavailable(string $driver, string $action, Request $request): void
    {
        // Dedup via Redis cooldown window (same pattern as logFailOpenOnce) rather
        // than a process-static flag. The process-static version under-logs in
        // long-lived workers (Octane, queue) when Redis flaps — only the first
        // outage per process boot would log. If Redis is genuinely unavailable
        // the INCR throws and we silently skip — that matches the "fail-open on
        // observability failure" principle used everywhere else in this file.
        try {
            $key = "bot_protection:breaker_unavailable_logged:{$driver}";
            $count = Redis::incr($key);
            if ($count === 1) {
                Redis::expire($key, (int) config('partna.bot_protection.circuit_breaker.cooldown_seconds', 300));
                Log::warning('bot_protection.breaker_unavailable', [
                    'driver' => $driver, 'action' => $action, 'route' => $request->path(),
                ]);
            }
        } catch (Throwable $e) {
            // Silent — observability failure must not break the request.
        }
    }
}
