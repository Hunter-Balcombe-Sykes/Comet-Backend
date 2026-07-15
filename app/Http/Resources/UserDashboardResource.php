<?php

namespace App\Http\Resources;

use App\Services\Accounts\AccountCapabilities;
use Illuminate\Http\Request;

// Own-profile shape returned to the authenticated professional (dashboard show, update, bootstrap).
class UserDashboardResource extends ApiResource
{
    public function toArray(Request $request): array
    {
        // $this->resource (not a magic-proxied property) is the actual User
        // model — AccountCapabilities::for() needs the instance, not a value.
        $capabilities = AccountCapabilities::for($this->resource);

        return [
            'id' => (string) $this->id,
            'auth_user_id' => $this->auth_user_id,
            'account_type' => $this->account_type?->value,
            // Curated industry/sector slug + its provenance ('manual' | 'google-business' |
            // null) — see App\Services\Profile\SectorTaxonomy. The dashboard never branches
            // on sector directly; it reads `capabilities` below for feature gating.
            'sector' => $this->sector,
            'sector_source' => $this->sector_source,
            // Sector-derived feature flags (2026-07-15 industry/sector gating).
            // Camel-cased for the frontend contract; source of truth is
            // AccountCapabilities — never rederive these from sector client-side.
            'capabilities' => [
                'canUseMenu' => $capabilities->can_use_menu,
                'canUseReservations' => $capabilities->can_use_reservations,
                'canUseBooking' => $capabilities->can_use_booking,
                'canUseOnlineOrdering' => $capabilities->can_use_online_ordering,
            ],
            // Staff-ness is independent of account_type (which stays
            // partna/business) — it derives from a linked core.partna_staff
            // record. The dashboard reads this to switch to the staff surface.
            // Only the boolean is exposed here; the granular admin/support role
            // still comes from the aal2-gated /staff/me. Relation is set on the
            // model by UserSelfController (never lazy-loaded here).
            'is_staff' => $this->partnaStaff !== null,
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
