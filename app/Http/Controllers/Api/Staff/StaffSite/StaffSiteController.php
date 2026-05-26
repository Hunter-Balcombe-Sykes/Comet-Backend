<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\User\User;
use App\Models\Views\AllSiteData;
use Illuminate\Http\JsonResponse;

// V2: Staff views site data including unpublished sites. Used by internal staff dashboard.
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

        $siteSettings = is_array($row->site_settings) ? $row->site_settings : [];

        // Staff can see unpublished too, so we return published flag either way
        return $this->success([
            'is_published' => (bool) $row->is_published,

            'site' => [
                'id' => $row->site_id,
                'subdomain' => $row->subdomain,
                'settings' => $siteSettings,
            ],

            'professional' => [
                'id' => $row->user_id,
                'handle' => $row->handle,
                'display_name' => $row->display_name,
                // account_type is the authoritative field
                // during the §28.1 dual-write window. Both fields exposed until frontend fully migrates.
                'account_type' => $row->account_type,
                'bio' => $row->bio,
                'location_street_address' => $row->location_street_address,
                'location_city' => $row->location_city,
                'location_state' => $row->location_state,
                'location_postcode' => $row->location_postcode,
                'location_country' => $row->location_country,
            ],

            'theme' => [
                'id' => $row->theme_id,
                'key' => $row->theme_key,
                'name' => $row->theme_name,
                'config' => $row->theme_config,
            ],

            'blocks' => $row->blocks ?? [],
        ]);
    }

    public function showByProfessional(User $professional): JsonResponse
    {
        $row = AllSiteData::query()
            ->where('user_id', $professional->id)
            ->first();

        if (! $row) {
            return $this->error('Site not found for professional.', 404);
        }

        $siteSettings = is_array($row->site_settings) ? $row->site_settings : [];

        return $this->success([
            'is_published' => (bool) $row->is_published,

            'site' => [
                'id' => $row->site_id,
                'subdomain' => $row->subdomain,
                'settings' => $siteSettings,
            ],

            'professional' => [
                'id' => $row->user_id,
                'handle' => $row->handle,
                'display_name' => $row->display_name,
                // account_type is the authoritative field
                // during the §28.1 dual-write window. Both fields exposed until frontend fully migrates.
                'account_type' => $row->account_type,
                'bio' => $row->bio,
                'location_street_address' => $row->location_street_address,
                'location_city' => $row->location_city,
                'location_state' => $row->location_state,
                'location_postcode' => $row->location_postcode,
                'location_country' => $row->location_country,
            ],

            'theme' => [
                'id' => $row->theme_id,
                'key' => $row->theme_key,
                'name' => $row->theme_name,
                'config' => $row->theme_config,
            ],

            'blocks' => $row->blocks ?? [],
        ]);
    }
}
