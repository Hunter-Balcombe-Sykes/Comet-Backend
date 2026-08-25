<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Internal\ManyChatBuildRequest;
use App\Http\Resources\ManyChatBuildResource;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\ClaimTokenIssuer;
use App\Services\PreAccount\PreAccountBuildException;
use App\Services\PreAccount\PreAccountBuildService;
use Illuminate\Http\JsonResponse;

// POST /api/internal/webhooks/manychat/builds — the ManyChat marketing surface.
//
// Exists because ManyChat cannot call POST /api/staff/builds: that group
// carries require.aal2, and an automation platform cannot do staff MFA.
//
// Pull, not Push (spec §3): we hand back a claim URL and ManyChat sends the DM
// itself from the flow where it already holds the Instagram subscriber.
class ManyChatBuildController extends ApiController
{
    public function __construct(
        private readonly PreAccountBuildService $builds,
        private readonly ClaimTokenIssuer $tokens,
    ) {}

    public function __invoke(ManyChatBuildRequest $request): JsonResponse
    {
        $data = $request->validated();
        $idempotencyKey = (string) $data['idempotency_key'];

        try {
            $result = $this->builds->requestBuild(
                accountType: $data['account_type'],
                sourceType: $data['source_type'],
                rawSourceRef: $data['source_ref'],
                sourceName: $data['source_name'] ?? null,
                ipHash: null,
                staff: null,
                publish: true,
                expiresDays: isset($data['expires_days']) ? (int) $data['expires_days'] : null,
                contactEmail: null,
                // VIA_STAFF with no staff row: an outreach build made FOR a
                // business, so isOutreach() must be true, but no human made it.
                builtVia: PreAccountBuild::VIA_STAFF,
                autoInvite: false,
            );
        } catch (PreAccountBuildException $e) {
            return $this->error($e->getMessage(), 422, [], ['code' => $e->errorCode]);
        }

        $build = $result['build'];
        $build->loadMissing('user.site');

        $subdomain = $build->user?->site?->subdomain;
        if ($subdomain === null) {
            return $this->error('Build has no site.', 409, [], ['code' => 'BUILD_NOT_READY']);
        }

        // Mint for a NEW build, or for a RETRY proving it is the same caller
        // (spec §5.4). On any other deduped call we mint nothing: otherwise a
        // leaked webhook secret could fetch a working capability for a build
        // someone else created, which is the takeover this rule exists to stop.
        $claimUrl = null;
        // The null check looks redundant — hash_equals((string) null, $idempotencyKey)
        // already fails on length. It is redundant ONLY because ManyChatBuildRequest
        // requires idempotency_key, so $idempotencyKey can never be ''. Loosen that
        // rule and hash_equals('', '') is true, making every null-key (self-serve)
        // build mintable — the takeover spec §5.4 exists to stop.
        $isRetryOfOurOwn = $result['reused']
            && $build->claim_idempotency_key !== null
            && hash_equals((string) $build->claim_idempotency_key, $idempotencyKey);

        if (! $result['reused'] || $isRetryOfOurOwn) {
            $build->forceFill(['claim_idempotency_key' => $idempotencyKey])->save();
            $token = $this->tokens->issue($build);
            $claimUrl = rtrim((string) config('app.frontend_url'), '/')
                .'/claim/'.$subdomain
                .'?t='.$token;
        }

        return $this->success(
            (new ManyChatBuildResource($build, (bool) $result['reused'], $claimUrl))->resolve(),
            $result['reused'] ? 200 : 202,
        );
    }
}
