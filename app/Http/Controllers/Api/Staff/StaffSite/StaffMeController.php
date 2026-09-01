<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\Staff\UpdateStaffMeRequest;
use App\Http\Resources\Staff\PartnaStaffResource;
use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Http\Request;

// V2: Returns authenticated staff member's UID and profile data. Used by staff dashboard for session context.
class StaffMeController extends ApiController
{
    public function show(Request $request)
    {
        // #SEC-6 (P3): consistency-only — the resource IS the actor, so there's
        // no privilege-escalation path either way. PartnaStaffPolicy::view
        // already allows a staff member to view their own record.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'view', $staff);

        return $this->success([
            'uid' => $request->attributes->get('supabase_uid'),
            'staff' => new PartnaStaffResource($staff),
        ]);
    }

    // Wave 8 (2026-09-02): the staff settings page's Account card. The
    // `updateOwnProfile` ability, not `update` — that one forbids self-edits
    // so role changes need a second actor, and this path must not weaken it.
    // The request class is the allowlist: name only.
    public function update(UpdateStaffMeRequest $request)
    {
        /** @var PartnaStaff $staff */
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'updateOwnProfile', $staff);

        $staff->fill(['name' => $request->validated('name')])->save();

        return $this->success([
            'uid' => $request->attributes->get('supabase_uid'),
            'staff' => new PartnaStaffResource($staff->fresh()),
        ]);
    }
}
