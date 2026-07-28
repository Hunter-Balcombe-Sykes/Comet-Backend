<?php

namespace App\Http\Resources\Content;

use App\Http\Resources\ApiResource;
use App\Models\Content\ManualOverride;
use Illuminate\Http\Request;

/**
 * One per-column manual override (plan §6) — the wire behind the "edited"
 * chip and its one-click "reset to source".
 *
 * `isCleared` is emitted separately from `value` because null means two
 * different things to a UI: "the user blanked this field" versus "nothing
 * here". Only the first is an override, and only the first should render a
 * chip.
 *
 * @mixin ManualOverride
 */
class ManualOverrideResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'itemId' => (string) $this->item_id,
            'facet' => $this->facet,
            'column' => $this->column_name,
            'value' => $this->value,
            'isCleared' => $this->value === null,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
