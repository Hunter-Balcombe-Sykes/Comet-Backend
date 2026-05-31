<?php

namespace App\Http\Resources;

use App\Services\SmartLinks\SmartLinkVisitorUrl;
use Illuminate\Http\Request;

// Dashboard-facing shape of a site.smart_links row. The owner sees their own
// discount code; visitor_url is the computed click target (tracking + discount
// baked in). Mirrors the engine SmartLink contract minus family-specific
// reshaping (the public payload builder does that for the sitepage).
class SmartLinkResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'type' => $this->type,
            'family' => $this->family,
            'platform' => $this->platform,
            'title' => $this->title,
            'image_url' => $this->image_url,
            'favicon_url' => $this->favicon_url,
            'brand_name' => $this->brand_name,
            'brand_logo_url' => $this->brand_logo_url,
            'metadata' => (object) ($this->metadata ?? []),
            'discount_code' => $this->discount_code,
            'discount_kind' => $this->discount_kind,
            'discount_value' => $this->discount_value !== null ? (float) $this->discount_value : null,
            'visitor_url' => app(SmartLinkVisitorUrl::class)->build($this->resource),
            'sort_order' => (int) $this->sort_order,
            'is_active' => (bool) $this->is_active,
            'last_refreshed_at' => $this->last_refreshed_at?->toIso8601String(),
            'last_refresh_status' => $this->last_refresh_status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
