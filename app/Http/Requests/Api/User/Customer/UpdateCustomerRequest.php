<?php

namespace App\Http\Requests\Api\User\Customer;

use App\Http\Requests\BaseFormRequest;

// V2: Validates partial customer updates — all fields optional with phone sanitization.
class UpdateCustomerRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->normalizePhones(['phone']);
    }

    public function rules(): array
    {
        return [
            'full_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'nullable', ...$this->phoneRule()],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'source' => ['sometimes', 'nullable', 'string', 'max:225'],
            'external_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'marketing_opt_in_cached' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
