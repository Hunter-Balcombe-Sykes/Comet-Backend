<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

// V2: API resource for professional profiles — transforms identity, contact, location, and onboarding fields.
class ProfessionalResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'account_type' => $this->account_type?->value,
            'display_name' => $this->display_name,
            'partna_url' => $this->partna_url,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'bio' => $this->bio,
            'about' => (object) ($this->about ?? []),
            'phone' => $this->phone,
            'primary_email' => $this->primary_email,
            'country_code' => $this->country_code,
            'timezone' => $this->timezone,
            'status' => $this->status,
            'onboarding_step' => $this->onboarding_step,
            'public_contact_number' => $this->public_contact_number,
            'public_contact_email' => $this->public_contact_email,
            'location_street_address' => $this->location_street_address,
            'location_city' => $this->location_city,
            'location_state' => $this->location_state,
            'location_postcode' => $this->location_postcode,
            'location_country' => $this->location_country,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
