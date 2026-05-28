<?php

namespace App\Policies;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Models\Moderation\ModerationCase;

/**
 * Moderation case authorization. All case operations are staff-only.
 * Users have no visibility into the moderation pipeline.
 */
class CasePolicy extends BasePolicy
{
    // Staff can view any case (queue listing).
    public function viewAny(User|PartnaStaff $actor): bool
    {
        return $actor instanceof PartnaStaff;
    }

    // Staff can view an individual case.
    public function view(User|PartnaStaff $actor, ModerationCase $case): bool
    {
        return $actor instanceof PartnaStaff;
    }

    // All active staff can triage.
    public function triage(PartnaStaff $staff, ModerationCase $case): bool
    {
        return true;
    }

    // All active staff can take a case.
    public function take(PartnaStaff $staff, ModerationCase $case): bool
    {
        return true;
    }

    // All active staff can release a case.
    public function release(PartnaStaff $staff, ModerationCase $case): bool
    {
        return true;
    }

    // All active staff can record a decision.
    public function decide(PartnaStaff $staff, ModerationCase $case): bool
    {
        return true;
    }

    // All active staff can escalate.
    public function escalate(PartnaStaff $staff, ModerationCase $case): bool
    {
        return true;
    }
}
