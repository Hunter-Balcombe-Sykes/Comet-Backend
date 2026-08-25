<?php

namespace App\Policies;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Models\Core\User\UserDeletionAuditEntry;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * V2: Authorization for records where the actor can only access their own data.
 *
 * Covers Professional (id-based),
 * and UserDeletionAuditEntry (read-only).
 *
 * Audit-log models (UserDeletionAuditEntry) are immutable — update/delete
 * are blocked by the policy regardless of ownership.
 *
 * Also covers staff-facing abilities on User records. Gate::policy() is 1:1
 * (one policy per model class), so staff abilities live here alongside the
 * self-service ones. Staff actor methods use PartnaStaff as the first argument
 * so Laravel's Gate dispatches them separately from the User-actor variants.
 */
class UserSelfPolicy extends BasePolicy
{
    public function view(User $actor, Model $resource): bool|Response
    {
        if ($this->resolveOwnerId($resource) !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    public function update(User $actor, Model $resource): bool|Response
    {
        // Audit-log models are append-only — deny all mutations regardless of ownership.
        if ($resource instanceof UserDeletionAuditEntry) {
            return $this->denyAsNotFound();
        }

        if ($denied = $this->denyIfPendingDeletion($actor)) {
            return $denied;
        }

        if ($this->resolveOwnerId($resource) !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        // Require fresh MFA for high-risk self-mutations. Gated by flag — flip after TOTP is live.
        if (config('partna.mfa.require_fresh_aal2_for_profile_update')) {
            $aal2Check = $this->requiresFreshAal2();
            if (! $aal2Check->allowed()) {
                return $aal2Check;
            }
        }

        return true;
    }

    public function delete(User $actor, Model $resource): bool|Response
    {
        return $this->update($actor, $resource);
    }

    /**
     * Cancel a pending account deletion during the grace period. Ownership-gated ONLY —
     * unlike update(), it must NOT block pending_deletion actors, because cancel is the
     * one mutation legitimately performed while the account is pending_deletion (it de-
     * escalates that very state). Structural ownership already holds (the professional is
     * resolved from the verified JWT); this gate is defence-in-depth for any future
     * staff-routed path.
     */
    public function cancelDeletion(User $actor, Model $resource): bool|Response
    {
        if ($this->resolveOwnerId($resource) !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Staff-actor abilities (PartnaStaff as first argument)
    // -------------------------------------------------------------------------
    //
    // destroy (soft-delete) and restore live in the staff-only route group (no
    // staff.admin middleware), so a support-role actor reaches these methods and
    // is denied HERE — the policy is the actual enforcement point for those two
    // operations. The remaining write methods (updateStatus, update, forceDestroy,
    // bulkUpdateStatus) are behind staff.admin, so this policy is defence-in-depth
    // for those.
    //
    // Pattern mirrors CasePolicy + StaffCaseController: actor is resolved from
    // $request->attributes->get('partna_staff') and passed via authorizeForUser().

    /**
     * General staff management ability: view/status-update/update/soft-delete/restore.
     * Any active admin staff can perform these reversible operations.
     */
    public function staffManage(PartnaStaff $actor, User $target): bool
    {
        return $actor->isAdmin();
    }

    /**
     * Read-access seam for the staff detail view (#SEC-101).
     *
     * All staff roles may view a professional's detail record — the PII gate
     * itself lives in UserStaffResource ($showPii, admin-only), not here. This
     * ability exists so the read path has an explicit, auditable authorization
     * point (rather than none at all) and a seam to tighten later if a role
     * should lose read access outright.
     */
    public function staffView(PartnaStaff $actor, User $target): bool
    {
        return true;
    }

    /**
     * Hard (force) delete — PERMANENT and irreversible.
     *
     * Explicitly gated to admin role even though the route group already requires
     * staff.admin, creating a defence-in-depth seam. If support staff are ever
     * granted access to the staff route group, they will be denied here until
     * this gate is deliberately relaxed. This prevents a support staffer from
     * permanently destroying a professional's account.
     */
    public function staffForceDelete(PartnaStaff $actor, User $target): bool
    {
        // Admin-only: irreversible action requires the highest privilege tier.
        return $actor->isAdmin();
    }

    /**
     * Release a claim — unbind the claimer, keep the built site.
     *
     * Admin-only for the same defence-in-depth reason as staffForceDelete: this
     * transfers ownership of a site away from whoever currently holds it, so a
     * support-tier staffer who ever reached the route group must still be denied
     * here. It is the NON-destructive recovery lane (the site survives), but the
     * ownership consequence is identical, so it sits at the same privilege tier.
     */
    public function staffReleaseClaim(PartnaStaff $actor, User $target): bool
    {
        return $actor->isAdmin();
    }

    /**
     * Bulk status update — affects many users at once.
     *
     * Treated as admin-only because the blast radius of a bulk suspend/activate
     * is much larger than a single-row status change.
     */
    public function staffBulkManage(PartnaStaff $actor): bool
    {
        return $actor->isAdmin();
    }

    /**
     * Staff management of blocks (sections + link blocks) on a professional's site.
     * Admin-only, and denied (423) when the professional is pending deletion.
     * Lives on UserSelfPolicy (not SitePolicy) because the controllers pass the
     * User professional as the resource, so the Gate resolves the policy from
     * User::class → UserSelfPolicy — a method on SitePolicy would never be reached.
     */
    public function staffManageBlock(PartnaStaff $actor, User $professional): bool|Response
    {
        if ($denied = $this->denyIfPendingDeletion($professional)) {
            return $denied;
        }

        return $actor->isAdmin();
    }

    // -------------------------------------------------------------------------

    /**
     * Professional itself is the root record — ownership is $resource->id.
     * All other covered models carry user_id directly.
     */
    private function resolveOwnerId(Model $resource): string
    {
        if ($resource instanceof User) {
            return (string) $resource->id;
        }

        return (string) ($resource->user_id ?? '');
    }
}
