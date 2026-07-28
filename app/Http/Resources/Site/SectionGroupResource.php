<?php

namespace App\Http\Resources\Site;

use App\Http\Resources\ApiResource;
use App\Models\Core\Site\SectionGroup;
use Illuminate\Http\Request;

/**
 * A section's label/order override for one group (plan §7).
 *
 * @mixin SectionGroup
 */
class SectionGroupResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'sectionId' => (string) $this->section_id,
            'groupKey' => $this->group_key,
            'label' => $this->label,
            'sortOrder' => (int) $this->sort_order,
            'isHidden' => (bool) $this->is_hidden,
        ];
    }
}
