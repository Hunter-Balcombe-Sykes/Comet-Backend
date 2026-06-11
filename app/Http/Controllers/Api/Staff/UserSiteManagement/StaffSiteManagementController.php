<?php

namespace App\Http\Controllers\Api\Staff\UserSiteManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\UserSite\StaffUpdateSiteRequest;
use App\Http\Resources\SiteResource;
use App\Models\Core\HandleChangeLog;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\Site\UpdateSiteAction;
use Illuminate\Http\JsonResponse;

// V2: Staff updates site settings with force-publish override capability.
class StaffSiteManagementController extends ApiController
{
    public function update(StaffUpdateSiteRequest $request, User $professional, UpdateSiteAction $action): JsonResponse
    {
        // Attribute the rename to the acting staff member, not the professional.
        // Without this, a staff rename is audit-logged as a self-serve 'rename' by
        // the user with no IP/UA, defeating the impersonation/fraud trail.
        /** @var PartnaStaff|null $staff */
        $staff = $request->attributes->get('partna_staff');

        $site = $action->execute(
            $professional,
            $request->validated(),
            [
                'allow_force_publish' => true,
                'allow_subdomain_override' => true,
                'reason' => HandleChangeLog::REASON_STAFF_RENAME,
                'actor_id' => $staff?->id,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 1024),
            ]
        );

        return $this->success(['site' => new SiteResource($site)]);
    }
}
