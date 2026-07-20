<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Staff\StaffSiteResource;
use App\Models\Core\User\User;
use App\Models\Views\AllSiteData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Staff views site data including unpublished sites. Used by internal staff
// dashboard. Returns the user's content + skeleton choice; the per-user
// design kit is intentionally NOT surfaced here (staff don't edit it).
class StaffSiteController extends ApiController
{
    public function show(Request $request, string $subdomain): JsonResponse
    {
        $row = AllSiteData::query()
            ->whereRaw('lower(subdomain) = lower(?)', [$subdomain])
            ->first();

        if (! $row) {
            return $this->error('Site not found.', 404);
        }

        // #SEC-5: staff-dashboard read surface — any staff role. staffView
        // doesn't inspect the target's fields (always true), so an unsaved
        // skeleton carrying just the id avoids a second query to re-fetch a
        // row the view already joined against core.users. `id` isn't
        // fillable on User, so set it directly rather than via the constructor.
        $staff = $request->attributes->get('partna_staff');
        $professionalSkeleton = new User;
        $professionalSkeleton->id = $row->user_id;
        $this->authorizeForUser($staff, 'staffView', $professionalSkeleton);

        return $this->success(['site' => new StaffSiteResource($row)]);
    }

    public function showByProfessional(Request $request, User $professional): JsonResponse
    {
        // #SEC-5: staff-dashboard read surface — any staff role.
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', $professional);

        $row = AllSiteData::query()
            ->where('user_id', $professional->id)
            ->first();

        if (! $row) {
            return $this->error('Site not found for professional.', 404);
        }

        return $this->success(['site' => new StaffSiteResource($row)]);
    }
}
