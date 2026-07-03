<?php

namespace App\Policies;

use App\Models\Core\User\User;
use App\Services\Platforms\Registry\PlatformDescriptor;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

// Authorization for IntegrationConnection. Ownership is the denormalised user_id
// on the row (same shape as Block/Enquiry under SitePolicy). Non-owners get a
// 404 (not 403) to avoid leaking existence — the 403-vs-404 standard.
class IntegrationConnectionPolicy extends BasePolicy
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

    // Capability gate for the generic connect flow — checks account eligibility
    // via the descriptor before the write-level create ability fires in writeConnection().
    // Extra arg pattern: authorizeForUser($user, 'connect', [$skeleton, $descriptor]).
    public function connect(User $actor, Model $skeleton, PlatformDescriptor $descriptor): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        if (! $descriptor->availableFor($actor)) {
            return Response::deny('This platform is not available for your account.', 403);
        }

        return $this->ownerMatches($actor, $skeleton);
    }

    private function ownerMatches(User $actor, Model $resource): bool
    {
        // getAttributes() reads the raw attribute array without Eloquent __get
        // magic; array_key_exists (not isset) so a null user_id is treated as
        // "no owner", not "missing".
        $rawAttrs = $resource->getAttributes();

        return array_key_exists('user_id', $rawAttrs)
            && $rawAttrs['user_id'] !== null
            && (string) $rawAttrs['user_id'] === (string) $actor->id;
    }
}
