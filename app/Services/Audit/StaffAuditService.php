<?php

namespace App\Services\Audit;

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\Staff\StaffAuditEntry;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\Log;
use Throwable;

// OPS-2: writes one row per staff write to audit.staff_audit_log.
// Invoked by RecordStaffAuditEntry middleware after the response is sent.
// May also be called directly from controllers that want to record extra
// body-detail forensics (e.g., previous_media_id / new_media_id on uploads).
//
// Failure mode: if the insert throws, we log a warning and return null —
// audit-log unavailability must never block a staff action.
class StaffAuditService
{
    public function record(
        ?PartnaStaff $staff,
        ?PartnaStaff $impersonator,
        ?User $professional,
        string $route,
        string $httpMethod,
        int $statusCode,
        array $payloadSummary = [],
        ?string $ip = null,
        ?string $userAgent = null,
    ): ?StaffAuditEntry {
        $attributes = [
            'staff_id' => $staff?->id,
            // PRIV-7: snapshots are intentional, not redundant with the FK — all
            // three FKs are ON DELETE SET NULL and core.users rows ARE force-deleted
            // 30 days post-deletion, so read-time resolution would lose who was
            // affected. Same pattern as audit.user_deletion_audit /
            // audit.data_export_audit (see DataExportPayloadBuilder). Table is
            // append-only (no UPDATE/DELETE grant), so nothing can be backfilled.
            'staff_email_snapshot' => $staff?->primary_email,
            'impersonator_staff_id' => $impersonator?->id,
            'impersonator_email_snapshot' => $impersonator?->primary_email,
            'user_id' => $professional?->id,
            'professional_handle_snapshot' => $professional?->handle,
            'route' => $route,
            'http_method' => $httpMethod,
            'status_code' => $statusCode,
            'payload_summary' => $payloadSummary,
            // DINT-1: hash centrally here (not at the call site) so no caller
            // can regress by writing a raw IP — matches the HMAC-SHA256
            // convention used by site.enquiries.ip_hash / HashesClientData.
            'ip_hash' => $this->hashIp($ip),
            'user_agent' => $userAgent,
        ];

        try {
            return StaffAuditEntry::query()->create($attributes);
        } catch (Throwable $e) {
            // The target user row can be GONE by the time this insert runs.
            // RecordStaffAuditEntry writes from terminate() — after the response —
            // and StaffUserController::forceDestroy hard-deletes core.users inside
            // the request, so staff_audit_log_user_fk has no parent and the ENTIRE
            // row was being discarded. That silently denied an audit trail to the
            // single most destructive staff endpoint there is.
            //
            // The FK is ON DELETE SET NULL, so a null user_id is the schema's own
            // resting state for a departed user, and the identity is not lost: the
            // handle snapshot is on the row and the raw UUID is in payload_summary
            // (RecordStaffAuditEntry::summariseBindings). Retry once, unlinked.
            if ($attributes['user_id'] !== null && $this->isForeignKeyViolation($e)) {
                return $this->recordUnlinked($attributes, $route, $httpMethod);
            }

            return $this->logWriteFailure($e, $route, $httpMethod);
        }
    }

    /**
     * Second attempt with the user FK dropped. Keeps the audit row rather than
     * losing it; the departed user stays identifiable via the handle snapshot
     * and payload_summary.
     */
    private function recordUnlinked(array $attributes, string $route, string $httpMethod): ?StaffAuditEntry
    {
        $departedUserId = $attributes['user_id'];
        $attributes['user_id'] = null;

        try {
            $entry = StaffAuditEntry::query()->create($attributes);
        } catch (Throwable $e) {
            return $this->logWriteFailure($e, $route, $httpMethod);
        }

        // Breadcrumb, not an error: the row was kept, only the FK link dropped.
        Log::info('staff.audit.user_link_dropped', [
            'route' => $route,
            'http_method' => $httpMethod,
            'departed_user_id' => $departedUserId,
            'audit_entry_id' => $entry->id,
        ]);

        return $entry;
    }

    /**
     * Postgres raises 23503 for a foreign-key violation; SQLite (the test lane)
     * reports the generic integrity class, so match the driver message too.
     * Both wordings contain "foreign key constraint".
     */
    private function isForeignKeyViolation(Throwable $e): bool
    {
        if ((string) $e->getCode() === '23503') {
            return true;
        }

        return str_contains(strtolower($e->getMessage()), 'foreign key constraint');
    }

    private function logWriteFailure(Throwable $e, string $route, string $httpMethod): null
    {
        report($e);
        // B3/P2-12: request_id correlates the warning to the NGINX/Cloudflare
        // access log entry — same pattern as FeatureFlagService / NotificationPublisher.
        Log::warning('staff.audit.write_failed', [
            'exception' => $e->getMessage(),
            'route' => $route,
            'http_method' => $httpMethod,
            'request_id' => request()?->header('X-Request-Id'),
        ]);

        return null;
    }

    // One-way HMAC-SHA256, keyed on APP_KEY — same scheme as HashesClientData /
    // ContentReportService::submit(). Never store the raw IP (DINT-1).
    private function hashIp(?string $ip): ?string
    {
        return $ip !== null ? hash_hmac('sha256', $ip, config('app.key')) : null;
    }
}
