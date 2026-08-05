<?php

namespace App\Http\Middleware\Auth;

use App\Exceptions\Auth\RevocationUnverifiableException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Selective fail-closed revocation. Aliased `revocation.strict`.
 *
 * Partna's default is to fail OPEN when the revocation blocklist is
 * unreachable: a Redis outage must not log every customer out. This middleware
 * inverts that on the small, signed-off set of routes where a revoked session
 * could do irreversible or credential-level damage — account deletion, GDPR
 * export, MFA factor removal, session revocation, and everything under
 * routes/api/staff.php. The list and the reasoning for each *exclusion* live in
 * docs/superpowers/plans/2026-08-05-auth-selective-failclosed-PLAN.md §3.
 *
 * IT MAKES NO REDIS CALLS AND RE-CHECKS NOTHING. VerifySupabaseJwt already
 * asked "is this session revoked?"; all this reads is whether that question got
 * a real answer. Re-running isRevoked() here would double the Redis round-trips
 * on exactly the routes that must stay fast when Redis is sick, and would
 * re-introduce the very timeout this design routes around. Because it only
 * reads a request attribute, this middleware cannot itself fail.
 *
 * ORDERING. Depends on the `supabase_revocation_verified` attribute, so it must
 * run after VerifySupabaseJwt. It is unlisted in bootstrap/app.php's priority
 * list and applied at route level, which places it after the route group's
 * `supabase.jwt`. An invalid or absent token therefore still 401s from the
 * verifier and never reaches this class — pinned by
 * tests/Feature/Auth/StrictRevocationTest.php rather than left to trust.
 *
 * THE DEFAULT IS THE BYPASS DEFENCE. The attribute defaults to false when
 * ABSENT, not just when false. A strict route reachable by some path that skips
 * VerifySupabaseJwt gets 503, not a pass. That makes the fail-safe a property of
 * construction rather than of keeping two route lists in sync — which is the
 * failure mode this whole design exists to avoid.
 *
 * INTERACTION WITH THE REQUEST BREAKER (E). Once RedisRequestBreaker is open,
 * GuardedPhpRedisConnection throws RedisUnavailableException *before* issuing
 * the command, so isRevoked() is skipped rather than merely slow.
 * VerifySupabaseJwt catches that like any other failure and records
 * verified=false — so a strict route hit on an already-degraded request fails
 * CLOSED and does not inherit the breaker's skip. That is the entire point of
 * this middleware and the easiest thing to get wrong, so it is asserted
 * directly by the breaker test rather than inferred from this comment.
 */
class RequireVerifiedRevocation
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('supabase_revocation_verified') === true) {
            return $next($request);
        }

        // Log BEFORE throwing. This is the only telemetry that distinguishes
        // "a customer was blocked from deleting their account during a Redis
        // outage" from a generic 503, and it is the signal that says whether
        // the strict list is drawn in the right place. Deliberately warning,
        // not error: a correctly-working fail-closed gate is not a fault.
        Log::warning('auth.revocation_unverified_on_strict_route', [
            'path' => $request->path(),
            'method' => $request->method(),
            'operation' => __METHOD__,
            // No uid: on the absent-attribute (bypass) branch there is no
            // resolved identity to log, and a half-populated field reads as
            // though the user were known.
            'uid' => $request->attributes->get('supabase_uid'),
        ]);

        throw new RevocationUnverifiableException;
    }
}
