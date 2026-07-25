<?php

namespace App\Services\Accounts;

use App\Models\Core\User\User;
use App\Services\Profile\SectorTaxonomy;

/**
 * Runtime capability registry — answers "can this account access feature X right now?"
 * Most capabilities are constant; `can_book_storewide`, the Google Business sync
 * flags, and `can_use_multipage_site` derive from `account_type` — this is the
 * single sanctioned place that reads the type; everything else gates on the
 * derived capability. (Internal staff are NOT an account type — see PartnaStaff.)
 */
final class AccountCapabilities
{
    /**
     * Per-account memoization (audit SCALE-1). WeakMap so memoized instances
     * don't pin the account alive longer than necessary.
     */
    private static ?\WeakMap $cache = null;

    public static function for(User $pro): AccountCapabilitySet
    {
        self::$cache ??= new \WeakMap;
        if (isset(self::$cache[$pro])) {
            return self::$cache[$pro];
        }

        $set = self::individualCapabilities($pro);
        self::$cache[$pro] = $set;

        return $set;
    }

    /** Flush the per-instance cache. Tests call this when reassigning fields on a memoized account. */
    public static function flushCache(): void
    {
        self::$cache = null;
    }

    private static function individualCapabilities(User $pro): AccountCapabilitySet
    {
        $status = (string) ($pro->status ?? '');
        $isBusiness = $pro->isBusiness();
        // NULL sector reads as not-food (booking-only default) for a business
        // until an industry is picked (Settings) or synced (Google) — see
        // SectorTaxonomy::isFood(). Irrelevant for partna: none of the four
        // food-derived flags below branch on it for a partna account.
        $isFood = SectorTaxonomy::isFood($pro->sector);

        return new AccountCapabilitySet(
            can_edit_design: true,
            notification_categories: 'profile,platform',
            worker_kv_type: 'individual',
            can_submit_feedback: true,
            can_be_reported: $status === 'active',
            receive_moderation_notifications: in_array($status, ['active'], true),
            can_book_storewide: $isBusiness,
            google_business_full_sync: $isBusiness,
            google_business_sets_display_name: $isBusiness,
            can_use_multipage_site: $isBusiness,
            can_use_lifestyle_pages: ! $isBusiness,
            // Sector-derived (2026-07-15 industry/sector gating contract — LAW,
            // do not rederive): partna is never gated by sector, only business is.
            can_use_menu: $isBusiness && $isFood,
            can_use_reservations: $isBusiness ? $isFood : true,
            can_use_booking: $isBusiness ? ! $isFood : true,
            can_use_online_ordering: $isBusiness && $isFood,
            // Pre-account auto-sync gate (2026-07-25): previously gated on
            // !isUnclaimed() (DISC-7 consent). Removed — unclaimed users now
            // receive the same auto-sync as claimed users. Kept as an always-true
            // flag rather than deleted so it remains a one-line kill switch if the
            // consent question returns before pilot.
            can_autosync_scraped_connections: true,
        );
    }
}
