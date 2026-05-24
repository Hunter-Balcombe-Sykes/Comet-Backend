<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// V2: API resource for site.blocks rows where block_group='links'.
// `settings` is passed through (it holds social-mode tags like platform/handle
// and a free-form category) — the column itself is explicitly allowlisted.
class LinkBlockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'professional_id' => $this->professional_id,
            'site_id' => $this->site_id,
            'block_type' => $this->block_type,
            'block_group' => $this->block_group,
            'title' => $this->title,
            'url' => $this->url,
            'icon_key' => $this->icon_key,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'is_enabled' => $this->is_enabled,
            'settings' => (object) ($this->settings ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
