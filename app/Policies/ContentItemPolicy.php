<?php

namespace App\Policies;

use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Authorization for content.items and content.identity_candidates.
 *
 * Both carry user_id directly (Shape A). Denials on route-bound resources
 * return 404 rather than 403: an actor here has already submitted a valid
 * UUID, and confirming that it exists would hand them an enumeration oracle.
 *
 * `curate` is separate from `update` because merging two items is a different
 * act from editing one: it is gated by the content.curate_identity capability
 * (plan §5), so the gate has somewhere to live if it is ever withdrawn from an
 * account.
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

    /** Rule on a duplicate: merge, split, or dismiss. */
    public function curate(User $actor, Model $resource): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        if (! $this->ownerMatches($actor, $resource)) {
            return $this->denyAsNotFound();
        }

        // A capability denial is a genuine 403: the actor owns the row, they
        // simply may not perform this act — nothing is leaked by saying so.
        return AccountCapabilities::for($actor)->can_curate_identity
            ? true
            : Response::deny('Identity curation is not available on this account.');
    }

    private function ownerMatches(User $actor, Model $resource): bool
    {
        $rawAttributes = $resource->getAttributes();

        return array_key_exists('user_id', $rawAttributes)
            && $rawAttributes['user_id'] !== null
            && (string) $rawAttributes['user_id'] === (string) $actor->id;
    }
}
