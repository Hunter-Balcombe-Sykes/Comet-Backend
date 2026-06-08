<?php

namespace App\Services\User\Concerns;

use App\Models\Core\User\User;
use App\Models\Core\User\UserDeletionAuditEntry;
use Illuminate\Support\Facades\DB;

/**
 * Shared logic for recovering the pre-pseudonymisation email from the deletion
 * audit trail. This is the single source of truth used by both the GDPR data
 * export (DataExportPayloadBuilder) and the post-deletion purge sweep
 * (AccountDeletionService), ensuring the two cannot drift and cause incomplete
 * Article 15 exports (SEM-3).
 *
 * AccountDeletionService::pseudonymiseAccountPii() rewrites primary_email to
 * "deleted+{id}@partna.au" before the hard-delete step. Any code that needs
 * the user's real email after pseudonymisation — for waitlist/subscription
 * lookups, notification delivery, or purge email targeting — must use this
 * method rather than accessing primary_email directly.
 *
 * All three relevant event types are queried so that admin-initiated deletions
 * (which write EVENT_ADMIN_INITIATED then EVENT_CONFIRMED without a preceding
 * EVENT_REQUESTED) still surface a snapshot. DESC order ensures the most
 * recently captured snapshot wins when multiple rows exist.
 */
trait ResolvesDeletedEmail
{
    /**
     * Resolve the user's original (pre-pseudonymisation) email.
     *
     * Returns the most recent non-empty professional_email_snapshot from
     * audit.user_deletion_audit for EVENT_REQUESTED, EVENT_CONFIRMED, or
     * EVENT_ADMIN_INITIATED rows. Falls back to primary_email when the
     * account has not been pseudonymised or no snapshot is found.
     */
    protected function resolveDeletedAccountEmail(User $professional): ?string
    {
        $email = $professional->primary_email;

        if ($email !== null && str_starts_with($email, 'deleted+')) {
            $snapshot = DB::connection('pgsql')
                ->table('audit.user_deletion_audit')
                ->where('user_id', $professional->id)
                ->whereIn('event', [
                    UserDeletionAuditEntry::EVENT_REQUESTED,
                    UserDeletionAuditEntry::EVENT_CONFIRMED,
                    UserDeletionAuditEntry::EVENT_ADMIN_INITIATED,
                ])
                ->orderByDesc('created_at')
                ->value('professional_email_snapshot');

            if (is_string($snapshot) && $snapshot !== '') {
                return $snapshot;
            }
        }

        return $email;
    }
}
