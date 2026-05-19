<?php

namespace App\Services\Accounts;

/**
 * Snapshot of what a Professional can do RIGHT NOW. Built by {@see AccountCapabilities}.
 *
 * Read-only. Construct once per Professional per request and pass it around — capability
 * checks are pure functions on this value object so a single instance can be reused freely.
 *
 * The 16 boolean flags + 2 configuration values mirror plan §9's matrix:
 *
 * @see docs/PARTNA-STANDALONE-PAGES-NEW-DIRECTION-2.md §9
 */
final readonly class AccountCapabilitySet
{
    /**
     * @param  string  $notification_categories  Comma-separated list of allowed categories.
     *                                           'full' means every category in the registry.
     * @param  string  $worker_kv_type  Routing tag written to SUBDOMAIN_KV by
     *                                  SyncSubdomainToKvJob. One of: brand|affiliate|individual.
     */
    public function __construct(
        // Onboarding + payout requirements
        public bool $requires_stripe_connect,
        public bool $requires_tax_info,
        public bool $requires_payout_schedule,
        // Dashboard surfaces
        public bool $shows_shop_section,
        public bool $shows_commissions_dashboard,
        public bool $shows_orders_dashboard,
        public bool $shows_affiliates_dashboard,
        public bool $shows_ex_partner_panel,
        // Notification gates (dispatcher-layer — defence-in-depth)
        public bool $receives_order_notifications,
        public bool $receives_payout_notifications,
        public bool $receives_payout_settlement_notifications,
        public bool $receives_commission_notifications,
        public bool $receives_brand_status_notifications,
        public bool $receives_invite_notifications,
        // Relationship + editor surfaces
        public bool $can_have_brand_link,
        public bool $can_edit_design,
        // Configuration values
        public string $notification_categories,
        public string $worker_kv_type,
    ) {}
}
