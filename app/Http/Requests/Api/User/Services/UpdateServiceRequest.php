<?php

namespace App\Http\Requests\Api\User\Services;

use App\Http\Requests\BaseFormRequest;

// Validates partial service updates — title/price/description/duration etc.
// Categories were removed from the affiliate UX; no rule for category_id
// here, so a stray value in the payload is silently dropped.
class UpdateServiceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'price_cents' => ['sometimes', 'required', 'integer', 'min:0'],
            'currency_code' => ['sometimes', 'nullable', 'string', 'size:3'],
            'duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
