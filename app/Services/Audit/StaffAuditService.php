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
        try {
            return StaffAuditEntry::query()->create([
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
            ]);
        } catch (Throwable $e) {
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
    }

    // One-way HMAC-SHA256, keyed on APP_KEY — same scheme as HashesClientData /
    // ContentReportService::submit(). Never store the raw IP (DINT-1).
    private function hashIp(?string $ip): ?string
    {
        return $ip !== null ? hash_hmac('sha256', $ip, config('app.key')) : null;
    }
}
