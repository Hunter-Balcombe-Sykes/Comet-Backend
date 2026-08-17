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
     * Category assignment — open to every service the actor owns.
     *
     * Slice 3a restricted this to `source = 'fresha'` because owner-authored
     * services had no membership destination in content.* at all, and
     * accepting a write nothing serves is worse than refusing it. **Slice 3b
     * landed that destination** (`content.collections` /
     * `content.collection_items`, written through
     * `App\Services\Content\ServiceCollections::assign()`), so the gate is
     * gone — that was its documented exit condition, not a regression.
     *
     * The coupling 3a recorded here moved WITH it, in the same commit:
     * `ManualServiceItems::publicList()` (the read behind
     * SitepageDataResolverService::buildServicesData()) no longer hardcodes
     * `'category' => 'Services'` — it renders the item's real collection
     * label and falls back to that constant only when the item has no live
     * membership. Neither half may move alone: re-adding a restriction here
     * without changing that read ships a dashboard that assigns categories
     * the page then labels "Services", and dropping the read's fallback
     * without a gate here relabels every uncategorised service.
     *
     * Ownership/pending-deletion still come from update() (404, never 403 —
     * the enumeration rule the rest of this policy follows). WHICH id resolves
     * is the controller's problem, not an authorization one: since the
     * services cutover both halves live in `content.items`, and a legacy
     * `site.services` uuid 404s by being unaddressable rather than denied
     * (spec ruling 1). This policy authorizes on user_id against the in-memory
     * model either way.
     */
    public function updateCategory(User $actor, Model $resource): bool|Response
    {
        return $this->update($actor, $resource);
    }
}
