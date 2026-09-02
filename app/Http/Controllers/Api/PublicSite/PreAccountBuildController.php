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
use App\Services\Profile\SectorTaxonomy;
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
        // build (spec §5.4)…
        //
        // …EXCEPT a live SIGN-UP build (A.8, owner decision U28): the flow's
        // resume path re-POSTs the same handle after a lost draft and must
        // get a working token back, or the person is locked out of their own
        // signup. issue() ROTATES the hash, so a squatter re-POSTing a
        // victim's handle invalidates the victim's token rather than sharing
        // it — the victim's own resume mints a fresh one the same way.
        // Staff/outreach builds keep mint-once (reissue is staff-only).
        $claimToken = null;
        if (! $result['reused']) {
            $claimToken = $this->tokens->issue($result['build']);
        } elseif ($result['build']->built_via === PreAccountBuild::VIA_SIGNUP && $result['build']->claimed_at === null) {
            $claimToken = $this->tokens->issue($result['build']);
        }

        return $this->success(
            (new PreAccountBuildCreatedResource($result['build'], $claimToken, (bool) $result['reused']))->resolve(),
            $result['reused'] ? 200 : 202,
        );
    }

    // GET /api/public/signup/sector-options — the industry step's picker
    // (B.2). The taxonomy is static and carries no user data, but the step is
    // only reachable authenticated, so it sits behind the same supabase.jwt
    // gate as prefill: /profile/sector-options can't serve it (that route
    // needs a core.users row, which doesn't exist until the claim commits).
    public function sectorOptions(): JsonResponse
    {
        return $this->success(['groups' => SectorTaxonomy::all()]);
    }

    // GET /api/public/signup/builds/{build}/prefill — the sign-up flow's name
    // step pre-fill (A.9/B.2, wire §8). JWT-gated (same middleware as claim):
    // the anonymous poll must never carry a person's name. 404 for a claimed
    // build — its person is past the flow.
    public function prefill(PreAccountBuild $build): JsonResponse
    {
        if ($build->claimed_at !== null) {
            return $this->error('Not found.', 404);
        }

        $build->loadMissing('user.site');
        $user = $build->user;
        if ($user === null) {
            return $this->error('Not found.', 404);
        }

        return $this->success([
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'display_name' => $user->display_name,
            'handle' => $user->site?->subdomain,
            'sector' => $user->sector,
        ]);
    }

    // GET /api/public/signup/builds/{build} — opaque-UUID poll; 404-not-403 on
    // anything unknown (public enumeration standard). Route-model binding
    // (whereUuid + the app's global ModelNotFoundException→404 handler)
    // covers both an unknown UUID and a non-UUID path segment.
    public function show(PreAccountBuild $build): JsonResponse
    {
        $build->loadMissing('user.site');

        // 9h: the poll is the one reader every settling build has — let it
        // stamp the post-ready tiers (content_filled/enriched) the first time
        // it sees them and emit the per-tier timing telemetry.
        $build->observeTierMarkers();

        return $this->success((new PreAccountBuildStatusResource($build))->resolve());
    }
}
