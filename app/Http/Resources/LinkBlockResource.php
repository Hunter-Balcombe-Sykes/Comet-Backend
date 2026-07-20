<?php

namespace App\Http\Resources;

use App\Models\Core\Site\Block;
use Illuminate\Http\Request;

// V2: API resource for site.blocks rows where block_group='links'.
// Phase 2: platform/category/live_check_enabled are promoted columns emitted
// at the top level. `settings` passes through the remaining JSONB keys (handle,
// is_live, display-hint flags like highlight/note) — the column itself is
// explicitly allowlisted and no longer carries the promoted keys.
/**
 * @mixin Block
 */
class LinkBlockResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'user_id' => $this->user_id,
            'site_id' => $this->site_id,
            'block_type' => $this->block_type,
            'block_group' => $this->block_group,
            'title' => $this->title,
            'url' => $this->url,
            'icon_key' => $this->icon_key,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'is_enabled' => $this->is_enabled,
            'platform' => $this->platform,
            'category' => $this->category,
            'live_check_enabled' => (bool) $this->live_check_enabled,
            'settings' => (object) ($this->settings ?? []),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
