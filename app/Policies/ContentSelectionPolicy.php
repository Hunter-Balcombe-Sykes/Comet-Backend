<?php

namespace App\Policies;

use App\Models\Core\User\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

// Authorization for ContentSelection (the sitepage background-content picks).
// ContentSelection carries no user_id — ownership resolves through its parent
// Site's user_id, so every content endpoint authorizes a skeleton whose `site`
// relation is the auth-resolved current site (setRelation('site', $site)).
//
// The controller passes a bare ContentSelection skeleton with the current site
// attached; we read $model->site->user_id. The site is always the caller's own
// resolved site, so this is a pending-deletion + defensive owner check rather
// than a cross-tenant lookup — but we keep the owner assertion so the pattern
// is identical to the other site-scoped policies and can never silently pass.
class ContentSelectionPolicy extends BasePolicy
{
    public function view(User $actor, Model $resource): bool|Response
    {
        return $this->ownerMatches($actor, $resource)
            ? true
            : $this->denyAsNotFound();
    }

    // Single write ability for the whole surface — replace/toggle/upload/delete
    // all authorize 'manage'. Gates pending_deletion (423) before any mutation.
    public function manage(User $actor, Model $resource): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        return $this->ownerMatches($actor, $resource)
            ? true
            : $this->denyAsNotFound();
    }

    // Ownership flows through the loaded site relation. The caller must
    // setRelation('site', $site) with the auth-resolved current site before
    // authorizing — mirrors SitePolicy's SiteMedia resolution.
    private function ownerMatches(User $actor, Model $resource): bool
    {
        // relationLoaded() first — see SectionPolicy::ownerMatches(). getRelation()
        // THROWS on an unloaded relation rather than returning null, so this guard
        // never ran and a missing site relation surfaced as a 500.
        $site = $resource->relationLoaded('site') ? $resource->getRelation('site') : null;
        if (! $site) {
            return false;
        }

        return $site->user_id !== null && (string) $site->user_id === (string) $actor->id;
    }
}
