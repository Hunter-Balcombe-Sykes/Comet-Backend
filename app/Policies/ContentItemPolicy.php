<?php

namespace App\Policies;

use App\Models\Core\User\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Authorization for content.items.
 *
 * Carries user_id directly (Shape A). Denials on route-bound resources
 * return 404 rather than 403: an actor here has already submitted a valid
 * UUID, and confirming that it exists would hand them an enumeration oracle.
 *
 * manual_overrides are NOT covered here — they are reached only through their
 * parent item's route, so authorising the item authorises them (MenuItem
 * precedent, POLICY_EXEMPT in PolicyCoverageTest).
 */
class ContentItemPolicy extends BasePolicy
{
    public function view(User $actor, Model $resource): bool|Response
    {
        return $this->ownerMatches($actor, $resource)
            ? true
            : $this->denyAsNotFound();
    }

    public function update(User $actor, Model $resource): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        return $this->ownerMatches($actor, $resource)
            ? true
            : $this->denyAsNotFound();
    }

    public function delete(User $actor, Model $resource): bool|Response
    {
        return $this->update($actor, $resource);
    }

    private function ownerMatches(User $actor, Model $resource): bool
    {
        $rawAttributes = $resource->getAttributes();

        return array_key_exists('user_id', $rawAttributes)
            && $rawAttributes['user_id'] !== null
            && (string) $rawAttributes['user_id'] === (string) $actor->id;
    }
}
