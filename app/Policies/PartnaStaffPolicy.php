<?php

namespace App\Policies;

use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Auth\Access\Response;

/**
 * V2: Authorization for `core.partna_staff` records.
 *
 * Doctrine:
 *   - `view`   — admin may view any staff record; support may only view their own.
 *   - `update` — admin only, AND must not be self. Self-promotion / self-demotion
 *                is forbidden at the policy layer; role changes flow only via
 *                PartnaStaff::promoteToAdmin() and demoteToSupport() from an
 *                authorized endpoint.
 *   - `updateOwnProfile` — self ONLY, any role (Wave 8, 2026-09-02): the
 *                settings page's name edit. Deliberately a separate ability
 *                from `update` so its self-edit rule stays intact — the
 *                request class caps what this path can touch (name), never
 *                role or email.
 *   - `delete` — admin only, AND must not be self. Prevents an admin from
 *                accidentally locking the org out of admin access.
 *
 * Actor type is PartnaStaff (not Professional). This policy never calls
 * denyIfPendingDeletion — staff records have no pending_deletion state.
 *
 * 404 vs 403: this policy returns 404 (`denyAsNotFound`) for non-existent /
 * non-self lookups by support staff, to avoid leaking which staff IDs exist.
 * Admin actions that fail role gates return false → Laravel renders 403,
 * which is the correct signal for "you're authenticated as staff but lack
 * the privilege for this action."
 */
class PartnaStaffPolicy extends BasePolicy
{
    public function view(PartnaStaff $actor, PartnaStaff $target): bool|Response
    {
        if ($actor->isAdmin()) {
            return true;
        }

        // Support staff may view their own record only.
        if ((string) $actor->id !== (string) $target->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function update(PartnaStaff $actor, PartnaStaff $target): bool|Response
    {
        if (! $actor->isAdmin()) {
            return false;
        }

        // No self-edit — an admin must not mutate their own staff record
        // through the gated update path. Forces role transitions through an
        // explicit, auditable second-actor flow.
        if ((string) $actor->id === (string) $target->id) {
            return false;
        }

        return true;
    }

    public function updateOwnProfile(PartnaStaff $actor, PartnaStaff $target): bool|Response
    {
        return (string) $actor->id === (string) $target->id;
    }

    public function delete(PartnaStaff $actor, PartnaStaff $target): bool|Response
    {
        if (! $actor->isAdmin()) {
            return false;
        }

        if ((string) $actor->id === (string) $target->id) {
            return false;
        }

        return true;
    }
}
