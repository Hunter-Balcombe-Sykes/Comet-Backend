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
        return $this->success([
            'uid' => $request->attributes->get('supabase_uid'),
            'staff' => new PartnaStaffResource($request->attributes->get('partna_staff')),
        ]);
    }
}
