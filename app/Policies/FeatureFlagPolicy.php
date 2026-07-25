<?php

namespace App\Policies;

use App\Models\Core\FeatureFlag;
use App\Models\Core\FeatureFlagOverride;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;

/**
 * Defensive deny-all policy for FeatureFlag and FeatureFlagOverride.
 *
 * Real auth: the EnsurePartnaStaff middleware on the staff route group
 * (supabase.jwt + staff + staff.admin + throttle:staff). The three
 * User-typed methods below return false unconditionally so that a
 * misconfigured non-staff route cannot grant access to a Professional
 * actor via Gate::forUser($pro) — this shield must never be widened or
 * removed.
 *
 * PartnaStaff actors are dispatched to the staff* abilities below by
 * first-parameter type (Laravel's Gate has no overload resolution — see
 * UserSelfPolicy's doctrine docblock). They must NEVER be passed to
 * viewAny/view/manage: that raises an uncaught TypeError (500), not a 403.
 */
class FeatureFlagPolicy extends BasePolicy
{
    public function viewAny(User $pro): bool
    {
        return false;
    }

    public function view(User $pro, FeatureFlag|FeatureFlagOverride $resource): bool
    {
        return false;
    }

    public function manage(User $pro, FeatureFlag|FeatureFlagOverride|null $resource = null): bool
    {
        return false;
    }

    /**
     * Read seam for the staff flag surfaces (#FFLAG-2). Support + admin, mirroring
     * FeatureAvailabilityPolicy::staffView — the closest structural twin (staff-only
     * platform config, same deny-all-User shield). Both read routes sit in staff.admin,
     * so this is defence-in-depth today; it exists so the reads have an explicit,
     * auditable authorization point and a seam to tighten if they are ever relaxed to
     * the any-staff group. Accepts the class-string form for the two collection reads.
     */
    public function staffView(PartnaStaff $actor, FeatureFlag|FeatureFlagOverride|string|null $resource = null): bool
    {
        return in_array($actor->role, [PartnaStaff::ROLE_SUPPORT, PartnaStaff::ROLE_ADMIN], true);
    }

    /**
     * Write seam for staff flag + override mutations (#FFLAG-2) — admin only, mirroring
     * the staff.admin route middleware for defence-in-depth parity with
     * FeatureAvailabilityPolicy::staffManage / NotificationPolicy::staffManage.
     */
    public function staffManage(PartnaStaff $actor, FeatureFlag|FeatureFlagOverride|string|null $resource = null): bool
    {
        return $actor->isAdmin();
    }
}
