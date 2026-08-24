<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\PublicSite\ClaimSiteRequest;
use App\Http\Resources\UserDashboardResource;
use App\Services\PreAccount\ClaimSiteService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

// Public-site claim endpoint: a Supabase JWT whose sub has no core.users row
// yet claims an unclaimed pre-account site by subdomain (first-come). Same
// OV-A hardening as bootstrap: the email is ONLY ever read from the verified
// JWT claim, never the body.
class ClaimController extends ApiController
{
    public function __construct(private readonly ClaimSiteService $claims) {}

    // POST /api/claim
    public function store(ClaimSiteRequest $request): JsonResponse
    {
        $uid = $request->attributes->get('supabase_uid');
        if (! is_string($uid) || $uid === '') {
            return $this->error('Unauthenticated', 401);
        }

        // SEC-2: an `email` claim is not proof of control — Supabase populates it
        // from signup input before confirmation, so an unconfirmed session can
        // carry an address the caller never proved they own, and claiming binds a
        // real business site to it. Read email_verified from BOTH locations for
        // the same reason RequireEmailVerified does (root vs user_metadata depends
        // on project age + provider). Both failure modes share one 422 because the
        // frontend has one remedy for them — re-run OTP (docs/api.md POST /api/claim).
        $claims = $request->attributes->get('supabase_claims');
        $claims = is_array($claims) ? $claims : [];

        $userMetadata = is_array($claims['user_metadata'] ?? null) ? $claims['user_metadata'] : [];
        $emailVerified = (bool) ($claims['email_verified'] ?? $userMetadata['email_verified'] ?? false);

        $verifiedEmail = trim((string) ($claims['email'] ?? ''));
        if ($verifiedEmail === '' || ! $emailVerified) {
            return $this->error(
                'A verified email is required to claim your site.',
                422,
                [],
                ['code' => 'EMAIL_VERIFICATION_REQUIRED']
            );
        }

        $validated = $request->validated();

        try {
            $result = $this->claims->claim($uid, $verifiedEmail, $validated['subdomain'], (bool) $validated['marketing_opt_in']);
        } catch (RuntimeException $e) {
            // $extra (not $errors) puts `code` at the TOP level of the response
            // body — matching this branch's discriminator contract (see
            // ApiController::error() docblock + PreAccountBuildController).
            return match ($e->getMessage()) {
                'CLAIM_NOT_FOUND' => $this->error('No site found for that address.', 404, [], ['code' => 'CLAIM_NOT_FOUND']),
                'ALREADY_CLAIMED' => $this->error('This site has already been claimed.', 409, [], ['code' => 'ALREADY_CLAIMED']),
                'BUILD_FAILED' => $this->error("We couldn't finish building this site. Please try again.", 409, [], ['code' => 'BUILD_FAILED']),
                'ACCOUNT_EXISTS' => $this->error('Your account already has a site.', 409, [], ['code' => 'ACCOUNT_EXISTS']),
                'EMAIL_ALREADY_REGISTERED' => $this->error('This email is already registered.', 409, [], ['code' => 'EMAIL_ALREADY_REGISTERED']),
                'CLAIM_EMAIL_MISMATCH' => $this->error('This site is reserved for a different email address.', 409, [], ['code' => 'CLAIM_EMAIL_MISMATCH']),
                // Outreach build with no invited address: staff must attach one
                // before anyone can claim it. Deliberately does NOT confirm the
                // site exists in any way a bare 404 wouldn't — it is reachable
                // only by handle, which is public anyway, and it must not become
                // an oracle for "this handle is a staff-built site worth taking".
                'CLAIM_NOT_INVITED' => $this->error(
                    "This site isn't open for claiming yet. If it's yours, reply to the email we sent you or contact support.",
                    409,
                    [],
                    ['code' => 'CLAIM_NOT_INVITED']
                ),
                default => throw $e,
            };
        }

        // Bootstrap-shaped payload so the frontend lands straight in the dashboard
        // (professional via resource; site raw — byte-compatible with /bootstrap).
        return $this->success([
            'professional' => new UserDashboardResource($result['professional']),
            'site' => $result['site'],
        ]);
    }
}
