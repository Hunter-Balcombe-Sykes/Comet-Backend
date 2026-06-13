<?php

namespace App\Http\Requests\Api\User;

use App\Enums\AccountType;
use App\Http\Requests\BaseFormRequest;
use App\Http\Requests\Concerns\ValidatesUserAbout;
use App\Models\Core\User\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

// V2: Validates professional profile updates — display name, contact info, location, and email/phone sanitization.
class UpdateUserRequest extends BaseFormRequest
{
    use ValidatesUserAbout;

    public function rules(): array
    {
        return array_merge([
            // keep handle out of this endpoint (handle changes should be a dedicated flow)
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Account-type switch from settings (the "Change account type" flow).
            // 'individual' is not accepted — only the two user-selectable types.
            'account_type' => ['sometimes', 'required', Rule::in([AccountType::Partna->value, AccountType::Business->value])],

            'primary_email' => [
                'sometimes', 'required', 'email:rfc', 'max:255',
                Rule::unique(User::class, 'primary_email')
                    ->ignore($this->attributes->get('professional')?->id, 'id'),
            ],
            'phone' => ['sometimes', 'required', ...$this->phoneRule()],
            'public_contact_number' => ['sometimes', 'nullable', ...$this->phoneRule()],
            'public_contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],

            // ISO 3166-1 alpha-2 only. Normalised to upper-case in
            // prepareForValidation before this rule runs. Matches the
            // tightened BootstrapRequest format.
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Location
            'location_street_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_state' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_postcode' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_country' => ['sometimes', 'nullable', 'string', 'max:255'],
        ], $this->aboutRules());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($v) {
            $this->validateExperienceDateOrder($v);
        });
    }

    protected function prepareForValidation(): void
    {
        $this->normalizeAboutPayload();
        $this->normalizePhones(['phone', 'public_contact_number']);
        $this->lowercaseEmails(['primary_email', 'public_contact_email']);
        $this->cleanText(['bio']);

        $merge = [];

        // Upper-case country_code if supplied so the ISO alpha-2 validator
        // accepts lower-case input from older clients.
        if ($this->has('country_code')) {
            $cc = $this->input('country_code');
            if (is_string($cc)) {
                $cc = strtoupper(trim($cc));
                $merge['country_code'] = $cc === '' ? null : $cc;
            }
        }

        if ($merge) {
            $this->merge($merge);
        }
    }
}
