<?php

namespace App\Http\Controllers\Api\User\Account;

use App\Http\Controllers\Api\ApiController;
use App\Services\Auth\AuthFactorEventRepository;
use App\Services\Auth\SupabaseAdminService;
use Illuminate\Auth\Access\Response as GateResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Self-service MFA management for the authenticated user.
 *
 * Today: unenroll a single factor. Enrollment / list / verify all live
 * on the frontend via supabase.auth.mfa.* — we do NOT intermediate
 * those because we never want to handle factor secrets.
 *
 * The unenroll endpoint exists on our backend (not directly via the
 * Supabase JS SDK) so we can enforce a *fresh* AAL2 gate — Supabase
 * only enforces session-level aal2, not "verify within last 60s".
 */
class MfaController extends ApiController
{
    public function __construct(
        private readonly SupabaseAdminService $admin,
        private readonly AuthFactorEventRepository $repo,
    ) {}

    public function destroy(Request $request, string $factorId): JsonResponse
    {
        // Inline fresh-AAL2 gate — not in a policy because there's no
        // model to authorize against (the factor lives in Supabase, not
        // our DB). Same logic as BasePolicy::requiresFreshAal2() but
        // applied here with the unenroll-specific window.
        $window = (int) config('partna.mfa.unenroll_fresh_window_seconds', 60);
        $gate = $this->requiresFreshAal2($request, $window);
        if (! $gate->allowed()) {
            // 'code' => 'mfa_fresh_required' is read by the frontend — preserve exactly.
            return $this->error(
                $gate->message() ?: 'Recent MFA verification required',
                $gate->status() ?? 401,
                [],
                ['code' => 'mfa_fresh_required'],
            );
        }

        $uid = (string) $request->attributes->get('supabase_uid');
        $sessionId = $request->attributes->get('supabase_session_id');

        try {
            $this->admin->unenrollMfaFactor($uid, $factorId);
        } catch (\RuntimeException $e) {
            Log::warning('MFA unenroll failed against Supabase Admin API', [
                'operation' => __METHOD__,
                'user_id' => $uid,
                'factor_id' => $factorId,
                'reason' => $e->getMessage(),
            ]);

            return $this->error('Could not remove factor', 502);
        }

        $this->repo->record(
            userId: $uid,
            eventType: 'unenroll',
            factorId: $factorId,
            sessionId: is_string($sessionId) ? $sessionId : null,
            ip: $request->ip(),
            userAgent: (string) $request->userAgent(),
        );

        return $this->success(['ok' => true]);
    }

    /**
     * Inline copy of BasePolicy::requiresFreshAal2 — see destroy() comment
     * for why it's not delegated to a policy.
     *
     * Uses max(timestamp) across all MFA-method amr entries so the result is
     * order-independent (Supabase emits amr chronologically, oldest-first).
     */
    private function requiresFreshAal2(Request $request, int $maxAgeSeconds): GateResponse
    {
        $amr = $request->attributes->get('supabase_amr', []);
        $mfaMethods = ['totp', 'phone', 'webauthn'];

        $mostRecentMfaTs = null;
        foreach ($amr as $entry) {
            $method = $entry['method'] ?? null;
            if (in_array($method, $mfaMethods, true)) {
                $ts = (int) ($entry['timestamp'] ?? 0);
                if ($mostRecentMfaTs === null || $ts > $mostRecentMfaTs) {
                    $mostRecentMfaTs = $ts;
                }
            }
        }

        if ($mostRecentMfaTs === null) {
            return GateResponse::denyWithStatus(401, 'Recent MFA verification required');
        }

        return (time() - $mostRecentMfaTs) <= $maxAgeSeconds
            ? GateResponse::allow()
            : GateResponse::denyWithStatus(401, 'Recent MFA verification required');
    }
}
