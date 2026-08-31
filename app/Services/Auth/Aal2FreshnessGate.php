<?php

namespace App\Services\Auth;

use Illuminate\Auth\Access\Response;
use Illuminate\Http\Request;

/**
 * Single source of truth for the "fresh AAL2" check — was the user's most recent
 * MFA verification inside $maxAgeSeconds?
 *
 * AAL stays sticky at aal2 for the life of a Supabase session (it is NOT
 * downgraded on token refresh), so session-level aal2 alone cannot answer
 * "verified recently". We inspect the amr timeline instead: scan every entry,
 * take the max MFA-method timestamp, and compare to now. The scan is
 * order-independent — correct whether Supabase emits amr oldest- or newest-first.
 *
 * SECURITY-SENSITIVE: this gate decides whether high-risk actions proceed — MFA
 * unenroll (MfaController), staff suspend / bulk-suspend / update / archive /
 * restore / force-delete / release-claim (StaffUserController), and the
 * flag-gated profile self-mutation (UserSelfPolicy via BasePolicy). These call
 * sites previously each carried a byte-identical copy of this logic; they now
 * all delegate here so the MFA-method allowlist can never drift between them.
 * Changing the allowlist or the comparison changes the security posture of ALL
 * consumers at once — by design.
 */
class Aal2FreshnessGate
{
    /**
     * @param  Request  $request  Carries the verified `supabase_amr` attribute:
     *                            an array of ['method' => string, 'timestamp' => int].
     * @param  int  $maxAgeSeconds  Freshness window. Each call site owns its own
     *                              default (staff/profile window vs the unenroll
     *                              window) and passes the resolved value here.
     */
    public function check(Request $request, int $maxAgeSeconds): Response
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
            return Response::denyWithStatus(401, 'Recent MFA verification required');
        }

        return (time() - $mostRecentMfaTs) <= $maxAgeSeconds
            ? Response::allow()
            : Response::denyWithStatus(401, 'Recent MFA verification required');
    }
}
