<?php

namespace App\Http\Middleware\Auth;

use App\Exceptions\Auth\RevocationUnverifiableException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
 * ORDERING. Pinned in bootstrap/app.php's priority list, immediately after
 * VerifySupabaseJwt (whose attribute it depends on) and ahead of IdempotencyKey
 * and ThrottleRequests. It was UNLISTED until 2026-08-05, which left it last;
 * drill 03 measured the cost — during a Redis outage the rate limiter 503'd every
 * strict route first and this gate never ran, so the protection in production was
 * the limiter's and only accidentally so. An invalid or absent token still 401s
 * from the verifier and never reaches this class. All three orderings are pinned
 * by tests/Feature/Auth/StrictRevocationTest.php rather than left to trust.
 *
 * Two knock-ons on staff routes, where this now runs ahead of `require.aal2`,
 * `staff` and `SubstituteBindings`:
 *
 *   - A non-AAL2 staff session gets 503 during an outage rather than 401
 *     `mfa_required`. Correct — see RevocationUnverifiableException for why a 401
 *     during a store outage is a lie with a harmful consequence.
 *
 *   - A blocked staff request STILL WRITES an audit row, with exactly two fields
 *     degraded. RecordStaffAuditEntry writes in terminate(), and
 *     Kernel::terminateMiddleware iterates the gathered stack unconditionally —
 *     it has no idea the pipeline short-circuited. But `partna_staff` (set by
 *     EnsurePartnaStaff) and the resolved `{professional}` binding come from
 *     middleware that now run AFTER this one, so:
 *
 *       `staff_id`                     → NULL   ← the real loss: WHO acted
 *       `professional_handle_snapshot` → null
 *       `user_id`                      → preserved, via RecordStaffAuditEntry's
 *                                        raw-string fallback (:80-91)
 *       route, method, status (503), payload_summary, ip, user_agent → intact
 *
 *     So the row says what was attempted against whom, but not by which staff
 *     member. Worth knowing before an incident rather than during one.
 *     (Two earlier revisions of this docblock got this wrong in both directions —
 *     first claiming no row is written, then that the row is anonymous.)
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
        // the strict list is drawn in the right place. uid is null on the
        // absent-attribute (bypass) branch, where no identity was ever resolved.
        Log::warning('auth.revocation_unverified_on_strict_route', [
            'path' => $request->path(),
            'method' => $request->method(),
            'operation' => __METHOD__,
            'uid' => $request->attributes->get('supabase_uid'),
        ]);

        // Log::warning is breadcrumb-only in this app — it does NOT reach
        // Nightwatch. Every staff route in the product failing closed is an
        // incident someone needs to be paged about, not something to discover
        // later in a log search, so surface it with report() the same way
        // VerifySupabaseJwt::jwksOutage() surfaces a JWKS outage.
        //
        // Throttled to one report per minute: a sustained Redis outage would
        // otherwise flood the exception pipeline with one report per request.
        // Uses the `cache_locks` store for the same reason jwksOutage() does —
        // it is isolated from data-cache flushes. Wrapped in try/catch because
        // the throttle store is Redis too, and during exactly the outage this
        // fires on it is likely unreachable; a broken throttle must never turn
        // a clean 503 into a 500.
        try {
            if (Cache::store('cache_locks')->add('auth:revocation-unverified-reported', true, 60)) {
                report(new RevocationUnverifiableException);
            }
        } catch (\Throwable) {
            // Throttle layer unreachable — swallow. The Log line above still
            // carries the breadcrumb, and the 503 below is unaffected.
        }

        throw new RevocationUnverifiableException;
    }
}
