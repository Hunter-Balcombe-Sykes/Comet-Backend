<?php

namespace App\Services\Moderation;

use App\Jobs\Moderation\NotifyOnCallStaffJob;
use App\Jobs\Moderation\NotifyReportedUserJob;
use App\Jobs\Moderation\NotifyReporterJob;
use App\Jobs\Moderation\PurgeModerationCacheJob;
use App\Jobs\Moderation\QuarantineMediaJob;
use App\Jobs\Moderation\SuspendSiteJob;
use App\Jobs\Moderation\SuspendUserJob;
use App\Models\Moderation\ActionLogEntry;
use App\Models\Moderation\CaseSignal;
use App\Models\Moderation\Decision;
use Illuminate\Support\Facades\Bus;
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
        'dismiss' => [],
        // No auto-notify on warn — spec §4 locked decision (no statement-of-reasons
        // for warn-level outcomes until there's an internal warnings UI).
        'warn' => [],
        'hide_content' => ['notify_reported_user', 'purge_cloudflare_cache'],
        'hide_site' => ['suspend_site', 'sync_subdomain_kv', 'notify_reported_user'],
        'suspend_user' => ['suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_reported_user'],
        'ban_user' => ['suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_reported_user'],
        // CSAM auto-action (spec §7.5): quarantine the matched media, suspend the
        // uploader, hide the site, purge the edge, and page on-call. Deliberately
        // omits notify_reported_user (never tip off a CSAM uploader). The NCMEC
        // CyberTip filing is dispatched directly by CsamMatchHandlerService because
        // FileCyberTipReportJob takes an ncmec_submission id, not an action_log id.
        'csam_auto_suspend' => ['quarantine_media', 'suspend_user', 'suspend_site', 'sync_subdomain_kv', 'notify_oncall_staff'],
        'override_csam_auto_action' => ['notify_oncall_staff'],
        'escalate_law_enforcement' => ['notify_oncall_staff'],
        'escalate_esafety' => ['notify_oncall_staff'],
    ];

    // Enforcement actions run as ONE Bus::chain, in the order ACTIONS_BY_DECISION
    // lists them (already causal: quarantine/suspend writes first, KV + edge sync
    // last). The sync job decides what the edge serves by READING the state the
    // earlier jobs write — as independent dispatches on the concurrent
    // moderation_high queue it could run before those writes committed, re-caching
    // (and keeping routing for) a page that was just taken down. Chaining makes
    // the sync strictly follow the writes; a permanently-failed link halts the
    // chain, leaving the later action rows 'pending' in the staff log rather than
    // announcing a state that never committed. notify_* actions are order-free
    // and stay independent dispatches.
    private const CHAINED_ENFORCEMENT_ACTIONS = [
        'quarantine_media',
        'suspend_user',
        'suspend_site',
        'sync_subdomain_kv',
        'purge_cloudflare_cache',
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
            'id' => Str::uuid()->toString(),
            'decision_id' => $decision->id,
            'action_type' => $type,
            'action_target' => ['case_id' => $decision->case_id],
            'status' => 'pending',
        ]));

        // Dispatch Horizon jobs only after the outer transaction commits so a rollback
        // doesn't produce orphaned jobs against rows that no longer exist.
        DB::afterCommit(function () use ($rows, $decision) {
            [$enforcement, $independent] = $rows->partition(
                fn (ActionLogEntry $row) => in_array($row->action_type, self::CHAINED_ENFORCEMENT_ACTIONS, true),
            );

            $chain = $enforcement
                ->map(fn (ActionLogEntry $row) => $this->makeEnforcementJob($row->id, $row->action_type, $decision->case_id))
                ->filter()
                ->values();

            if ($chain->count() > 1) {
                Bus::chain($chain->all())->dispatch();
            } elseif ($chain->count() === 1) {
                dispatch($chain->first());
            }

            foreach ($independent as $row) {
                $this->dispatchIndependentJob($row->id, $row->action_type, $decision->case_id);
            }
        });
    }

    private function makeEnforcementJob(string $actionLogId, string $type, string $caseId): ?object
    {
        return match ($type) {
            'quarantine_media' => new QuarantineMediaJob($actionLogId, $caseId),
            'suspend_user' => new SuspendUserJob($actionLogId, $caseId),
            'suspend_site' => new SuspendSiteJob($actionLogId, $caseId),
            'sync_subdomain_kv', 'purge_cloudflare_cache' => new PurgeModerationCacheJob($actionLogId, $caseId),
            default => null,
        };
    }

    private function dispatchIndependentJob(string $actionLogId, string $type, string $caseId): void
    {
        match ($type) {
            'notify_reported_user' => NotifyReportedUserJob::dispatch($actionLogId, $caseId),
            'notify_reporter' => NotifyReporterJob::dispatch($actionLogId, $caseId),
            'notify_oncall_staff' => NotifyOnCallStaffJob::dispatch($actionLogId, $caseId),
            default => null,
        };
    }
}
