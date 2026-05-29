<?php

namespace App\Services\Moderation;

use App\Jobs\Moderation\NotifyOnCallStaffJob;
use App\Jobs\Moderation\NotifyReportedUserJob;
use App\Jobs\Moderation\NotifyReporterJob;
use App\Jobs\Moderation\PurgeModerationCacheJob;
use App\Jobs\Moderation\SuspendSiteJob;
use App\Jobs\Moderation\SuspendUserJob;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\Decision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Translates a decision_type into the set of action_log rows + Horizon job dispatches.
 *
 * Action rows are written inside the transaction that created the decision (callers wrap).
 * Jobs are dispatched afterCommit so they don't fire if the surrounding transaction rolls back.
 */
class ModerationActionDispatcher
{
    private const ACTIONS_BY_DECISION = [
        'dismiss'                   => [],
        'warn'                      => ['notify_reported_user'],
        'hide_content'              => ['notify_reported_user', 'purge_cloudflare_cache'],
        'hide_site'                 => ['suspend_site', 'sync_subdomain_kv', 'notify_reported_user'],
        'suspend_user'              => ['suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_reported_user'],
        'ban_user'                  => ['suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_reported_user'],
        'override_csam_auto_action' => ['notify_oncall_staff'],
        'escalate_law_enforcement'  => ['notify_oncall_staff'],
        'escalate_esafety'          => ['notify_oncall_staff'],
    ];

    public function dispatchFor(Decision $decision): void
    {
        $actionTypes = self::ACTIONS_BY_DECISION[$decision->decision_type] ?? [];

        // Append notify_reporter only when at least one signal on this case has a reporter_email.
        $hasReporterEmail = CaseSignal::query()
            ->where('case_id', $decision->case_id)
            ->whereNotNull('reporter_email')
            ->exists();

        if ($hasReporterEmail) {
            $actionTypes[] = 'notify_reporter';
        }

        // Write action_log rows immediately (inside the caller's transaction).
        // forceCreate() is required because these models guard 'id' via $guarded.
        $rows = collect($actionTypes)->map(fn (string $type) => ActionLogEntry::forceCreate([
            'id'            => Str::uuid()->toString(),
            'decision_id'   => $decision->id,
            'action_type'   => $type,
            'action_target' => ['case_id' => $decision->case_id],
            'status'        => 'pending',
        ]));

        // Dispatch Horizon jobs only after the outer transaction commits so a rollback
        // doesn't produce orphaned jobs against rows that no longer exist.
        DB::afterCommit(function () use ($rows, $decision) {
            foreach ($rows as $row) {
                $this->dispatchJob($row->id, $row->action_type, $decision->case_id);
            }
        });
    }

    private function dispatchJob(string $actionLogId, string $type, string $caseId): void
    {
        match ($type) {
            'suspend_user'           => SuspendUserJob::dispatch($actionLogId, $caseId),
            'suspend_site'           => SuspendSiteJob::dispatch($actionLogId, $caseId),
            'sync_subdomain_kv'      => PurgeModerationCacheJob::dispatch($actionLogId, $caseId),
            'purge_cloudflare_cache' => PurgeModerationCacheJob::dispatch($actionLogId, $caseId),
            'notify_reported_user'   => NotifyReportedUserJob::dispatch($actionLogId, $caseId),
            'notify_reporter'        => NotifyReporterJob::dispatch($actionLogId, $caseId),
            'notify_oncall_staff'    => NotifyOnCallStaffJob::dispatch($actionLogId, $caseId),
            default                  => null,
        };
    }
}
