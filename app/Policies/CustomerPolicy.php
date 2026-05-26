<?php

namespace App\Policies;

use App\Models\Core\User\Customer;
use App\Models\Core\User\User;
use Illuminate\Auth\Access\Response;

/**
 * V2: Authorization for Customer records owned by a Professional.
 *
 * Denials on route-bound resources return 404 to avoid leaking existence to non-owners.
 */
class CustomerPolicy extends BasePolicy
{
    public function view(User $actor, Customer $customer): bool|Response
    {
        if ((string) $customer->user_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function create(User $actor, Customer $skeleton): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        return (string) $skeleton->user_id === (string) $actor->id;
    }

    public function update(User $actor, Customer $customer): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        if ((string) $customer->user_id !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function delete(User $actor, Customer $customer): bool|Response
    {
        return $this->update($actor, $customer);
    }
}
