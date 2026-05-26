<?php

namespace App\Policies;

use App\Models\Core\User\User;
use Illuminate\Auth\Access\Response;

// V2: Base for all auth Policies. Provides the shared pending_deletion read-only
// guard. Concrete Policies call denyIfPendingDeletion() as the first line of any
// ability that mutates state — this mirrors the EnforcePendingDeletionReadOnly
// HTTP middleware so background jobs and console commands get the same gate.
abstract class BasePolicy
{
    /**
     * Returns a 423 deny Response when the actor's account is pending deletion,
     * otherwise null. Caller convention: any write-capable ability returns
     * this result early when non-null.
     */
    protected function denyIfPendingDeletion(User $professional): ?Response
    {
        if ($professional->isPendingDeletion()) {
            return Response::denyWithStatus(423, 'Account is pending deletion.');
        }

        return null;
    }

    /**
     * Deny with a 404 to avoid leaking resource existence to non-owners.
     * Use in policy methods that gate route-bound resources — an actor reaching
     * this point has already submitted a valid UUID, and we don't want to
     * confirm or deny it exists if they don't have access.
     */
    protected function denyAsNotFound(): Response
    {
        return Response::denyAsNotFound('Not found.');
    }

    /**
     * Allow only sessions at AAL2 (passed at least one MFA factor this session).
     * Use for "this action requires MFA but doesn't need re-verification".
     *
     * Returns 401 (not 403) — frontend interprets 401 + a recognizable message
     * as "trigger step-up challenge".
     */
    protected function requiresAal2(): Response
    {
        $aal = request()->attributes->get('supabase_aal', 'aal1');

        return $aal === 'aal2'
            ? Response::allow()
            : Response::denyWithStatus(401, 'MFA required for this action');
    }

    /**
     * "Fresh" AAL2 — was the user's most recent MFA verification inside
     * $maxAgeSeconds? Use for high-risk actions where AAL2 alone is too weak
     * (an attacker on an already-aal2 session could otherwise act freely).
     *
     * AAL stays sticky at aal2 for the life of the session (Supabase doesn't
     * downgrade it on token refresh), so we have to inspect the amr timeline
     * to enforce "verify recently". We scan all entries, take the max
     * MFA-method timestamp, and compare to now — order-independent so we
     * stay correct regardless of whether Supabase emits amr oldest-first or
     * newest-first.
     *
     * @param  int  $maxAgeSeconds  Window. Default in config('partna.mfa.fresh_window_seconds').
     */
    protected function requiresFreshAal2(?int $maxAgeSeconds = null): Response
    {
        $maxAgeSeconds ??= (int) config('partna.mfa.fresh_window_seconds', 300);
        $amr = request()->attributes->get('supabase_amr', []);
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
