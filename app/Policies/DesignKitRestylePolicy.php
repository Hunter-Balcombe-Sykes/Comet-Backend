<?php

namespace App\Policies;

use App\Models\Core\User\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Authorization for site.design_kit_restyles (plan §13).
 *
 * Like site.pages/sections, the table carries site_id rather than user_id, so
 * ownership resolves through the site relation with the same independent
 * cross-check SectionPolicy performs: the caller setRelation()s the ACTOR's
 * own site, and this policy verifies the row's site_id actually matches it —
 * a mismatch is a 404, never a 403 (existence must not leak).
 */
class DesignKitRestylePolicy extends BasePolicy
{
    public function view(User $actor, Model $resource): bool|Response
    {
        return $this->ownerMatches($actor, $resource)
            ? true
            : $this->denyAsNotFound();
    }

    public function create(User $actor, Model $skeleton): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        return $this->ownerMatches($actor, $skeleton)
            ? true
            : $this->denyAsNotFound();
    }

    /** Undo is the only mutation a restyle row supports. */
    public function update(User $actor, Model $resource): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        return $this->ownerMatches($actor, $resource)
            ? true
            : $this->denyAsNotFound();
    }

    private function ownerMatches(User $actor, Model $resource): bool
    {
        $site = $resource->getRelation('site');

        if ($site === null) {
            return false;
        }

        $resourceSiteId = $resource->getAttributes()['site_id'] ?? null;

        if ($resourceSiteId === null || (string) $resourceSiteId !== (string) $site->id) {
            return false;
        }

        return (string) $site->user_id === (string) $actor->id;
    }
}
