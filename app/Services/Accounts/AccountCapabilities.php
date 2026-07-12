<?php

namespace App\Services\Accounts;

use App\Models\Core\User\User;

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

        return new AccountCapabilitySet(
            can_edit_design: true,
            notification_categories: 'profile,platform',
            worker_kv_type: 'individual',
            can_submit_feedback: true,
            can_be_reported: $status === 'active',
            receive_moderation_notifications: in_array($status, ['active'], true),
            can_book_storewide: $pro->isBusiness(),
            google_business_full_sync: $pro->isBusiness(),
            google_business_sets_display_name: $pro->isBusiness(),
            can_use_multipage_site: $pro->isBusiness(),
        );
    }
}
