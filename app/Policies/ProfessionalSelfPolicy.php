<?php

namespace App\Policies;

use App\Models\Core\Professional\ProfessionalDeletionAuditEntry;
use App\Models\Core\Professional\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * V2: Authorization for records where the actor can only access their own data.
 *
 * Covers Professional (id-based), ProfessionalConfirmationPreference (professional_id),
 * and ProfessionalDeletionAuditEntry (read-only).
 *
 * Audit-log models (ProfessionalDeletionAuditEntry) are immutable — update/delete
 * are blocked by the policy regardless of ownership.
 */
class ProfessionalSelfPolicy extends BasePolicy
{
    public function view(User $actor, Model $resource): bool|Response
    {
        if ($this->resolveOwnerId($resource) !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function update(User $actor, Model $resource): bool|Response
    {
        // Audit-log models are append-only — deny all mutations regardless of ownership.
        if ($resource instanceof ProfessionalDeletionAuditEntry) {
            return $this->denyAsNotFound();
        }

        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        if ($this->resolveOwnerId($resource) !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function delete(User $actor, Model $resource): bool|Response
    {
        return $this->update($actor, $resource);
    }

    /**
     * Professional itself is the root record — ownership is $resource->id.
     * All other covered models carry professional_id directly.
     */
    private function resolveOwnerId(Model $resource): string
    {
        if ($resource instanceof User) {
            return (string) $resource->id;
        }

        return (string) ($resource->professional_id ?? '');
    }
}
