<?php

namespace App\Services\Accounts;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;

/**
 * Runtime capability registry — answers "can this Professional access feature X right now?"
 * Most capabilities are constant; `can_book_storewide` is derived from `account_type`,
 * and staff accounts derive is_staff + granular staff powers here too (the single
 * sanctioned place that reads the type — everything else gates on the capability).
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
        if ($pro->isStaff()) {
            return self::staffCapabilities($pro);
        }

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

    /**
     * Internal staff account: every user-facing capability is off (no site, no
     * integrations, no design, not reportable); granular staff powers come from
     * the linked core.partna_staff role via staffPowers() below.
     */
    private static function staffCapabilities(User $pro): AccountCapabilitySet
    {
        $powers = self::staffPowers(self::staffRole($pro));

        return new AccountCapabilitySet(
            can_edit_design: false,
            notification_categories: 'staff',
            worker_kv_type: 'individual', // vestigial — staff have no site, so KV sync never runs
            can_submit_feedback: false,
            can_be_reported: false,
            receive_moderation_notifications: false,
            can_book_storewide: false,
            google_business_full_sync: false,
            google_business_sets_display_name: false,
            can_use_multipage_site: false,
            is_staff: true,
            can_have_site: false,
            can_connect_integrations: false,
            staff_manage_users: $powers['staff_manage_users'],
            staff_manage_segments: $powers['staff_manage_segments'],
            staff_manage_availability: $powers['staff_manage_availability'],
            staff_send_notifications: $powers['staff_send_notifications'],
            staff_manage_early_access: $powers['staff_manage_early_access'],
            staff_view_aggregate_analytics: $powers['staff_view_aggregate_analytics'],
            staff_view_feedback: $powers['staff_view_feedback'],
        );
    }

    /**
     * SINGLE source of truth for the staff role → power mapping. The staff
     * Policies (UserSegmentPolicy, FeatureAvailabilityPolicy, EarlyAccessSignup
     * Policy, UserSelfPolicy::staff*) enforce the same rule via
     * PartnaStaff::isAdmin() on the request actor — keep the two in sync.
     *
     * support → view-level powers only; admin → view + manage; no partna_staff
     * row (misprovisioned staff account) → no powers at all.
     *
     * @return array{staff_manage_users:bool, staff_manage_segments:bool, staff_manage_availability:bool, staff_send_notifications:bool, staff_manage_early_access:bool, staff_view_aggregate_analytics:bool, staff_view_feedback:bool}
     */
    private static function staffPowers(?string $role): array
    {
        $isStaffRole = in_array($role, [PartnaStaff::ROLE_SUPPORT, PartnaStaff::ROLE_ADMIN], true);
        $isAdmin = $role === PartnaStaff::ROLE_ADMIN;

        return [
            'staff_manage_users' => $isAdmin,
            'staff_manage_segments' => $isAdmin,
            'staff_manage_availability' => $isAdmin,
            'staff_send_notifications' => $isAdmin,
            'staff_manage_early_access' => $isAdmin,
            'staff_view_aggregate_analytics' => $isStaffRole,
            'staff_view_feedback' => $isStaffRole,
        ];
    }

    /** Role of the partna_staff row sharing this account's auth user, if any. */
    private static function staffRole(User $pro): ?string
    {
        if (! is_string($pro->auth_user_id) || $pro->auth_user_id === '') {
            return null;
        }

        $role = PartnaStaff::query()
            ->where('auth_user_id', $pro->auth_user_id)
            ->value('role');

        return is_string($role) ? $role : null;
    }
}
