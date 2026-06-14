<?php

namespace App\Policies;

use App\Models\Core\User\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * V2: Authorization for GDPR-related records (DataExportAudit).
 *
 * Owner-only access. denyIfPendingDeletion is intentionally NOT applied here —
 * these endpoints drive the deletion process itself, so a professional in
 * pending_deletion must still be able to read their export/deletion status.
 */
class GdprPolicy extends BasePolicy
{
    public function view(User $actor, Model $resource): bool|Response
    {
        if ((string) ($resource->user_id ?? '') !== (string) $actor->id) {
            return $this->denyAsNotFound();
        }

        return true;
    }

    // No update/delete abilities — GDPR records are append-only, mutations
    // are handled by system jobs, not actor actions.
}
