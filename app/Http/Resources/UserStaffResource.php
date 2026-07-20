<?php

namespace App\Http\Resources;

use App\Models\Core\User\User;
use Illuminate\Http\Request;

// Staff admin shape for Professional — full profile including auth_user_id for identity verification.
// No payment integration fields; those are not relevant to staff management workflows.
//
// PII gate (#SEC-101): auth_user_id, phone, primary_email, public_contact_number,
// the location_* fields, and admin_notes are null unless $showPii is true. Only
// admin staff have $showPii=true — enforced by the controller before passing the
// flag here. Mirrors the pattern in StaffUserListResource.
/**
 * @mixin User
 */
class UserStaffResource extends ApiResource
{
    /**
     * @param  mixed  $resource  User model instance
     * @param  bool  $showPii  True only for admin staff; gates sensitive-field visibility
     */
    public function __construct($resource, private readonly bool $showPii = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'auth_user_id' => $this->showPii ? $this->auth_user_id : null,
            'account_type' => $this->account_type?->value,
            'display_name' => $this->display_name,
            'partna_url' => $this->partna_url,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone' => $this->showPii ? $this->phone : null,
            'primary_email' => $this->showPii ? $this->primary_email : null,
            'country_code' => $this->country_code,
            'timezone' => $this->timezone,
            'status' => $this->status,
            'onboarding_step' => $this->onboarding_step,
            'public_contact_number' => $this->showPii ? $this->public_contact_number : null,
            'public_contact_email' => $this->public_contact_email,
            'location_street_address' => $this->showPii ? $this->location_street_address : null,
            'location_city' => $this->showPii ? $this->location_city : null,
            'location_state' => $this->showPii ? $this->location_state : null,
            'location_postcode' => $this->showPii ? $this->location_postcode : null,
            'location_country' => $this->showPii ? $this->location_country : null,
            // Staff-only tribal knowledge — must NEVER appear in UserDashboardResource (/me).
            'admin_notes' => $this->showPii ? $this->admin_notes : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            // Signals to staff UI that this professional is soft-deleted (deleted_at is set);
            // distinct from status='pending_deletion' which is the 30-day grace period.
            'parent_status' => $this->trashed() ? 'soft_deleted' : 'active',
            // Task 18 — marketing-pipeline visibility: the pre-account build origin
            // record, when one exists. A bare whenLoaded('preAccountBuild', fn () => [...])
            // isn't enough here — a HasOne eager-load with no match still marks the
            // relation "loaded", just with a null value, and whenLoaded()'s closure
            // form short-circuits a loaded-but-null relation straight to `null`
            // (ConditionallyLoadsAttributes::whenLoaded) rather than dropping the
            // key. Once show() eager-loads this for every professional, that would
            // emit `"pre_account_build": null` for ordinary users instead of omitting
            // the key. Gate on the resolved value, not just the loaded flag, so the
            // key is fully ABSENT (not present-as-null) when there's no build —
            // staff clients key off presence, not nullness.
            'pre_account_build' => $this->when(
                $this->relationLoaded('preAccountBuild') && $this->preAccountBuild !== null,
                fn () => [
                    'source_type' => $this->preAccountBuild->source_type,
                    'source_ref' => $this->preAccountBuild->source_ref,
                    'built_via' => $this->preAccountBuild->built_via,
                    'build_state' => $this->preAccountBuild->build_state,
                    'failure_code' => $this->preAccountBuild->failure_code,
                    'expires_at' => $this->preAccountBuild->expires_at?->toIso8601String(),
                    'claimed_at' => $this->preAccountBuild->claimed_at?->toIso8601String(),
                ]
            ),
        ];
    }
}
