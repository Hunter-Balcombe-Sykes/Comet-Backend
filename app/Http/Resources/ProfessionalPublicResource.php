<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

// Public-safe shape for Professional — only fields appropriate for unauthenticated visitors.
// Excludes: auth_user_id, primary_email, phone, handle, street address, internal status/onboarding fields.
// public_contact_* are opt-in: a professional sets them to share a contact detail publicly;
// NULL means not sharing. They are distinct from primary_email / phone, which are never exposed.
class ProfessionalPublicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_type' => $this->account_type?->value,
            'display_name' => $this->display_name,
            'partna_url' => $this->partna_url,
            'bio' => $this->bio,
            'public_contact_number' => $this->public_contact_number,
            'public_contact_email' => $this->public_contact_email,
            'location_city' => $this->location_city,
            'location_state' => $this->location_state,
            'location_country' => $this->location_country,
        ];
    }
}
