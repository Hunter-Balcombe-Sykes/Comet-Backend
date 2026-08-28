<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\PublicSite\CreatePreAccountBuildRequest;
use App\Http\Resources\PreAccountBuildCreatedResource;
use App\Http\Resources\PreAccountBuildStatusResource;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\ClaimTokenIssuer;
use App\Services\PreAccount\PreAccountBuildException;
use App\Services\PreAccount\PreAccountBuildService;
use Illuminate\Http\JsonResponse;

// Public, unauthenticated pre-account (site-first signup) build + poll
// endpoints. Heavily throttled — a build kicks off real scraping (Apify-billed).
class PreAccountBuildController extends ApiController
{
    public function __construct(
        private readonly PreAccountBuildService $builds,
        private readonly ClaimTokenIssuer $tokens,
    ) {}

    // POST /api/public/signup/build
    public function store(CreatePreAccountBuildRequest $request): JsonResponse
    {
        // Waitlist gate relocated from the retired bootstrap create branch (F5).
        // $extra (not $errors) is required to put `code` at the TOP level of
        // the response body — matching the frontend's discriminator contract
        // (see ApiController::error() docblock + MfaController/StaffCaseController).
        if ((bool) config('partna.waitlist.enabled', false)) {
            return $this->error('New account creation is currently waitlist-only.', 403, [], ['code' => 'WAITLIST_ONLY']);
        }

        $data = $request->validated();
        $ip = $request->header('CF-Connecting-IP') ?: $request->ip();

        try {
            $result = $this->builds->requestBuild(
                accountType: $data['account_type'],
                sourceType: $data['source_type'],
                rawSourceRef: $data['source_ref'],
                sourceName: $data['source_name'] ?? null,
                // PRIV-3: HMAC, not a bare digest — see PreAccountBuild::hashIp().
                ipHash: PreAccountBuild::hashIp((string) $ip),
            );
        } catch (PreAccountBuildException $e) {
            $status = $e->errorCode === PreAccountBuildException::IP_BUILD_CAP ? 429 : 422;

            return $this->error($e->getMessage(), $status, [], ['code' => $e->errorCode]);
        }

        $result['build']->loadMissing('user.site');

        // #W2-SEC-1: mint ONLY for a NEW build, never on the dedupe/re-serve
        // path — minting there would let anyone who POSTs a guessable
        // source_ref fetch a working takeover capability for someone else's
        // build (spec §5.4). A caller who lost this token on a genuinely new
        // build has no self-serve recovery here by design; the only reissue
        // path is staff, POST /api/staff/builds/{build}/claim-token.
        $claimToken = $result['reused'] ? null : $this->tokens->issue($result['build']);

        return $this->success(
            (new PreAccountBuildCreatedResource($result['build'], $claimToken))->resolve(),
            $result['reused'] ? 200 : 202,
        );
    }

    // GET /api/public/signup/builds/{build} — opaque-UUID poll; 404-not-403 on
    // anything unknown (public enumeration standard). Route-model binding
    // (whereUuid + the app's global ModelNotFoundException→404 handler)
    // covers both an unknown UUID and a non-UUID path segment.
    public function show(PreAccountBuild $build): JsonResponse
    {
        $build->loadMissing('user.site');

        return $this->success((new PreAccountBuildStatusResource($build))->resolve());
    }
}
