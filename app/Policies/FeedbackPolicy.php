<?php

namespace App\Policies;

use App\Models\Core\Feedback;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Auth\Access\Response;

/**
 * Authorization for user-submitted Feedback rows.
 *
 * Owner-only read/list. Create gated on the can_submit_feedback capability
 * (always true now; reserved for future per-account bans). A staff
 * triage-write ability (update / delete-by-staff) is not yet wired to a
 * controller — OV-D only shipped the read side (staffView, below).
 */
class FeedbackPolicy extends BasePolicy
{
    public function view(User $actor, Feedback $feedback): bool|Response
    {
        if ((string) $feedback->user_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function create(User $actor, Feedback $skeleton): bool|Response
    {
        // Intentionally NOT calling denyIfPendingDeletion(): pending-deletion
        // users may be giving feedback about the deletion flow itself. The
        // route-level `withoutMiddleware([EnforcePendingDeletionReadOnly])`
        // and this policy stay aligned by design.
        if (! AccountCapabilities::for($actor)->can_submit_feedback) {
            return false;
        }

        return (string) $skeleton->user_id === (string) $actor->id;
    }

    public function delete(User $actor, Feedback $feedback): bool|Response
    {
        if ((string) $feedback->user_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function viewAny(User $actor): bool
    {
        // Owner listing of their own feedback. Scope is applied at query time
        // (where user_id = $actor->id); this just gates access to the endpoint.
        return true;
    }

    /**
     * OV-D: staff triage list (GET /staff/feedback) — any staff role, support
     * or admin. Mirrors EarlyAccessSignupPolicy::staffView / UserSegmentPolicy
     * ::staffView exactly; gated on the core.partna_staff role (the sole source
     * of truth for staff powers).
     */
    public function staffView(PartnaStaff $actor, Feedback|string|null $feedback = null): bool
    {
        return in_array($actor->role, [PartnaStaff::ROLE_SUPPORT, PartnaStaff::ROLE_ADMIN], true);
    }
}
