<?php

namespace App\Policies;

use App\Models\Core\Site\Enquiry;
use App\Models\Core\User\User;
use Illuminate\Auth\Access\Response;

/**
 * Authorization for Enquiry records owned by a Professional.
 *
 * Enquiries carry user_id directly (Shape A — simple direct ownership).
 * Denials on route-bound resources return 404 to avoid leaking existence
 * to non-owners. viewAny always passes; the controller scopes to the
 * authenticated user's records via user_id.
 */
class EnquiryPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        // Any authenticated professional may list their own enquiries.
        // Tenant scoping is enforced in the controller query, not here.
        return true;
    }

    public function view(User $user, Enquiry $enquiry): bool|Response
    {
        if ((string) $user->id !== (string) $enquiry->user_id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function update(User $user, Enquiry $enquiry): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($user)) {
            return $denied;
        }

        if ((string) $user->id !== (string) $enquiry->user_id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function delete(User $user, Enquiry $enquiry): bool|Response
    {
        return $this->update($user, $enquiry);
    }
}
