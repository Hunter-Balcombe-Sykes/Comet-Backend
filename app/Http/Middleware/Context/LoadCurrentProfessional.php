<?php

namespace App\Http\Middleware\Context;

use App\Services\Cache\ProfessionalCacheService;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// V2: Loads authenticated professional into request context via cache. Rejects suspended/missing accounts.
class LoadCurrentProfessional
{
    public function __construct(
        private ProfessionalCacheService $professionalCache
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $uid = $request->attributes->get('supabase_uid');
        if (! $uid) {
            Log::debug('LoadCurrentProfessional missing uid');

            return response()->json(['message' => 'Missing uid'], 401);
        }

        // Supabase sub claim is always a UUID; any non-UUID string indicates a routing/middleware misconfiguration.
        if (! Str::isUuid($uid)) {
            Log::warning('LoadCurrentProfessional invalid uid format', ['uid' => $uid]);

            return response()->json(['message' => 'Invalid uid'], 401);
        }

        // Use cache service instead of a direct query
        $professional = $this->professionalCache->getByAuthId($uid);

        if (! $professional) {
            // Verified auth user with no Partna profile — they bailed mid-signup
            // (closed the tab between supabase.auth.signUp and /api/bootstrap).
            // Surface a structured error code so the frontend can route them
            // back into the sign-up flow at the "about" step (resume=1) and
            // finish the bootstrap with their existing session.
            Log::debug('LoadCurrentProfessional no professional for uid', ['uid' => $uid]);

            return response()->json([
                'error' => 'bootstrap_required',
                'message' => 'Finish setting up your Partna account.',
            ], 403);
        }

        $status = $professional->status ?? 'active';
        if (! in_array($status, ['active', 'pending_deletion'], true)) {
            Log::debug('LoadCurrentProfessional blocked account', [
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
        // Tag with both columns during the dual-write window. account_type is
        // the source of truth for Phase 1 onward — Nightwatch dashboards
        // should filter on it. professional_type kept until §28.13 finishes
        // the read-migration sweep.
        Context::add([
            'professional_id' => (string) $professional->id,
            'professional_type' => (string) ($professional->professional_type ?? ''),
            'account_type' => (string) ($professional->account_type?->value ?? ''),
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

        try {
            $professional->primary_email = $claimedEmail;
            $professional->save();
            $this->professionalCache->invalidateProfessional($professional);
        } catch (UniqueConstraintViolationException $e) {
            Log::warning('LoadCurrentProfessional email sync collision', [
                'professional_id' => (string) $professional->id,
                'attempted_email' => $claimedEmail,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
