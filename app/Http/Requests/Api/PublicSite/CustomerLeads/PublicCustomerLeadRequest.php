<?php

namespace App\Http\Requests\Api\PublicSite\CustomerLeads;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\WithBotProtection;

// V2: Validates public customer lead submissions — requires name, email, and phone with honeypot and timing-based bot protection.
class PublicCustomerLeadRequest extends BaseFormRequest
{
    use WithBotProtection;

    protected function prepareForValidation(): void
    {
        $optIn = $this->input('marketing_opt_in');

        $parsed = filter_var($optIn, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        $this->merge([
            'full_name' => is_string($this->full_name) ? trim($this->full_name) : $this->full_name,
            'email' => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
            'phone' => is_string($this->phone) ? trim($this->phone) : $this->phone,
            'notes' => is_string($this->notes) ? trim($this->notes) : $this->notes,
            'marketing_opt_in' => $parsed ?? false,
            ...$this->botProtectionPrepare(),
        ]);
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['required', ...$this->phoneRule()],
            'notes' => ['nullable', 'string', 'max:2000'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'marketing_opt_in' => ['boolean'],

            // bot protection
            ...$this->botProtectionRules(),
        ];
    }
}
