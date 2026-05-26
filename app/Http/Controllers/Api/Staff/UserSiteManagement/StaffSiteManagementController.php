<?php

namespace App\Http\Controllers\Api\Staff\UserSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\UserSite\StaffUpdateSiteRequest;
use App\Http\Resources\SiteResource;
use App\Models\Core\User\User;
use App\Services\Site\UpdateSiteAction;
use Illuminate\Http\JsonResponse;

// V2: Staff updates site settings with force-publish override capability.
class StaffSiteManagementController extends ApiController
{

    public function update(StaffUpdateSiteRequest $request, User $professional, UpdateSiteAction $action): JsonResponse
    {
        $site = $action->execute(
            $professional,
            $request->validated(),
            [
                'allow_force_publish' => true,
                'allow_subdomain_override' => true,
            ]
        );

        return $this->success(['site' => new SiteResource($site)]);
    }
}
