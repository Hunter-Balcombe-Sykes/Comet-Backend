<?php

namespace App\Policies;

use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\Site\SiteSubdomainAlias;
use App\Models\Core\User\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * V2: Authorization for Site and its nested resources.
 *
 * Covers: Site, Block, SiteMedia, Enquiry, SiteSubdomainAlias, LeadSubmission.
 *
 * Ownership resolution:
 *   - Site, Block, Enquiry, LeadSubmission: carry user_id directly
 *   - SiteMedia, SiteSubdomainAlias: resolve via loaded site relation
 *     (caller must setRelation('site', $site) before authorizeForUser to avoid N+1)
 */
class SitePolicy extends BasePolicy
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

    public function create(User $actor, Model $skeleton): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        return $this->ownerMatches($actor, $skeleton);
    }

    private function ownerMatches(User $actor, Model $resource): bool
    {
        $ownerId = $this->resolveOwnerId($resource);

        return $ownerId !== null && (string) $ownerId === (string) $actor->id;
    }

    private function resolveOwnerId(Model $resource): ?string
    {
        // SiteMedia and SiteSubdomainAlias don't own user_id — ownership
        // resolves through their site relation. Caller must setRelation('site', $site)
        // before authorizeForUser to prevent N+1 / lazy-loading violations.
        //
        // We verify both that the preloaded site owns the actor AND that the resource's
        // site_id actually matches the site — this prevents a non-owner's site being
        // injected via setRelation to spoof access to another owner's resource.
        if ($resource instanceof SiteMedia || $resource instanceof SiteSubdomainAlias) {
            $site = $resource->getRelation('site');
            if (! $site) {
                return null;
            }

            // Confirm the resource's site_id matches the preloaded site's id
            $resourceSiteId = $resource->getAttributes()['site_id'] ?? null;
            if ($resourceSiteId === null || (string) $resourceSiteId !== (string) $site->id) {
                return null;
            }

            return (string) $site->user_id;
        }

        // Direct: Site itself plus denormalized user_id on Block/Enquiry/LeadSubmission.
        // getAttributes() reads the raw attribute array without triggering Eloquent __get
        // magic. array_key_exists is intentional — isset() would return false for null.
        $rawAttrs = $resource->getAttributes();

        return array_key_exists('user_id', $rawAttrs) && $rawAttrs['user_id'] !== null
            ? (string) $rawAttrs['user_id']
            : null;
    }
}
