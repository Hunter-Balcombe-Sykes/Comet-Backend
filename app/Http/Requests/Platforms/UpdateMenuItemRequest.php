<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

// PATCH /api/platforms/menu/items/{item} — edit any dish (scraped, scanned, or
// manual). PATCH semantics: only the keys actually sent are touched ('sometimes'),
// so an omitted field is left as-is while an explicit null clears it. Editing a
// scraped dish detaches it from platform sync (is_manual flips) — done in the
// controller. image_media_id sets a new image; remove_image:true clears it.
class UpdateMenuItemRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string'],
            'price' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100000'],
            'category_id' => ['sometimes', 'nullable', 'uuid'],
            'image_media_id' => ['sometimes', 'nullable', 'uuid'],
            'remove_image' => ['sometimes', 'boolean'],
        ];
    }
}
