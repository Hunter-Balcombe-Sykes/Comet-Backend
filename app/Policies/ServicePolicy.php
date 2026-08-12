<?php

namespace App\Policies;

use App\Models\Core\User\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * V2: Authorization for Service and ServiceCategory records owned by a Professional.
 *
 * Both models carry user_id directly (Shape A — simple direct ownership).
 * Denials on route-bound resources return 404 to avoid leaking existence to non-owners.
 * Uses `Model` for parameter types to cover both Service and ServiceCategory with one policy class —
 * narrowing to concrete types would require separate policies.
 */
class ServicePolicy extends BasePolicy
{
    public function view(User $actor, Model $resource): bool|Response
    {
        if ((string) $resource->user_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function create(User $actor, Model $skeleton): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        return (string) $skeleton->user_id === (string) $actor->id;
    }

    public function update(User $actor, Model $resource): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        if ((string) $resource->user_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function delete(User $actor, Model $resource): bool|Response
    {
        return $this->update($actor, $resource);
    }

    /**
     * Category assignment, restricted to Fresha-projected services.
     * Slice 3a (§7 "Out of scope — carried to 3b"): owner-authored
     * (source IS NULL) categories have no destination in content.* yet —
     * 3b lands content.collections for them. Until then,
     * SitepageDataResolverService::buildServicesData() hardcodes
     * 'category' => 'Services' for every manual row; that constant is only
     * honest while this restriction holds, so if one of the two moves the
     * other must move with it. Denies as not-found (not a new 403 shape) —
     * same posture the rest of this policy uses for "can't act on this yet".
     */
    public function updateCategory(User $actor, Model $resource): bool|Response
    {
        $updateResult = $this->update($actor, $resource);
        if ($updateResult !== true) {
            return $updateResult;
        }

        if ((string) ($resource->source ?? '') !== 'fresha') {
            return $this->denyAsNotFound();
        }

        return true;
    }
}
