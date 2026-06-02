<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\Staff\StaffSiteResource;
use App\Models\Core\User\User;
use App\Models\Views\AllSiteData;
use Illuminate\Http\JsonResponse;

// Staff views site data including unpublished sites. Used by internal staff
// dashboard. Returns the user's content + skeleton choice; the per-user
// design kit is intentionally NOT surfaced here (staff don't edit it).
class StaffSiteController extends ApiController
{
    public function show(string $subdomain): JsonResponse
    {
        $row = AllSiteData::query()
            ->whereRaw('lower(subdomain) = lower(?)', [$subdomain])
            ->first();

        if (! $row) {
            return $this->error('Site not found.', 404);
        }

        return $this->success(new StaffSiteResource($row));
    }

    public function showByProfessional(User $professional): JsonResponse
    {
        $row = AllSiteData::query()
            ->where('user_id', $professional->id)
            ->first();

        if (! $row) {
            return $this->error('Site not found for professional.', 404);
        }

        return $this->success(new StaffSiteResource($row));
    }
}
