<?php

namespace App\Policies;

use App\Models\Core\Notifications\Notification;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * V2: Authorization for notification records.
 *
 * Notification.user_id is nullable: null = global broadcast (visible to
 * all professionals). Targeted notifications (non-null) are only visible to the
 * matching professional.
 *
 * NotificationEmailPreference, NotificationEmailPolicy, NotificationReceipt,
 * EmailSubscription all use standard direct user_id ownership.
 */
class NotificationPolicy extends BasePolicy
{
    public function view(User $actor, Model $resource): bool|Response
    {
        // Global notifications (null user_id) are visible to all.
        if ($resource instanceof Notification && $resource->user_id === null) {
            return true;
        }

        if ((string) ($resource->user_id ?? '') !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function update(User $actor, Model $resource): bool|Response
    {
        // Global notifications have no single owner — deny all mutations.
        if ($resource instanceof Notification && $resource->user_id === null) {
            return $this->denyAsNotFound();
        }

        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        if ((string) ($resource->user_id ?? '') !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function delete(User $actor, Model $resource): bool|Response
    {
        return $this->update($actor, $resource);
    }

    /**
     * OV-A hardening: staff broadcast composer (StaffNotificationController::store)
     * — admin only, mirroring the `staff.admin` route middleware for
     * defence-in-depth parity with the other staff write controllers
     * (UserSegmentPolicy / EarlyAccessSignupPolicy ::staffManage). Accepts the
     * class-string form (`authorizeForUser($staff, 'staffManage', Notification::class)`).
     */
    public function staffManage(PartnaStaff $actor, Notification|string|null $notification = null): bool
    {
        return $actor->isAdmin();
    }

    /**
     * Read-access seam for staff notification surfaces (#SEC-1/#SEC-5) — any
     * staff role. Mirrors UserSelfPolicy::staffView exactly: there is no PII
     * gate to enforce here (notification bodies aren't PII), this ability
     * exists purely so every staff read has an explicit, auditable
     * authorization point instead of none at all. Accepts the class-string
     * form for the two global-scope reads (notification list, email policies)
     * that have no single target row.
     */
    public function staffView(PartnaStaff $actor, Notification|string|null $notification = null): bool
    {
        return true;
    }
}
