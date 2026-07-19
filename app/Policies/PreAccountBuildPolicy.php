<?php

namespace App\Policies;

use App\Models\Core\Staff\PartnaStaff;

class PreAccountBuildPolicy extends BasePolicy
{
    /** Any staff member may trigger a marketing build (route already enforces AAL2). */
    public function staffCreate(PartnaStaff $actor): bool
    {
        return true;
    }
}
