<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

// POST /api/platforms/menu/categories — add an owner-authored (manual) menu
// category. Duplicate-name (case-insensitive, among live categories) is checked
// in the controller, where the resolved menu is available.
class CreateMenuCategoryRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // required() already fails on a whitespace-only string (Laravel trims
            // before checking), so no separate non-empty rule is needed.
            'name' => ['required', 'string', 'max:120'],
        ];
    }
}
