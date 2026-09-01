<?php

namespace App\Http\Middleware\Context;

use App\Models\Core\Staff\PartnaStaff;
use App\Services\Cache\UserCacheService;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// V2: Loads authenticated professional into request context via cache. Rejects suspended/missing accounts.
class LoadCurrentUser
{
    /**
     * Opt-in mode for the ONE route that must survive a staff-only session:
     * GET /api/me, the dashboard's boot call. Passed as a middleware parameter
     * (`current.pro:staff_session_ok`) rather than being the default, because
     * every OTHER user route reads `professional` off the request — directly in
     * ~6 controllers, via ResolveCurrentUser everywhere else — and letting a
     * profile-less session through to those would turn a clean 403 into a 500.
     */
    public const MODE_STAFF_SESSION_OK = 'staff_session_ok';

    public function __construct(
        private UserCacheService $userCache
    ) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $uid = $request->attributes->get('supabase_uid');
        if (! $uid) {
            Log::debug('LoadCurrentUser missing uid', ['uid' => $uid]);

            return response()->json(['message' => 'Missing uid'], 401);
        }

        // Supabase sub claim is always a UUID; any non-UUID string indicates a routing/middleware misconfiguration.
        if (! Str::isUuid($uid)) {
            Log::warning('LoadCurrentUser invalid uid format', ['uid' => $uid]);

            return response()->json(['message' => 'Invalid uid'], 401);
        }

        // Use cache service instead of a direct query
        $professional = $this->userCache->getByAuthId($uid);

        if (! $professional) {
            return $this->handleMissingProfile($request, $next, (string) $uid, $mode);
        }

        $status = $professional->status ?? 'active';
        if (! in_array($status, ['active', 'pending_deletion'], true)) {
            Log::debug('LoadCurrentUser blocked account', [
                'uid' => $uid,
                'status' => $status,
            ]);

            return response()->json([
                'message' => 'Your account is not active. Contact support.',
            ], 403);
        }

        // Passive sync of primary_email from the verified Supabase JWT claims.
        // The token already carries the current email — no extra network/db cost
        // on the happy path (one strcasecmp). UPDATE fires only on actual drift,
        // which is a rare lifetime event per user. Only honoured for verified
        // emails to avoid an unverified secondary identity from poisoning the row.
        $this->syncEmailFromClaims($request, $professional);

        $request->attributes->set('professional', $professional);

        // Tag Nightwatch records with tenant identity. The full Context blob is
        // serialized into every request/job/exception record (RecordsContext trait),
        // so these become searchable filters in the dashboard without extra plumbing.
        // No DB cost: $professional is already loaded above.
        Context::add([
            'user_id' => (string) $professional->id,
            'account_type' => (string) ($professional->account_type?->value ?? ''),
        ]);

        return $next($request);
    }

    /**
     * A verified auth user with no core.users row is one of two very different
     * people, and answering both with `bootstrap_required` is what produced the
     * incident this method exists for.
     *
     * A STAFF member is not a half-finished professional. Staff UX used to be a
     * USER session with a staff overlay: /me required a core.users row, so the
     * only way a staff member could boot the dashboard at all was to hold one —
     * which meant being treated as a professional who owns a public sitepage.
     * `bootstrap_required` is the frontend's cue to route the caller back into
     * signup, so a staff-only session was pushed to mint exactly the row the
     * owner ruled must never exist (2026-09-01). Staff sessions are first-class
     * now: on the boot route they get a session envelope, and everywhere else a
     * distinct 403 the dashboard must NOT translate into "finish signing up".
     *
     * The genuine bail-out-mid-signup case is unchanged — same code, same
     * status, same message.
     */
    private function handleMissingProfile(Request $request, Closure $next, string $uid, ?string $mode): Response
    {
        $staff = PartnaStaff::query()->where('auth_user_id', $uid)->first();

        if (! $staff) {
            // Verified auth user with no Partna profile — they bailed mid-signup
            // (closed the tab between supabase.auth.signUp and /api/bootstrap).
            // Surface a structured error code so the frontend can route them
            // back into the sign-up flow at the "about" step (resume=1) and
            // finish the bootstrap with their existing session.
            Log::debug('LoadCurrentUser no professional for uid', ['uid' => $uid]);

            return response()->json([
                'error' => 'bootstrap_required',
                'message' => 'Finish setting up your Partna account.',
            ], 403);
        }

        if ($mode !== self::MODE_STAFF_SESSION_OK) {
            Log::info('LoadCurrentUser staff-only session on a professional route', [
                'uid' => $uid,
                'staff_id' => (string) $staff->id,
                'route' => $request->path(),
            ]);

            return response()->json([
                'error' => 'staff_only_session',
                'message' => 'This is a staff session. It has no professional profile.',
            ], 403);
        }

        $request->attributes->set('partna_staff', $staff);
        $request->attributes->set('staff_only_session', true);

        // Tag Nightwatch with the actor we DO have. The professional branch adds
        // user_id/account_type below; a staff-only request has neither, and an
        // untagged record is indistinguishable from an unauthenticated one.
        Context::add([
            'staff_id' => (string) $staff->id,
            'staff_role' => (string) $staff->role,
        ]);

        return $next($request);
    }

    /**
     * Reconcile professionals.primary_email with the verified email claim from
     * the Supabase JWT. Catches unique-index collisions explicitly so a user
     * whose Google email now matches another Partna account doesn't 500 — the
     * old email stays, the collision is logged, the request still succeeds.
     */
    private function syncEmailFromClaims(Request $request, $professional): void
    {
        $claims = $request->attributes->get('supabase_claims');
        if (! is_array($claims)) {
            return;
        }

        $claimedEmail = $claims['email'] ?? null;
        $emailVerified = (bool) ($claims['email_verified'] ?? false);

        if (! is_string($claimedEmail) || $claimedEmail === '' || ! $emailVerified) {
            return;
        }

        $current = (string) ($professional->primary_email ?? '');
        if (strcasecmp($claimedEmail, $current) === 0) {
            return;
        }

        // DINT-2: this read (the strcasecmp above) and this write are not
        // wrapped in a transaction or a conditional `UPDATE ... WHERE
        // primary_email = ?`. The only realistic race is the same user firing
        // two concurrent requests carrying two different currently-valid
        // verified emails (e.g. multi-tab re-login) — a narrow window with no
        // cross-user exposure. The UniqueConstraintViolationException catch
        // below already prevents the outcome that actually matters (silently
        // taking over another user's email row); worst case here is a lost
        // update that self-heals on the next request against the JWT's
        // current claim. Not worth a conditional UPDATE for that edge case.
        try {
            $professional->primary_email = $claimedEmail;
            $professional->save();
        } catch (UniqueConstraintViolationException $e) {
            // Hash before logging — Nightwatch records must never carry a raw
            // email. Same HMAC-SHA256 scheme as SupabaseEmailEventService::hashEmail
            // (app.key as pepper, lowercased input) so it's consistent/correlatable
            // with other email hashes in the codebase and never reversible.
            Log::warning('LoadCurrentUser email sync collision', [
                'user_id' => (string) $professional->id,
                'attempted_email_hash' => hash_hmac('sha256', strtolower($claimedEmail), config('app.key')),
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        // LIFE-7: separate try/catch from the save() above — a cache blip here
        // must not 500 a request whose DB write already committed. Narrow
        // UniqueConstraintViolationException handling above stays untouched;
        // this is deliberately \Throwable since invalidateUser()'s failure
        // modes (Redis down, etc.) aren't a single known exception type.
        try {
            $this->userCache->invalidateUser($professional);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
