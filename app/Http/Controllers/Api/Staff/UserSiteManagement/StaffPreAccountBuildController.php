<?php

namespace App\Http\Controllers\Api\Staff\UserSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\UserSite\StaffCreatePreAccountBuildRequest;
use App\Http\Resources\PreAccountBuildStatusResource;
use App\Models\Core\User\PreAccountBuild;
use App\Services\PreAccount\PreAccountBuildException;
use App\Services\PreAccount\PreAccountBuildService;
use Illuminate\Http\JsonResponse;

class StaffPreAccountBuildController extends ApiController
{
    public function __construct(private readonly PreAccountBuildService $builds) {}

    // POST /api/staff/builds — the ManyChat/marketing surface. Builds publish by
    // default (the site IS the pitch); the public endpoint never publishes pre-claim.
    public function store(StaffCreatePreAccountBuildRequest $request): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffCreate', PreAccountBuild::class);

        $data = $request->validated();

        try {
            $result = $this->builds->requestBuild(
                accountType: $data['account_type'],
                sourceType: $data['source_type'],
                rawSourceRef: $data['source_ref'],
                sourceName: $data['source_name'] ?? null,
                ipHash: null,
                staff: $staff,
                publish: (bool) ($data['publish'] ?? true),
                expiresDays: isset($data['expires_days']) ? (int) $data['expires_days'] : null,
                contactEmail: $data['contact_email'] ?? null,
            );
        } catch (PreAccountBuildException $e) {
            // The staff surface has no waitlist/IP-cap paths (requestBuild skips
            // the cap entirely when $staff is set) — every thrown code here is a
            // bad source/pairing, so a flat 422 (unlike the public controller's
            // cap-vs-pairing status split) is correct.
            return $this->error($e->getMessage(), 422, [], ['code' => $e->errorCode]);
        }

        $result['build']->loadMissing('user.site');

        return $this->success(
            (new PreAccountBuildStatusResource($result['build']))->resolve(),
            $result['reused'] ? 200 : 202,
        );
    }
}
