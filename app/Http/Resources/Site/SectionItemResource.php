<?php

namespace App\Http\Resources\Site;

use App\Http\Resources\ApiResource;
use App\Models\Core\Site\SectionItem;
use Illuminate\Http\Request;

/**
 * One curation row: the user pinned this, or hid it (plan §7).
 *
 * @mixin SectionItem
 */
class SectionItemResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'sectionId' => (string) $this->section_id,
            'itemId' => (string) $this->item_id,
            'state' => $this->state,
            'sortKey' => $this->sort_key === null ? null : (float) $this->sort_key,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
