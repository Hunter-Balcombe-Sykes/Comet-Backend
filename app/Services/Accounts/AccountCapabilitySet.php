<?php

namespace App\Services\Accounts;

/**
 * Snapshot of what a Professional can do RIGHT NOW. Built by {@see AccountCapabilities}.
 *
 * Read-only. Construct once per Professional per request and pass it around — capability
 * checks are pure functions on this value object so a single instance can be reused freely.
 *
 * Most capabilities are constant today; `can_book_storewide` varies by `account_type`
 * (Business Partna vs standard).
 * Capabilities for commerce/payout/brand features were removed in the 2026-05-22 strip
 * and will be re-added as named params here when reintegrated.
 */
final readonly class AccountCapabilitySet
{
    /**
     * @param  string  $notification_categories  Comma-separated list of notification category tags
     *                                           available to this account (e.g. 'profile,platform').
     *                                           Stored for informational use — callers that filter by
     *                                           category should check this value; no automatic enforcement
     *                                           is applied by this class.
     * @param  string  $worker_kv_type  Routing tag written to SUBDOMAIN_KV by
     *                                  SyncSubdomainToKvJob. One of: brand|affiliate|individual.
     * @param  bool  $can_submit_feedback  Always true today. The gate exists so a future
     *                                     per-user feedback ban can disable abusers without
     *                                     a controller change.
     * @param  bool  $can_be_reported  True only for active accounts. Suspended/disabled users
     *                                 are already under moderation action so new public reports
     *                                 are suppressed (Plan B, T&S foundation).
     * @param  bool  $receive_moderation_notifications  True only for active accounts. Suspended
     *                                                  or banned users do not receive email/push
     *                                                  moderation notifications.
     */
    public function __construct(
        public bool $can_edit_design,
        public string $notification_categories,
        public string $worker_kv_type,
        public bool $can_submit_feedback,
        // NEW — moderation capabilities (Plan B, T&S foundation):
        public bool $can_be_reported,
        public bool $receive_moderation_notifications,
        // Business Partna accounts book "storewide" on Fresha (no team-member
        // picker); standard accounts pick a team member. Derived from account_type
        // in AccountCapabilities so nothing else branches on the type directly.
        public bool $can_book_storewide,
        // Business Partna accounts get the FULL Google Business auto-sync —
        // reservations, online-ordering, workplace card + socials are all seeded
        // from the connected Place. Standard accounts only get the booking link
        // synced (so a solo professional isn't handed a restaurant's reservation /
        // ordering cards they can't use). Derived from account_type here.
        public bool $google_business_full_sync,
        // Business Partna accounts treat the connected Google Business name as
        // their public name — connecting (or reconnecting) overwrites display_name
        // with it. Standard accounts keep their own display name untouched.
        public bool $google_business_sets_display_name,
        // Business Partna accounts may pick the multi-page "atlas" skeleton — a
        // full multi-page website (Home / Menu / About / Book / Contact) with its
        // own nav. Standard accounts are single-view skeletons only. Derived from
        // account_type here so UpdateSiteRequest gates selection without branching
        // on the type directly (#30).
        public bool $can_use_multipage_site,
        // Standard (partna) accounts offer the lifestyle/creator content pages —
        // Listen (music) and Community (Strava/Skool). Business Partna
        // accounts don't: those integration groups
        // are hidden from their dashboard (Partna-Frontend
        // lib/integrations/platform-registry.ts → HIDDEN_GROUPS), so a business
        // account can't manage such a connection and shouldn't advertise the
        // page. Derived from account_type here so the sitepage presence gate
        // (SitepageDataResolverService::presentPageIds) doesn't branch on the
        // type directly. Shop is deliberately NOT covered — business accounts
        // keep Shop, managed via the dedicated Products page.
        public bool $can_use_lifestyle_pages,
        // Sector-derived (2026-07-15 industry/sector gating). "Food" = business
        // AND SectorTaxonomy::isFood(sector) — a business with no sector yet
        // reads as not-food (booking-only) until an industry is picked/synced.
        // A standard (partna) account is never food-gated at all: Menu stays
        // hidden for partna exactly as it was before this flag existed.
        public bool $can_use_menu,
        // Food businesses take table reservations instead of appointment
        // booking; every other account (including every partna account,
        // unconditionally) keeps reservations available.
        public bool $can_use_reservations,
        // The inverse of can_use_reservations for a business account — a food
        // business books via Reservations, not Booking. Partna is unconditionally
        // true (unchanged): every partna account keeps Booking.
        public bool $can_use_booking,
        // Online ordering is a food-business-only convenience; partna loses
        // access entirely (explicit owner override — matches nothing partna
        // could meaningfully do with it) and a non-food business never had a
        // menu/store to order from in the first place.
        public bool $can_use_online_ordering,
        // DISC-7: consent gate — a provisional/unclaimed subject has not
        // consented to auto-created platform connections seeded from scraped
        // data (e.g. InstagramAutoSync classifying a bio link). False only
        // while unclaimed; true for every claimed status.
        public bool $can_autosync_scraped_connections,
    ) {}
}
