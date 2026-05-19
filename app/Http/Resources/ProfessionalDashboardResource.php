<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Own-profile shape returned to the authenticated professional (dashboard show, update, bootstrap).
// Square fields are only present when the `squareIntegration` relation has been eager-loaded by the caller.
class ProfessionalDashboardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'auth_user_id' => $this->auth_user_id,
            'professional_type' => $this->professional_type,
            // Track B's frontend account-capabilities.ts reads this for 3-state
            // routing (brand / partner / individual). See plan §28.8a.
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
            'stripe_connect_status' => $this->stripe_connect_status,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // Square — caller must eager-load 'squareIntegration' for these to appear
            'square_connected' => $this->whenLoaded('squareIntegration', function () {
                $integration = $this->squareIntegration;

                return $integration !== null
                    && ! empty($integration->access_token)
                    && ! empty($integration->external_account_id);
            }),
            'square_merchant_id' => $this->whenLoaded('squareIntegration', fn () => $this->squareIntegration?->external_account_id),
        ];
    }
}
