<?php

namespace App\Http\Requests\Api\Staff\UserSite;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

// V2: Validates staff update of a professional profile — supports display name, contact info, location, and phone normalization with PATCH semantics.
class StaffUpdateUserRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            // profile-ish fields
            'display_name' => ['sometimes', 'required', 'string', 'max:255'],
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],

            'primary_email' => ['sometimes', 'required', 'email:rfc', 'max:255'],
            'phone' => ['sometimes', 'required', ...$this->phoneRule()],
            'public_contact_number' => ['sometimes', 'nullable', ...$this->phoneRule()],
            'public_contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],

            // ISO 3166-1 alpha-2 only. Normalised to upper-case in
            // prepareForValidation before this rule runs.
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:64'],
            // Location
            'location_street_address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_state' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_postcode' => ['sometimes', 'nullable', 'string', 'max:255'],
            'location_country' => ['sometimes', 'nullable', 'string', 'max:255'],

            // Staff-only — see UserStaffResource. Self-service UserDashboardResource
            // must never expose admin_notes back to the professional.
            'admin_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->normalizePhones(['phone', 'public_contact_number']);
        $this->lowercaseEmails(['primary_email', 'public_contact_email']);

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
