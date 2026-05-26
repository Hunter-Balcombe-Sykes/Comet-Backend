<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

// V2: API resource for site.sites rows (dashboard + staff side).
// `settings` is passed through unchanged — the dashboard editor reads the full
// settings blob to render design tokens, booking config, GBP profile, etc.
// Tightening to a key-level allowlist on settings.* is a follow-up audit task;
// the public-internet path already does this via IndividualProfileResource::DESIGN_KEYS.
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
            'theme_id' => $this->theme_id,
            'is_published' => $this->is_published,
            'subdomain_changed_at' => $this->subdomain_changed_at?->toIso8601String(),
            'unpublished_at' => $this->unpublished_at?->toIso8601String(),
            'settings' => (object) ($this->settings ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
