<?php

namespace App\Http\Requests\Api\User\Customer;

use App\Http\Requests\BaseFormRequest;

// V2: Validates new customer creation — name, contact info, source defaulting to manual, and phone sanitization.
class StoreCustomerRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', ...$this->phoneRule()],
            'notes' => ['nullable', 'string', 'max:5000'],
            'source' => ['nullable', 'string', 'max:225'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'marketing_opt_in_cached' => ['nullable', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['source' => $this->input('source', 'manual')]);
        $this->normalizePhones(['phone']);
    }
}
