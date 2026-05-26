<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
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

        return $this->success($this->buildPayload($row));
    }

    public function showByProfessional(User $professional): JsonResponse
    {
        $row = AllSiteData::query()
            ->where('user_id', $professional->id)
            ->first();

        if (! $row) {
            return $this->error('Site not found for professional.', 404);
        }

        return $this->success($this->buildPayload($row));
    }

    /**
     * Build the staff payload from an all_site_data row. Single source so
     * show + showByProfessional can't drift. Skeleton choice replaces the old
     * `theme` object.
     */
    private function buildPayload(AllSiteData $row): array
    {
        $siteSettings = is_array($row->site_settings) ? $row->site_settings : [];

        return [
            'is_published' => (bool) $row->is_published,

            'site' => [
                'id' => $row->site_id,
                'subdomain' => $row->subdomain,
                'skeleton_id' => $row->skeleton_id,
                'settings' => $siteSettings,
            ],

            'professional' => [
                'id' => $row->user_id,
                'handle' => $row->handle,
                'display_name' => $row->display_name,
                'account_type' => $row->account_type,
                'bio' => $row->bio,
                'location_street_address' => $row->location_street_address,
                'location_city' => $row->location_city,
                'location_state' => $row->location_state,
                'location_postcode' => $row->location_postcode,
                'location_country' => $row->location_country,
            ],

            'blocks' => $row->blocks ?? [],
        ];
    }
}
