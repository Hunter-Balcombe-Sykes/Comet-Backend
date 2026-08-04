<?php

namespace App\Http\Resources;

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
            // row it is.
            'source' => $this->source,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
