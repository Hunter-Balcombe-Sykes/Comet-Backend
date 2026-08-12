<?php

namespace App\Policies;

use App\Models\Core\User\User;
use App\Site\Pools\BorrowedMedia;
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

    /**
     * Slice 1b D5 — pinning an item into a pool's curated half.
     *
     * Ownership is checked FIRST and denies as 404, before the borrowed test
     * runs. Reversed, a stranger probing uuids would learn which ones are real
     * Google media items from the 403 — the enumeration oracle the class
     * docblock above exists to close.
     *
     * The 403 here is a capability restriction on a resource the actor
     * legitimately owns, not a hidden resource, which is why it is not the 404
     * every other denial on this policy returns.
     */
    public function pin(User $actor, Model $resource): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        if (! $this->ownerMatches($actor, $resource)) {
            return $this->denyAsNotFound();
        }

        return BorrowedMedia::isBorrowed($resource)
            ? Response::deny('This photo comes from Google and cannot be pinned.')
            : true;
    }

    private function ownerMatches(User $actor, Model $resource): bool
    {
        $rawAttributes = $resource->getAttributes();

        return array_key_exists('user_id', $rawAttributes)
            && $rawAttributes['user_id'] !== null
            && (string) $rawAttributes['user_id'] === (string) $actor->id;
    }
}
