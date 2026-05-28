<?php

namespace App\Policies;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Moderation\Decision;

/**
 * Decision authorization. All operations staff-only.
 */
class DecisionPolicy extends BasePolicy
{
    // Staff can view any decision record.
    public function view(PartnaStaff $staff, Decision $decision): bool
    {
        return true;
    }

    // Reversals go through artisan moderation:reverse-decision (day-one).
    public function reverse(PartnaStaff $staff, Decision $decision): bool
    {
        return true;
    }
}
