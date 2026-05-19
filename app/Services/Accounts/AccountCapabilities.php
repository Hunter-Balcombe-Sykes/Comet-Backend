<?php

namespace App\Services\Accounts;

use App\Enums\AccountType;
use App\Models\Core\Professional\Professional;

/**
 * Runtime capability registry — answers "can this Professional access feature X right now?"
 * Plan §28.3 + §9 matrix.
 *
 * Reconciliation with {@see \App\Services\Professional\AccountTypeDefaultsService}:
 * the two services answer different questions and stay separate.
 *
 *  | Service                       | Question                              | When it runs       |
 *  | ----------------------------- | ------------------------------------- | ------------------ |
 *  | AccountTypeDefaultsService    | "What should this account be seeded   | Once, at signup,   |
 *  |                               |  with at creation?"                   | via BootstrapCtrl. |
 *  | AccountCapabilities (this)    | "Can this account access feature X    | Every API request, |
 *  |                               |  right now?"                          | every job, every   |
 *  |                               |                                       | route guard.       |
 *
 * Both read AccountType so values can't drift.
 *
 * Defaults-service still keys off the legacy professional_type string today; migration to
 * account_type is tracked under §28.11's broader read migration and isn't blocking.
 *
 * @see docs/PARTNA-STANDALONE-PAGES-NEW-DIRECTION-2.md §9, §28.3
 */
final class AccountCapabilities
{
    /**
     * Per-Professional memoization (audit SCALE-1). Notification fan-outs over
     * many professionals previously rebuilt the value object N times per row;
     * once §28.16's denorm-column read becomes part of individualCapabilities,
     * that pattern is worth avoiding. WeakMap so memoized instances don't pin
     * the Professional alive longer than necessary.
     */
    private static ?\WeakMap $cache = null;

    public static function for(Professional $pro): AccountCapabilitySet
    {
        self::$cache ??= new \WeakMap;
        if (isset(self::$cache[$pro])) {
            return self::$cache[$pro];
        }

        // Fall through to individual for legacy rows still in the dual-write window.
        // After the §28.1 SET NOT NULL promotion lands in production, account_type
        // is guaranteed non-null and the fallback becomes unreachable. The default
        // arm catches any future enum case added without an explicit branch —
        // fail-safe over UnhandledMatchError.
        $set = match ($pro->account_type) {
            AccountType::Brand => self::brandCapabilities(),
            AccountType::Partner => self::partnerCapabilities(),
            AccountType::Individual, null => self::individualCapabilities($pro),
            default => self::individualCapabilities($pro),
        };

        self::$cache[$pro] = $set;

        return $set;
    }

    /** Flush the per-instance cache. Tests call this when reassigning fields on a memoized Professional. */
    public static function flushCache(): void
    {
        self::$cache = null;
    }

    private static function brandCapabilities(): AccountCapabilitySet
    {
        return new AccountCapabilitySet(
            requires_stripe_connect: true,
            requires_tax_info: true,
            requires_payout_schedule: true,
            shows_shop_section: true,
            shows_commissions_dashboard: true,
            shows_orders_dashboard: true,
            shows_affiliates_dashboard: true,
            shows_ex_partner_panel: false,
            receives_order_notifications: true,
            receives_payout_notifications: true,
            receives_payout_settlement_notifications: true,
            receives_commission_notifications: true,
            receives_brand_status_notifications: false,
            receives_invite_notifications: false,
            can_have_brand_link: false,
            can_edit_design: true,
            notification_categories: 'full',
            worker_kv_type: 'brand',
        );
    }

    private static function partnerCapabilities(): AccountCapabilitySet
    {
        return new AccountCapabilitySet(
            requires_stripe_connect: true,
            requires_tax_info: true,
            requires_payout_schedule: true,
            shows_shop_section: true,
            shows_commissions_dashboard: true,
            shows_orders_dashboard: true,
            shows_affiliates_dashboard: false,
            shows_ex_partner_panel: false,
            receives_order_notifications: true,
            receives_payout_notifications: true,
            receives_payout_settlement_notifications: true,
            receives_commission_notifications: true,
            receives_brand_status_notifications: true,
            receives_invite_notifications: true,
            // Partner design inherits from the brand — no per-affiliate overrides
            // exist (plan rule #6). Editor surface is hidden.
            can_have_brand_link: true,
            can_edit_design: false,
            notification_categories: 'full',
            worker_kv_type: 'affiliate',
        );
    }

    private static function individualCapabilities(Professional $pro): AccountCapabilitySet
    {
        // shows_ex_partner_panel and receives_payout_settlement_notifications are
        // conditional on historical data (former brand-link, pending payouts).
        // The denormalized boolean column + brandPartnerLinksAll() relationship
        // land in §28.16; until then, individuals are treated as no-history.
        // SCALE-1 will swap in the denorm lookup once the column exists.
        $hasHistoricalLinks = false;
        $hasPendingPayoutHistory = false;

        return new AccountCapabilitySet(
            requires_stripe_connect: false,
            requires_tax_info: false,
            requires_payout_schedule: false,
            shows_shop_section: false,
            shows_commissions_dashboard: false,
            shows_orders_dashboard: false,
            shows_affiliates_dashboard: false,
            shows_ex_partner_panel: $hasHistoricalLinks,
            receives_order_notifications: false,
            receives_payout_notifications: false,
            receives_payout_settlement_notifications: $hasPendingPayoutHistory,
            receives_commission_notifications: false,
            receives_brand_status_notifications: false,
            receives_invite_notifications: true,
            can_have_brand_link: true,
            can_edit_design: true,
            // Individuals get only the filtered subset until §28.16 wires the
            // payout-history check (then payout_settlement is added conditionally).
            notification_categories: 'profile,platform,invites',
            worker_kv_type: 'individual',
        );
    }
}
