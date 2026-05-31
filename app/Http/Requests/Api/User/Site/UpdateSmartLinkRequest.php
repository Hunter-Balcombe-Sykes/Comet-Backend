<?php

namespace App\Http\Requests\Api\User\Site;

use App\Http\Requests\BaseFormRequest;

// Validates editing a smart link — discount code + visibility toggle.
// URL/type are immutable (swap = delete + re-add) so they're not editable here.
class UpdateSmartLinkRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'discount_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
