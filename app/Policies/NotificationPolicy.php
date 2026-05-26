<?php

namespace App\Policies;

use App\Models\Core\Notifications\Notification;
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
}
