<?php

namespace App\Policies;

use App\Models\Core\EarlyAccess\EarlyAccessSignup;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;

/**
 * OV-A: early-access rows are staff-managed (the public create path is
 * unauthenticated and never touches the Gate). Deny-all for User actors;
 * staff-actor methods encode support=view / admin=manage+invite, mirroring
 * AccountCapabilities::staffPowers() (staff_manage_early_access).
 */
class EarlyAccessSignupPolicy extends BasePolicy
{
    public function viewAny(User $pro): bool
    {
        return false;
    }

    public function view(User $pro, EarlyAccessSignup $signup): bool
    {
        return false;
    }

    public function manage(User $pro, ?EarlyAccessSignup $signup = null): bool
    {
        return false;
    }

    public function staffView(PartnaStaff $actor, EarlyAccessSignup|string|null $signup = null): bool
    {
        return in_array($actor->role, [PartnaStaff::ROLE_SUPPORT, PartnaStaff::ROLE_ADMIN], true);
    }

    /** List-edit, manual add, delete AND invite sends — admin only. */
    public function staffManage(PartnaStaff $actor, EarlyAccessSignup|string|null $signup = null): bool
    {
        return $actor->isAdmin();
    }
}
