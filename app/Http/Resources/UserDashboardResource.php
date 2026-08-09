<?php

namespace App\Http\Resources;

use App\Models\Core\Site\UserHandleAlias;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Http\Request;

// Own-profile shape returned to the authenticated professional (dashboard show, update, bootstrap).
/**
 * @mixin User
 */
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
            // The WHOLE capability set, camel-cased for the frontend contract.
            // Source of truth is AccountCapabilities — never rederive these
            // client-side, and never branch on account_type instead.
            //
            // This used to expose only the four sector-derived flags, which left
            // the dashboard no way to know about multipage/lifestyle/storewide
            // except by branching on account_type (the one thing the doctrine
            // forbids) or by POSTing and reading the 4xx back. Additive: the
            // original four keys keep their names and meaning.
            'capabilities' => [
                'canUseMenu' => $capabilities->can_use_menu,
                'canUseReservations' => $capabilities->can_use_reservations,
                'canUseBooking' => $capabilities->can_use_booking,
                'canUseOnlineOrdering' => $capabilities->can_use_online_ordering,
                'canUseMultipageSite' => $capabilities->can_use_multipage_site,
                'canUseLifestylePages' => $capabilities->can_use_lifestyle_pages,
                'canBookStorewide' => $capabilities->can_book_storewide,
                'canEditDesign' => $capabilities->can_edit_design,
                'canCurateIdentity' => $capabilities->can_curate_identity,
                'canSubmitFeedback' => $capabilities->can_submit_feedback,
                'canBeReported' => $capabilities->can_be_reported,
                'canAutosyncScrapedConnections' => $capabilities->can_autosync_scraped_connections,
                'googleBusinessFullSync' => $capabilities->google_business_full_sync,
                'googleBusinessSetsDisplayName' => $capabilities->google_business_sets_display_name,
                'receiveModerationNotifications' => $capabilities->receive_moderation_notifications,
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
            // Handles this professional renamed away from that are still inside
            // their reclaim grace window (core.user_handle_aliases lifecycle:
            // GRACE 0-14d). POST /me/site/reclaim-handle takes one back; the
            // dashboard shows the affordance only when this list is non-empty.
            // Added 2026-08-06 — the endpoint predates any UI (audit decision 6).
            // Guard: an unsaved model (resource unit tests build these) has no
            // id — querying with user_id NULL is never meaningful. blank(), not
            // === null: User's @property declares $id as a non-nullable string,
            // so PHPStan reads the strict comparison as always-false (it is only
            // null on an unsaved instance, which the annotation cannot express).
            'reclaimable_handles' => blank($this->id) ? [] : UserHandleAlias::query()
                ->where('user_id', $this->id)
                ->where('reclaim_until', '>', now())
                ->orderBy('reclaim_until')
                ->get(['handle', 'reclaim_until'])
                ->map(fn (UserHandleAlias $alias) => [
                    'handle' => $alias->handle,
                    'reclaim_until' => $alias->reclaim_until?->toIso8601String(),
                ])
                ->all(),
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
