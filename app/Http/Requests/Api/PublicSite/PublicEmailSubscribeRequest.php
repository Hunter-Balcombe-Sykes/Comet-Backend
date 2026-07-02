<?php

namespace App\Http\Requests\Api\PublicSite;

use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\WithBotProtection;
use Illuminate\Validation\Rule;

// V2: Validates public email subscription — requires an RFC-compliant email and a list key
// from the allowed public list keys, with optional name. Honeypot + timing bot protection
// via WithBotProtection (shared with the other public submission requests).
class PublicEmailSubscribeRequest extends BaseFormRequest
{
    use WithBotProtection;

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
            ...$this->botProtectionPrepare(),
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

            // Bot protection (honeypot + timing) — canonical rules in WithBotProtection.
            ...$this->botProtectionRules(),
        ];
    }
}
