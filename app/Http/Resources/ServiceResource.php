<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

// V2: API resource for site.services rows. Explicit allowlist — future columns
// (e.g. internal_cost_cents, deleted_origin) don't auto-ship to dashboard/staff.
class ServiceResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'user_id' => $this->user_id,
            'category_id' => $this->category_id,
            'title' => $this->title,
            'description' => $this->description,
            'price_cents' => $this->price_cents,
            'currency_code' => $this->currency_code,
            'duration_minutes' => $this->duration_minutes,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'deleted_at' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
