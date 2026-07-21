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
 * (configurable in partna.mfa.*), we return {decision: "reject"} and record
 * the rejection so subsequent window queries keep flagging the user as
 * in-cooldown. Note that a plain "continue" on a failed attempt already
 * fails that attempt — that's Supabase's own default behaviour for
 * {valid: false}. What "reject" actually adds is denying the attempt AND
 * logging the user out of all active sessions.
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
        // duplicate we replay the decision that was ACTUALLY made on the first
        // delivery (see replayDecision()) rather than assuming 'continue' — a
        // lost reject response must not be promoted to an allow just because
        // Supabase retried. Runs AFTER signature verification so an unsigned
        // caller cannot probe which webhook IDs have been seen.
        //
        // WHK-101: this Cache::add anchor is the fast path; the partial unique
        // index auth_factor_events_webhook_id_uk (audit.auth_factor_events,
        // via $id passed into every record() call below) is the durable backstop
        // for when Redis has already forgotten the key — and also what
        // replayDecision() reads to recover the original decision.
        if (! Cache::add(
            "supabase:auth-hook:{$id}",
            true,
            (int) config('partna.cache.ttls.webhook_idempotency', 86_400),
        )) {
            $replay = $this->replayDecision($id);
            if ($replay !== null) {
                Log::info('supabase.auth_hook.decision_replayed', [
                    'webhook_id' => $id, 'decision' => $replay['decision'],
                ]);

                return response()->json($replay);
            }
            // No durable record of a prior decision — do NOT assume 'continue'.
            // Fall through and recompute. record() is idempotent on webhook_id
            // (auth_factor_events_webhook_id_uk), so recomputation cannot double-record.
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
                    webhookId: $id,
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
                    webhookId: $id,
                );

                return response()->json([
                    'decision' => 'reject',
                    'message' => $this->lockoutMessage($windowSeconds),
                ]);
            }

            $this->repo->record(
                userId: $userId,
                eventType: 'verify_failed',
                factorId: $factorId,
                factorType: $factorType,
                ip: $ip,
                userAgent: $userAgent,
                webhookId: $id,
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

    /**
     * Resolve what was actually decided for a previously-seen webhook-id, so a
     * redelivery replays the ORIGINAL decision instead of assuming 'continue'.
     * Returns null when there's no durable record to replay — DB lookup
     * failure, or an event_type this hook never produced — and the caller
     * falls through to recompute rather than guess.
     *
     * @return array{decision: string, message?: string}|null
     */
    private function replayDecision(string $id): ?array
    {
        try {
            $row = $this->repo->findByWebhookId($id);
        } catch (\Throwable $e) {
            Log::warning('supabase.auth_hook.replay_lookup_failed', [
                'webhook_id' => $id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($row === null) {
            return null;
        }

        if ($row->event_type === 'verify_success' || $row->event_type === 'verify_failed') {
            return ['decision' => 'continue'];
        }

        if ($row->event_type !== 'verify_rejected_by_hook') {
            return null;
        }

        // metadata is jsonb in Postgres (the pgsql driver hands it back as a
        // JSON string, not pre-decoded) and TEXT in the SQLite test schema —
        // decode defensively either way and guard a malformed/non-array result.
        $metadata = json_decode((string) ($row->metadata ?? '{}'), true);
        $windowSeconds = is_array($metadata) && isset($metadata['window_seconds'])
            ? (int) $metadata['window_seconds']
            : (int) config('partna.mfa.verify_failure_window_seconds', 300);

        return [
            'decision' => 'reject',
            'message' => $this->lockoutMessage($windowSeconds),
        ];
    }

    /** Byte-identical lockout copy for both the original reject and any replay of it. */
    private function lockoutMessage(int $windowSeconds): string
    {
        return 'Too many failed verification attempts. Try again in '.ceil($windowSeconds / 60).' minutes.';
    }
}
