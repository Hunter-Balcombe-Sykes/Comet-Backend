<?php

namespace App\Services\Accounts;

use App\Models\Core\Professional\Professional;

/**
 * Runtime capability registry — answers "can this Professional access feature X right now?"
 * Standalone user-only: all accounts are individual; capabilities are constant.
 *
 * @see docs/PARTNA-STANDALONE-PAGES-NEW-DIRECTION-2.md §9, §28.3
 */
final class AccountCapabilities
{
    /**
     * Per-Professional memoization (audit SCALE-1). WeakMap so memoized instances
     * don't pin the Professional alive longer than necessary.
     */
    private static ?\WeakMap $cache = null;

    public static function for(Professional $pro): AccountCapabilitySet
    {
        self::$cache ??= new \WeakMap;
        if (isset(self::$cache[$pro])) {
            return self::$cache[$pro];
        }

        $set = self::individualCapabilities($pro);
        self::$cache[$pro] = $set;

        return $set;
    }

    /** Flush the per-instance cache. Tests call this when reassigning fields on a memoized Professional. */
    public static function flushCache(): void
    {
        self::$cache = null;
    }

    private static function individualCapabilities(Professional $pro): AccountCapabilitySet
    {
        return new AccountCapabilitySet(
            requires_stripe_connect: false,
            requires_tax_info: false,
            requires_payout_schedule: false,
            shows_shop_section: false,
            shows_commissions_dashboard: false,
            shows_orders_dashboard: false,
            shows_affiliates_dashboard: false,
            shows_ex_partner_panel: false,
            receives_order_notifications: false,
            receives_payout_notifications: false,
            receives_payout_settlement_notifications: false,
            receives_commission_notifications: false,
            receives_brand_status_notifications: false,
            receives_invite_notifications: false,
            can_have_brand_link: false,
            can_edit_design: true,
            notification_categories: 'profile,platform',
            worker_kv_type: 'individual',
        );
    }
}
