<?php

namespace App\Policies;

use App\Models\Core\FeatureAvailabilityRule;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;

/**
 * OV-A: feature-availability rules are staff-only. Deny-all for User actors;
 * staff-actor methods encode support=view / admin=manage, gated on the
 * core.partna_staff role (the sole source of truth for staff powers).
 */
class FeatureAvailabilityPolicy extends BasePolicy
{
    public function viewAny(User $pro): bool
    {
        return false;
    }

    public function view(User $pro, FeatureAvailabilityRule $rule): bool
    {
        return false;
    }

    public function manage(User $pro, ?FeatureAvailabilityRule $rule = null): bool
    {
        return false;
    }

    public function staffView(PartnaStaff $actor, FeatureAvailabilityRule|string|null $rule = null): bool
    {
        return in_array($actor->role, [PartnaStaff::ROLE_SUPPORT, PartnaStaff::ROLE_ADMIN], true);
    }

    public function staffManage(PartnaStaff $actor, FeatureAvailabilityRule|string|null $rule = null): bool
    {
        return $actor->isAdmin();
    }
}
