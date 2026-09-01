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
        // Sector-derived (2026-07-15 industry/sector gating), amended
        // 2026-09-01: "food" = SectorTaxonomy::isFood(sector), for EITHER
        // account type. The account_type half was dropped because a cafe is a
        // food account whatever enum it was filed under — see the clause in
        // AccountCapabilities for the accounts that proved it. An account with
        // no sector yet still reads as not-food until one is picked manually or
        // synced from Instagram; the Google sync does NOT fill it for a partna
        // account (IdentitySync::applySector is business-only by decision 12),
        // so "synced" is not the blanket the amending commit assumed — the
        // correction is spelled out at the clause itself.
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
        // content.curate_identity (plan §5): may this account merge, split or
        // dismiss possible-duplicate items? Both account types get it — the
        // gate exists so identity curation has a named place to be withdrawn
        // from (an abusive account, a support freeze) without a controller
        // change, and so the endpoints comply with the doctrine that every new
        // endpoint consults AccountCapabilities.
        public bool $can_curate_identity,
        // Is the reviews pool scoped to the PERSON rather than the venue
        // (owner, 2026-08-28)? True for a partna account: a venue-level
        // review source (Google listing, Booksy/Treatwell page, storewide
        // Fresha) reviews the WORKPLACE, and an individual's page shows only
        // the reviews attributable to them — Fresha's structured staff
        // attribution, or a mention of their name in the review text
        // (PoolResolver::reviewsOutsidePersonScope). False for a business:
        // the venue's reviews ARE its reviews. Applies pre-claim too — an
        // unclaimed partna build scopes by the display name the build set.
        public bool $reviews_scoped_to_person,
        // Does the WORKPLACE's brand (its logo, square mark, and the design
        // evidence scraped off its previous website — accent, font) stand for
        // the SITE? True for a business: the workplace IS the account. False
        // for a partna account (owner, 2026-08-19): a hairdresser's site never
        // wears the salon's logo — the workplace mark only ever appears
        // inside the site's Workplace card, and the dashboard's own chrome
        // (account menu, sidebar) never borrows it either. Gates
        // LogoAutoGrabber, ResolveSiteAccentJob and the font autopilot in
        // ScanPreviousWebsiteContentJob, and the dashboard's use of the mark.
        public bool $workplace_brand_is_site_identity,
    ) {}
}
