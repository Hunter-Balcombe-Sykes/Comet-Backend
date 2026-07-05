<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

// Own-profile shape returned to the authenticated professional (dashboard show, update, bootstrap).
class UserDashboardResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'auth_user_id' => $this->auth_user_id,
            'account_type' => $this->account_type?->value,
            'display_name' => $this->display_name,
            'partna_url' => $this->partna_url,
            // Custom domain summary so the dashboard can show connection state
            // and route every sitepage-URL reference through the custom domain
            // when the user has made it their primary URL.
            'custom_domain' => $this->site?->custom_domain,
            'custom_domain_status' => $this->site?->custom_domain_status,
            'custom_domain_primary' => (bool) ($this->site?->custom_domain_primary ?? false),
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
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
