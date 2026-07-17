<?php

namespace App\Http\Requests\Platforms;

use Illuminate\Foundation\Http\FormRequest;

// PATCH /api/platforms/menu/categories/{category} — rename an owner-authored
// (manual) or scan category. The synced-category guard (a scraped category
// can't be renamed) is enforced in the controller, where source_platform is known.
class UpdateMenuCategoryRequest extends FormRequest
{
    // Authorization is handled at the trait chokepoint, not here.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
        ];
    }
}
