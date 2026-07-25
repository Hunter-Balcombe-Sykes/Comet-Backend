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
        $rawBlocks = is_array($this->blocks) ? $this->blocks : [];

        return [
            'is_published' => (bool) $this->is_published,

            'site' => [
                'id' => (string) $this->site_id,
                'subdomain' => $this->subdomain,
                'architecture_id' => $this->architecture_id,
                'settings' => $siteSettings,
            ],

            'professional' => [
                'id' => (string) $this->user_id,
                'handle' => $this->handle,
                'display_name' => $this->display_name,
                'account_type' => $this->account_type,
                'location_street_address' => $this->location_street_address,
                'location_city' => $this->location_city,
                'location_state' => $this->location_state,
                'location_postcode' => $this->location_postcode,
                'location_country' => $this->location_country,
            ],

            // Allowlist mirrors the all_site_data view's jsonb_build_object keys exactly.
            // Unknown/future keys in the JSONB are dropped here; extend mapBlock() consciously.
            // Soft-deleted and inactive blocks are included for moderation visibility.
            'blocks' => array_map([$this, 'mapBlock'], $rawBlocks),
        ];
    }

    /**
     * Allowlist a single block from the all_site_data JSONB aggregate.
     *
     * Keys here mirror the jsonb_build_object in the all_site_data view definition
     * (supabase/migrations/20260726000000_baseline_pilot.sql). Any new
     * block column added to the view must also be added here before it reaches the
     * staff API.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private const BLOCK_ALLOWLIST = [
        'id', 'site_id', 'user_id', 'block_type', 'block_group',
        'title', 'url', 'icon_key', 'sort_order', 'is_active',
        'settings', 'platform', 'category', 'live_check_enabled',
        'created_at', 'updated_at',
    ];

    private function mapBlock(array $block): array
    {
        // Intersect, not pad: keep only allowlisted keys that are actually present.
        // Drops unknown/future JSONB keys (the security goal) while preserving a
        // partial block exactly — never invents null keys the source didn't have.
        $mapped = array_intersect_key($block, array_flip(self::BLOCK_ALLOWLIST));
        if (array_key_exists('id', $mapped)) {
            $mapped['id'] = (string) $mapped['id'];
        }

        return $mapped;
    }
}
