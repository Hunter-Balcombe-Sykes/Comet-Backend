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
        // #SEC-5: a mutating admin action (staff rename with force-publish +
        // subdomain override). Gate on staffManage (admin-only), matching every
        // sibling staff mutation — co-locating the admin check here so it holds
        // even if the staff.admin route middleware is ever removed/misconfigured.
        $this->authorizeForUser($staff, 'staffManage', $professional);

        $site = $action->execute(
            $professional,
            $request->validated(),
            [
                'allow_force_publish' => true,
                'allow_subdomain_override' => true,
                'reason' => HandleChangeLog::REASON_STAFF_RENAME,
                'actor_id' => $staff?->id,
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, (int) config('partna.staff.audit_user_agent_max_length', 1024)),
            ]
        );

        return $this->success(['site' => new SiteResource($site)]);
    }
}
