<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Staff\PartnaStaffResource;
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
}
