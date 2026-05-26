<?php

namespace App\Http\Requests\Api\User\Services;

use App\Http\Requests\BaseFormRequest;

// Validates new service creation — title, price, description, currency,
// duration, active state. Categories were removed from the affiliate UX;
// the column stays nullable on the DB but no rule for it here so a stray
// category_id in a payload is silently dropped instead of accepted.
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
        ];
    }
}
