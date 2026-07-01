<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

// Staff admin shape for Professional — full profile including auth_user_id for identity verification.
// No payment integration fields; those are not relevant to staff management workflows.
class UserStaffResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'auth_user_id' => $this->auth_user_id,
            'account_type' => $this->account_type?->value,
            'display_name' => $this->display_name,
            'partna_url' => $this->partna_url,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'bio' => $this->bio,
            // Reads from child tables via aboutPayload() — not from the legacy about JSONB (FOUND-5).
            'about' => (object) $this->aboutPayload(),
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
            // Plan §28.8a: only emit stripe_connect_status for account types that use Stripe Connect.
            // Individuals never have a Connect account; omitting the field avoids a null confusion.
            'stripe_connect_status' => $this->when(
                false, // Stripe Connect removed in 2026-05-22 standalone strip; reintegrate post-pilot
                fn () => $this->stripe_connect_status,
            ),
            // Staff-only tribal knowledge — must NEVER appear in UserDashboardResource (/me).
            'admin_notes' => $this->admin_notes,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // Signals to staff UI that this professional is soft-deleted (deleted_at is set);
            // distinct from status='pending_deletion' which is the 30-day grace period.
            'parent_status' => $this->trashed() ? 'soft_deleted' : 'active',
        ];
    }
}
