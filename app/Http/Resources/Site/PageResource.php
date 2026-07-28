<?php

namespace App\Http\Resources\Site;

use App\Http\Resources\ApiResource;
use App\Models\Core\Site\Page;
use Illuminate\Http\Request;

/**
 * A page on the sitepage (plan §7). Pages are the navigation unit, so this is
 * what the dashboard's nav editor and arrangement tree both read.
 *
 * @mixin Page
 */
class PageResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'siteId' => (string) $this->site_id,
            'key' => $this->key,
            'label' => $this->label,
            'sortOrder' => (int) $this->sort_order,
            'orderMode' => $this->order_mode,
            'isHidden' => (bool) $this->is_hidden,
            'capability' => $this->capability,
            'presetKey' => $this->preset_key,
            'sections' => SectionResource::collection($this->whenLoaded('sections')),
            'createdAt' => $this->created_at->toIso8601String(),
            'updatedAt' => $this->updated_at->toIso8601String(),
        ];
    }
}
