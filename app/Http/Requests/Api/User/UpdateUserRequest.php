<?php

namespace App\Http\Requests\Api\User;

use App\Enums\AccountType;
use App\Http\Requests\BaseFormRequest;
use App\Models\Core\User\User;
use Illuminate\Validation\Rule;

// V2: Validates professional profile updates — display name, contact info, location, and email/phone sanitization.
class UpdateUserRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // keep handle out of this endpoint (handle changes should be a dedicated flow).
            // One cap for both account types (2026-08-19 identity plan,
            // decision 9): display_name is user-owned after Google's initial
            // seed, so the business 15-char workplace-name cap no longer
            // applies to it.
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],

            // Owner-authored About Me paragraph (users.bio), both types.
            'bio' => ['sometimes', 'nullable', 'string', 'max:1000'],

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
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePhones(['phone', 'public_contact_number']);
        $this->lowercaseEmails(['primary_email', 'public_contact_email']);
        $this->normalizeCountryCode(['country_code']);
    }
}
