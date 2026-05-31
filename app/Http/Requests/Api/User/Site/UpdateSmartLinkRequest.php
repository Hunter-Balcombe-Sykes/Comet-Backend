<?php

namespace App\Http\Requests\Api\User\Site;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// Validates editing a smart link — discount fields + visibility toggle.
// URL/type are immutable (swap = delete + re-add) so they're not editable here.
class UpdateSmartLinkRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'discount_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'discount_kind' => ['sometimes', 'nullable', Rule::in(['percent', 'fixed'])],
            'discount_value' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
