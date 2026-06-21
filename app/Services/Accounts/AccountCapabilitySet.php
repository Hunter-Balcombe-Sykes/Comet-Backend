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
     * @param  string  $notification_categories  Comma-separated list of allowed categories.
     *                                           'full' means every category in the registry.
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
    ) {}
}
