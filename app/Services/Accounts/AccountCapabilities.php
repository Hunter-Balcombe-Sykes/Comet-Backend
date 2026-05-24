<?php

namespace App\Services\Accounts;

use App\Models\Core\Professional\User;

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

    /** Flush the per-instance cache. Tests call this when reassigning fields on a memoized Professional. */
    public static function flushCache(): void
    {
        self::$cache = null;
    }

    private static function individualCapabilities(User $pro): AccountCapabilitySet
    {
        return new AccountCapabilitySet(
            can_edit_design: true,
            notification_categories: 'profile,platform',
            worker_kv_type: 'individual',
        );
    }
}
