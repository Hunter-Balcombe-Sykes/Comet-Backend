<?php

namespace App\Http\Resources\Staff;

use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

// Explicit allowlist for the staff "view site" endpoint. Mirrors the column
// set that buildPayload() previously assembled from AllSiteData — any future
// column on that view must be consciously added here to reach the staff API.
//
// `blocks` includes soft-deleted / inactive rows because staff need full
// visibility into a professional's content state for moderation purposes.
class StaffSiteResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        $siteSettings = is_array($this->site_settings) ? $this->site_settings : [];

        return [
            'is_published' => (bool) $this->is_published,

            'site' => [
                'id'          => (string) $this->site_id,
                'subdomain'   => $this->subdomain,
                'skeleton_id' => $this->skeleton_id,
                'settings'    => $siteSettings,
            ],

            'professional' => [
                'id'                     => (string) $this->user_id,
                'handle'                 => $this->handle,
                'display_name'           => $this->display_name,
                'account_type'           => $this->account_type,
                'bio'                    => $this->bio,
                'location_street_address' => $this->location_street_address,
                'location_city'          => $this->location_city,
                'location_state'         => $this->location_state,
                'location_postcode'      => $this->location_postcode,
                'location_country'       => $this->location_country,
            ],

            'blocks' => $this->blocks ?? [],
        ];
    }
}
