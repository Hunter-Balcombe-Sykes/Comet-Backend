<?php

namespace App\Http\Resources;

use App\Models\Core\User\ServiceCategory;
use Illuminate\Http\Request;

// V2: API resource for site.service_categories rows.
class ServiceCategoryResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'user_id' => $this->user_id,
            'title' => $this->title,
            // 'fresha' = auto-created from a Fresha category during
            // projection; NULL = owner-authored. Without it the dashboard
            // couldn't tell a synced category from an editable one —
            // ServiceResource and the menu payload both already say whose
            // row it is. Typed access (not `$this->source` like the rows
            // above): those ride the 2026-06-03 PHPStan baseline; new code
            // is gated at level 5 and doesn't get to add to it.
            'source' => $this->resource instanceof ServiceCategory
                ? $this->resource->source
                : null,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
