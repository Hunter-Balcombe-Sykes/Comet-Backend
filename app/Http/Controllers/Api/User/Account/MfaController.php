<?php

namespace App\Http\Controllers\Api\User\Account;

use App\Http\Controllers\Api\ApiController;
use App\Mail\Security\TwoFactorRemovedMail;
use App\Models\Core\User\User;
use App\Services\Auth\Aal2FreshnessGate;
use App\Services\Auth\AuthFactorEventRepository;
use App\Services\Auth\SupabaseAdminService;
use Illuminate\Auth\Access\Response as GateResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

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

        // Security notice — fire-and-forget: a mail failure must never fail
        // the factor removal itself. auth_user_id, not id — $uid is the
        // Supabase auth id, not our primary key.
        try {
            $professional = User::query()->where('auth_user_id', $uid)->first();
            $email = (string) ($professional?->primary_email ?? '');
            if ($email !== '') {
                Mail::to($email)->queue(new TwoFactorRemovedMail($email, $professional?->display_name));
            }
        } catch (\Throwable $e) {
            Log::warning('mfa.factor_removed_mail_failed', ['auth_user_id' => $uid, 'error' => $e->getMessage()]);
        }

        return $this->success(['ok' => true]);
    }

    /**
     * Fresh-AAL2 gate for the unenroll endpoint. Kept as a local wrapper (not
     * delegated to a policy) because there is no model to authorize against — the
     * factor lives in Supabase, not our DB; see destroy(). The actual scan is the
     * shared Aal2FreshnessGate, so this path can no longer drift from BasePolicy.
     */
    private function requiresFreshAal2(Request $request, int $maxAgeSeconds): GateResponse
    {
        return app(Aal2FreshnessGate::class)->check($request, $maxAgeSeconds);
    }
}
