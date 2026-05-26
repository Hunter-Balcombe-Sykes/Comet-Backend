<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

// API resource for site.sites rows (dashboard + staff side).
// `settings` is passed through unchanged — the dashboard reads non-design
// settings (booking, GBP, etc). Per-user design vars live in site.design_kits
// (separate table), exposed via the skeleton-system payload.
class SiteResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'user_id' => $this->user_id,
            'subdomain' => $this->subdomain,
            'skeleton_id' => $this->skeleton_id,
            'is_published' => $this->is_published,
            'subdomain_changed_at' => $this->subdomain_changed_at?->toIso8601String(),
            'unpublished_at' => $this->unpublished_at?->toIso8601String(),
            'settings' => (object) ($this->settings ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
