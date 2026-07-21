<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

// POST /api/platforms/menu/items — add an owner-authored (manual) dish. When
// category_id is omitted the controller find-or-creates a manual 'Menu'
// category. category ownership + image ownership are resolved in the controller.
class CreateMenuItemRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string'],
            // Bounded like a real menu price (mirrors ApplyMenuScanRequest):
            // min:0 rejects a negative, max:100000 catches a fat-fingered decimal.
            'price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            // Category memberships (a dish can sit in several categories). All
            // must belong to the caller's menu (any source) — verified in the
            // controller. category_id is the legacy single-membership spelling.
            'category_ids' => ['nullable', 'array', 'min:1', 'max:50'],
            'category_ids.*' => ['uuid', 'distinct'],
            'category_id' => ['nullable', 'uuid'],
            // A caller-owned SiteMedia id — resolved to its optimized variant url
            // in the controller (404 when not theirs).
            'image_media_id' => ['nullable', 'uuid'],
        ];
    }
}
