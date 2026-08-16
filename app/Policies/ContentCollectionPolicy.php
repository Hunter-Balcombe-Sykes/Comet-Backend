<?php

namespace App\Policies;

use App\Models\Content\Storefront;
use App\Models\Core\User\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Authorization for content.collections and its content.storefronts sidecar
 * (Slice 5a §3.1).
 *
 * Collection carries user_id directly (Shape A — ContentItemPolicy precedent).
 * Storefront has no user_id column of its own; ownership resolves through its
 * parent collection's user_id (Shape B — SectionPolicy precedent),
 * so the caller must have the collection relation loaded.
 *
 * Denials on route-bound resources return 404 rather than 403: an actor here
 * has already submitted a valid UUID, and confirming that it exists would
 * hand them an enumeration oracle.
 */
class ContentCollectionPolicy extends BasePolicy
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
        $userId = $resource instanceof Storefront
            ? $this->collectionUserId($resource)
            : $this->directUserId($resource);

        return $userId !== null && (string) $userId === (string) $actor->id;
    }

    private function directUserId(Model $resource): ?string
    {
        $rawAttributes = $resource->getAttributes();

        return array_key_exists('user_id', $rawAttributes) ? $rawAttributes['user_id'] : null;
    }

    // relationLoaded() first — getRelation() THROWS on an unloaded relation
    // rather than returning null, so this guard never ran and a missing
    // collection relation surfaced as a 500 (SectionPolicy precedent).
    private function collectionUserId(Storefront $resource): ?string
    {
        $collection = $resource->relationLoaded('collection') ? $resource->getRelation('collection') : null;

        return $collection?->user_id;
    }
}
