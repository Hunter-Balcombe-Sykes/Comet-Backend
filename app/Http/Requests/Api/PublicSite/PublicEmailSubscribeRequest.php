<?php

namespace App\Http\Requests\Api\PublicSite;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// V2: Validates public email subscription — requires an RFC-compliant email and a list key
// from the allowed public list keys, with optional name. Honeypot + optional timing bot
// protection mirrors PublicCustomerLeadRequest; timing is nullable (non-breaking) until
// the frontend sends form_started_at_ms — tighten to required once it does.
class PublicEmailSubscribeRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        $listKey = is_string($this->list_key) ? trim($this->list_key) : null;
        if ($listKey === '') {
            $listKey = null;
        }

        $this->merge([
            'email' => is_string($this->email) ? strtolower(trim($this->email)) : $this->email,
            'full_name' => is_string($this->full_name) ? trim($this->full_name) : $this->full_name,
            'list_key' => $listKey ?? 'marketing',
            // honeypot — trim so an accidental space doesn't bypass the non-empty check
            'website' => is_string($this->website) ? trim($this->website) : $this->website,
        ]);
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'full_name' => ['nullable', 'string', 'max:200'],
            'list_key' => [
                'required',
                'string',
                'max:50',
                Rule::in(config('subscriptions.public_list_keys', ['marketing'])),
            ],

            // Bot protection (same field names as PublicCustomerLeadRequest).
            'website' => ['nullable', 'string', 'max:255'],          // honeypot
            'form_started_at_ms' => ['nullable', 'integer', 'min:0'], // timing (nullable until frontend sends it)
        ];
    }
}
