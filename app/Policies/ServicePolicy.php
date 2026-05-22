<?php

namespace App\Policies;

use App\Models\Core\Professional\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * V2: Authorization for Service and ServiceCategory records owned by a Professional.
 *
 * Both models carry professional_id directly (Shape A — simple direct ownership).
 * Denials on route-bound resources return 404 to avoid leaking existence to non-owners.
 * Uses `Model` for parameter types to cover both Service and ServiceCategory with one policy class —
 * narrowing to concrete types would require separate policies.
 */
class ServicePolicy extends BasePolicy
{
    public function view(User $actor, Model $resource): bool|Response
    {
        if ((string) $resource->professional_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function create(User $actor, Model $skeleton): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        return (string) $skeleton->professional_id === (string) $actor->id;
    }

    public function update(User $actor, Model $resource): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        if ((string) $resource->professional_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function delete(User $actor, Model $resource): bool|Response
    {
        return $this->update($actor, $resource);
    }
}
