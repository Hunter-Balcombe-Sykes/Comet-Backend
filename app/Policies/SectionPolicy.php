<?php

namespace App\Policies;

use App\Models\Core\User\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Authorization for site.pages and site.sections (plan §7).
 *
 * Neither table carries user_id — both carry site_id — so ownership resolves
 * through the site relation, the same shape SitePolicy uses for SiteMedia.
 * The caller must `setRelation('site', $site)` with the ACTOR's own site
 * before authorizing, and this policy independently re-checks that the
 * resource's site_id matches that site: without the cross-check, a non-owner's
 * site could be injected via setRelation to spoof access.
 *
 * section_items and section_groups are deliberately NOT covered here. They are
 * only ever reached through their parent section's route, so authorising the
 * section IS authorising them (the MenuItem precedent — see POLICY_EXEMPT in
 * PolicyCoverageTest).
 */
class SectionPolicy extends BasePolicy
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
        $site = $resource->getRelation('site');

        if ($site === null) {
            return false;
        }

        // getAttributes() reads the raw attribute array without triggering
        // Eloquent's magic getter, so a resource whose site_id was never set
        // reads as null rather than lazily loading something.
        $resourceSiteId = $resource->getAttributes()['site_id'] ?? null;

        if ($resourceSiteId === null || (string) $resourceSiteId !== (string) $site->id) {
            return false;
        }

        return (string) $site->user_id === (string) $actor->id;
    }
}
