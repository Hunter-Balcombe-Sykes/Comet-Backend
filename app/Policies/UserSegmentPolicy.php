<?php

namespace App\Policies;

use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Segments\UserSegmentMember;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;

/**
 * OV-A: segments are staff-only resources. User-actor methods deny everything
 * (defensive — a misconfigured non-staff route can't grant access); staff-actor
 * methods encode the role rule (support = view, admin = manage). The
 * core.partna_staff role is the sole source of truth for staff powers.
 */
class UserSegmentPolicy extends BasePolicy
{
    public function viewAny(User $pro): bool
    {
        return false;
    }

    public function view(User $pro, UserSegment|UserSegmentMember $resource): bool
    {
        return false;
    }

    public function manage(User $pro, UserSegment|UserSegmentMember|null $resource = null): bool
    {
        return false;
    }

    /** Any staff role can view segments (lists, member previews, counts). */
    public function staffView(PartnaStaff $actor, UserSegment|string|null $segment = null): bool
    {
        return in_array($actor->role, [PartnaStaff::ROLE_SUPPORT, PartnaStaff::ROLE_ADMIN], true);
    }

    /** Create/update/delete + member management — admin only (staff_manage_segments). */
    public function staffManage(PartnaStaff $actor, UserSegment|string|null $segment = null): bool
    {
        return $actor->isAdmin();
    }
}
