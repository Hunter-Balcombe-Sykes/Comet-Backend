<?php

namespace App\Http\Controllers\Api\Staff\ProfessionalSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\ProfessionalSite\StaffUpdateSiteRequest;
use App\Models\Core\Professional\User;
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

        $siteArray = $site->toArray();

        return $this->success(['site' => $siteArray]);
    }
}
