<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthFactorEventRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Receives Supabase Auth Hook callbacks.
 *
 * Currently handles only the MFA Verification Hook — every TOTP/Phone
 * verification attempt is announced to us *before* Supabase promotes
 * the session to aal2. We respond with {decision: "continue"} to allow,
 * or {decision: "reject", message: "..."} to refuse.
 *
 * Brute-force defense: after N failed verifies in the rolling window
 * (configurable in partna.mfa.*), we reject further attempts and record
 * the rejection so subsequent window queries keep flagging the user as
 * in-cooldown.
 *
 * Signature verification is handled by the supabase.auth-hook middleware
 * — this controller only runs for requests that have already been authenticated.
 */
class SupabaseAuthHookController extends Controller
{
    public function __construct(
        private readonly AuthFactorEventRepository $repo,
    ) {}

    public function mfaVerification(Request $request): JsonResponse
    {
        $id = (string) $request->header('webhook-id', '');

        // WHK-2: fail closed when the idempotency key is absent. The signature
        // middleware already rejects an empty webhook-id, but the controller must
        // not depend on that — without an id we cannot dedup retries, so the only
        // safe behaviour is to refuse the delivery rather than process it blindly.
        if ($id === '') {
            return response()->json(['message' => 'Missing webhook-id'], 400);
        }

        // WEBHOOK-3: dedup retried hook deliveries. Without this, a redelivered
        // verification announcement double-records the auth-factor event —
        // inflating the brute-force failure counter (or the success log). On a
        // duplicate we return the permissive decision: the first delivery
        // already recorded the outcome, and Supabase acts on whichever response
        // reaches it first. Runs AFTER signature verification so an unsigned
        // caller cannot probe which webhook IDs have been seen.
        if (! Cache::add(
            "supabase:auth-hook:{$id}",
            true,
            (int) config('partna.cache.ttls.webhook_idempotency'),
        )) {
            return response()->json(['decision' => 'continue']);
        }

        // WHK-1: the dedup anchor above is set BEFORE any repository work. If a
        // record()/countRecentFailures() call throws (DB outage, pool exhaustion),
        // the anchor must be reverted — otherwise Supabase's mandatory retry hits
        // Cache::add → false and short-circuits to {decision: continue}, silently
        // converting a brute-force *reject* into an *allow*. The try wraps the
        // whole body; deterministic 400 returns inside it exit normally (the
        // anchor stays, which is correct — a retry of malformed input is benign).
        try {
            $payload = $request->json()->all();
            $userId = (string) ($payload['user_id'] ?? '');
            $factorId = (string) ($payload['factor_id'] ?? '');
            $factorType = $payload['factor_type'] ?? null;
            $valid = (bool) ($payload['valid'] ?? false);

            $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
            if (! preg_match($uuidPattern, $userId) || ! preg_match($uuidPattern, $factorId)) {
                return response()->json(['message' => 'Malformed payload'], 400);
            }

            // Sanitize factor_type against the DB CHECK constraint allowlist.
            $allowedFactorTypes = ['totp', 'phone', 'webauthn', 'recovery'];
            if (! in_array($factorType, $allowedFactorTypes, true)) {
                $factorType = null;
            }

            $ip = $request->ip();
            $userAgent = (string) $request->userAgent();

            if ($valid) {
                $this->repo->record(
                    userId: $userId,
                    eventType: 'verify_success',
                    factorId: $factorId,
                    factorType: $factorType,
                    ip: $ip,
                    userAgent: $userAgent,
                );

                return response()->json(['decision' => 'continue']);
            }

            // Failed verify path.
            $maxFailures = (int) config('partna.mfa.verify_max_failures', 5);
            $windowSeconds = (int) config('partna.mfa.verify_failure_window_seconds', 300);

            $recentFailures = $this->repo->countRecentFailures($userId, $factorId, $windowSeconds);

            if ($recentFailures >= $maxFailures) {
                $this->repo->record(
                    userId: $userId,
                    eventType: 'verify_rejected_by_hook',
                    factorId: $factorId,
                    factorType: $factorType,
                    ip: $ip,
                    userAgent: $userAgent,
                    metadata: ['recent_failures' => $recentFailures, 'window_seconds' => $windowSeconds],
                );

                return response()->json([
                    'decision' => 'reject',
                    'message' => 'Too many failed verification attempts. Try again in '.ceil($windowSeconds / 60).' minutes.',
                ]);
            }

            $this->repo->record(
                userId: $userId,
                eventType: 'verify_failed',
                factorId: $factorId,
                factorType: $factorType,
                ip: $ip,
                userAgent: $userAgent,
            );

            return response()->json(['decision' => 'continue']);
        } catch (\Throwable $e) {
            // Revert the idempotency anchor so the retry re-evaluates instead of
            // replaying a permissive {decision: continue}. Re-throw so Nightwatch
            // captures the underlying failure (the exception is the alert signal;
            // the log line is structured breadcrumb context).
            Cache::forget("supabase:auth-hook:{$id}");

            Log::error('supabase.auth_hook.record_failed', [
                'webhook_id' => $id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
