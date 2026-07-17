<?php

namespace App\Services\Moderation;

use App\Models\Core\Staff\PartnaStaff;
use App\Notifications\Moderation\EdgePurgeFailedStaffNotification;
use Illuminate\Support\Facades\Notification;

/**
 * Pages on-call staff when a moderation-context edge purge
 * (CloudflareCachePurgeJob with a moderationCaseId) exhausts its retries.
 *
 * hide_content is the only decision that leaves the owner ACTIVE, so
 * SyncSubdomainToKvJob never retires the KV routing entry for it — the edge
 * purge is the sole backstop that evicts hidden content from caches.default.
 * A silent permanent failure here means the content can keep serving from
 * the edge indefinitely, so this escalates rather than relying on the log line.
 */
class EdgePurgeEscalator
{
    public function escalate(string $handle, string $moderationCaseId): void
    {
        // Mirrors NotifyOnCallStaffJob's on-call selection: PartnaStaff has no
        // is_on_call column — all role=admin rows are treated as on-call.
        $oncall = PartnaStaff::query()->where('role', PartnaStaff::ROLE_ADMIN)->get();
        if ($oncall->isEmpty()) {
            return;
        }

        Notification::send($oncall, new EdgePurgeFailedStaffNotification($handle, $moderationCaseId));
    }
}
