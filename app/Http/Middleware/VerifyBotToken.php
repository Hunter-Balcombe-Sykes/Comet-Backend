<?php

namespace App\Http\Middleware;

use App\Services\BotProtection\CaptchaManager;
use App\Services\BotProtection\CircuitBreaker;
use App\Services\BotProtection\Exceptions\CaptchaProviderException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class VerifyBotToken
{
    public function __construct(
        private readonly CaptchaManager $captcha,
        private readonly CircuitBreaker $breaker,
    ) {
    }

    public function handle(Request $request, Closure $next, string $action = 'default'): Response
    {
        $mode = (string) config('partna.bot_protection.mode', 'off');

        // Off mode: zero work, zero network, zero Redis call.
        if ($mode === 'off') {
            return $next($request);
        }

        $driver = (string) config('partna.bot_protection.driver', 'null');
        $token  = $this->extractToken($request);

        if ($token === null) {
            Log::info('bot_protection.missing_token', ['action' => $action]);
            if ($mode === 'shadow') {
                Log::info('bot_protection.shadow_reject', ['driver' => $driver, 'action' => $action, 'codes' => ['captcha_missing'], 'has_token' => false]);
                return $next($request);
            }
            return $this->reject('captcha_missing');
        }

        // Breaker check — if Redis is down, treat as breaker-unavailable + fail-open.
        try {
            if ($this->breaker->isOpen($driver)) {
                $this->logFailOpenOnce($driver, $action, $request, 'circuit_open');
                return $next($request);
            }
        } catch (Throwable $e) {
            $this->logBreakerUnavailable($driver, $action, $request);
            return $next($request);
        }

        $timeoutMs = $mode === 'shadow'
            ? (int) config('partna.bot_protection.shadow_timeout_ms', 500)
            : (int) config('partna.bot_protection.enforce_timeout_ms', 3000);

        try {
            $result = $this->captcha->verify($token, $request->ip(), $action, $timeoutMs);
        } catch (CaptchaProviderException $e) {
            $this->safelyRecord(fn () => $this->breaker->recordFailure($driver));
            Log::warning('bot_protection.fail_open', [
                'driver'     => $driver,
                'reason'     => 'provider_error',
                'action'     => $action,
                'route'      => $request->path(),
                'ip'         => $request->ip(),
                'request_id' => $request->header('X-Request-Id'),
            ]);
            return $next($request);
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

        if (! is_string($raw)) return null;
        return blank($raw) ? null : trim($raw);
    }

    private function reject(string $error): Response
    {
        return response()->json([
            'message' => 'Verification failed.',
            'error'   => $error,
            'captcha' => [
                'should_retry'    => true,
                'should_rerender' => true,
            ],
        ], 422);
    }

    private function safelyRecord(\Closure $op): void
    {
        try {
            $op();
        } catch (Throwable $e) {
            // Breaker bookkeeping failures must never break a request.
        }
    }

    private function logFailOpenOnce(string $driver, string $action, Request $request, string $reason): void
    {
        // Dedup via Redis: log once per cooldown window per driver. If Redis is dead
        // we already passed-through in the caller; we just skip logging here.
        try {
            $key = "bot_protection:fail_open_logged:{$driver}:{$reason}";
            $count = \Illuminate\Support\Facades\Redis::incr($key);
            if ($count === 1) {
                \Illuminate\Support\Facades\Redis::expire($key, (int) config('partna.bot_protection.circuit_breaker.cooldown_seconds', 300));
                Log::warning('bot_protection.fail_open', [
                    'driver' => $driver, 'reason' => $reason, 'action' => $action,
                    'route' => $request->path(), 'ip' => $request->ip(),
                    'request_id' => $request->header('X-Request-Id'),
                ]);
            }
        } catch (Throwable $e) {
            // Silent — observability failure must not break the request.
        }
    }

    private function logBreakerUnavailable(string $driver, string $action, Request $request): void
    {
        static $warned = false;
        if ($warned) return;
        $warned = true;
        Log::warning('bot_protection.breaker_unavailable', [
            'driver' => $driver, 'action' => $action, 'route' => $request->path(),
        ]);
    }
}
