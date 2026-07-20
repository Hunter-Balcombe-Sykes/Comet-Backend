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

        $claims = $request->attributes->get('supabase_claims');
        $verifiedEmail = is_array($claims) ? trim((string) ($claims['email'] ?? '')) : '';
        if ($verifiedEmail === '') {
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
                'BUILD_NOT_READY' => $this->error('This site is still being built.', 409, [], ['code' => 'BUILD_NOT_READY']),
                'ACCOUNT_EXISTS' => $this->error('Your account already has a site.', 409, [], ['code' => 'ACCOUNT_EXISTS']),
                'EMAIL_ALREADY_REGISTERED' => $this->error('This email is already registered.', 409, [], ['code' => 'EMAIL_ALREADY_REGISTERED']),
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
