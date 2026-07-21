<?php

namespace App\Http\Requests\Api\User\Services;

use App\Http\Requests\BaseFormRequest;

// Validates new service creation — title, price, description, currency,
// duration, active state, and (multi-)category memberships. Ownership of the
// supplied category ids is asserted in the controller.
class StoreServiceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price_cents' => ['required', 'integer', 'min:0'],
            'currency_code' => ['nullable', 'string', 'size:3'],
            'duration_minutes' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            // Memberships: category_ids (multi) or the legacy single category_id.
            'category_ids' => ['sometimes', 'nullable', 'array', 'max:50'],
            'category_ids.*' => ['uuid', 'distinct'],
            'category_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
