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

    /**
     * Re-pointing contact_email on an outreach build hands control of the
     * claim invite to whoever's address is set there — a site-takeover
     * primitive, not routine triage. Admin only; the route also sits behind
     * the staff.admin middleware (defence-in-depth, same pattern as
     * FeedbackPolicy::staffDelete).
     */
    public function staffAttachContactEmail(PartnaStaff $actor): bool
    {
        return $actor->isAdmin();
    }
}
