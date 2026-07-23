<?php

namespace App\Http\Requests\Api\User;

use App\Enums\AccountType;
use App\Http\Requests\BaseFormRequest;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Validation\Rule;

// V2: Validates professional profile updates — display name, contact info, location, and email/phone sanitization.
class UpdateUserRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // keep handle out of this endpoint (handle changes should be a dedicated flow).
            // Business accounts' display name IS the business name (Google adoption
            // mirrors it — capability google_business_sets_display_name), so it gets
            // the same 15-char cap as workplace names; personal partna names keep 255.
            'display_name' => ['sometimes', 'required', 'string', $this->displayNameMax()],

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

    /** max:15 when display_name is a business name (see rules() comment), else max:255. */
    private function displayNameMax(): string
    {
        $user = $this->attributes->get('professional');

        return $user instanceof User && AccountCapabilities::for($user)->google_business_sets_display_name
            ? 'max:15'
            : 'max:255';
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePhones(['phone', 'public_contact_number']);
        $this->lowercaseEmails(['primary_email', 'public_contact_email']);
        $this->normalizeCountryCode(['country_code']);
    }
}
