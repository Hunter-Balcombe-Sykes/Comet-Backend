<?php

namespace App\Jobs\Moderation\Concerns;

use App\Exceptions\Moderation\ModerationTargetMissingException;
use App\Models\Moderation\ActionLogEntry;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shared ActionLogEntry lifecycle for moderation enforcement and notification jobs (SLOP-11).
 *
 * Provides the invariant retry configuration and the helper methods that write
 * lifecycle timestamps to the action log. markDispatched/markCompleted are
 * connection-agnostic — the caller owns any surrounding DB transaction.
 *
 * failed() is included here: it reports the exception, marks the log entry as
 * 'failed', and emits a structured error log. Override failedLogContext() to merge
 * extra keys into the log payload without duplicating the rest of the body.
 *
 * markFailed() is the NON-THROWING sibling for enforcement jobs that discover
 * their target row does not exist. ⚠️ Do NOT "simplify" a markFailed() call site
 * into a throw: QuarantineMediaJob, SuspendUserJob, SuspendSiteJob and
 * PurgeModerationCacheJob are dispatched as links of ONE Bus::chain by
 * ModerationActionDispatcher::dispatchFor(), and a permanently-failed link halts
 * the rest of the chain. For a csam_auto_suspend decision quarantine_media is the
 * FIRST link, so throwing there when the media row is missing would leave the
 * uploader unsuspended, the site visible and KV un-retired — strictly worse than
 * the silent success this replaced (#W2-OBS-2). Jobs with nothing downstream
 * (NotifyOnCallStaffJob) throw instead and let failed() do the same bookkeeping.
 *
 * NOT included here: $timeout (30 vs 60 per job), $queue (varies), constructor.
 *
 * @property string $actionLogId
 * @property string $caseId
 */
trait HasActionLogLifecycle
{
    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    /**
     * Mark the entry as dispatched and increment its attempt counter.
     * Call this with the freshly-loaded entry, before performing the enforcement action,
     * so the log reflects the attempt even if the action itself throws.
     */
    protected function markDispatched(ActionLogEntry $entry): void
    {
        $entry->update([
            'status' => 'dispatched',
            'dispatched_at' => now(),
            'attempts' => $entry->attempts + 1,
        ]);
    }

    /**
     * Mark the entry as successfully completed.
     */
    protected function markCompleted(ActionLogEntry $entry): void
    {
        $entry->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Mark the entry as 'failed' in place, report to Nightwatch, and log — WITHOUT
     * throwing, so the caller can `return` and let the rest of the Bus::chain run.
     *
     * ⚠️ The no-throw is load-bearing, not laziness. These jobs are chained
     * (see the class docblock); a throw here halts every enforcement action queued
     * after this one. The audit trail is made honest by the status write, and
     * on-call is paged by the report() — neither needs the chain to die.
     */
    protected function markFailed(ActionLogEntry $entry, string $reason): void
    {
        $entry->update([
            'status' => 'failed',
            'failure_reason' => Str::limit($reason, 1000),
        ]);

        report(new ModerationTargetMissingException($reason));

        Log::error('Moderation enforcement affected no rows', array_merge([
            'job' => static::class,
            'action_log_id' => $this->actionLogId,
            'case_id' => $this->caseId,
            'reason' => $reason,
        ], $this->failedLogContext()));
    }

    /**
     * Report the exception, mark the action log entry as 'failed', and emit a
     * structured error log. All jobs using this trait are expected to have a
     * $caseId property. Override failedLogContext() to merge extra keys.
     */
    public function failed(Throwable $e): void
    {
        report($e);
        ActionLogEntry::query()->where('id', $this->actionLogId)->update([
            'status' => 'failed',
            'failure_reason' => Str::limit($e->getMessage(), 1000),
        ]);
        Log::error('Moderation job permanently failed', array_merge([
            'job' => static::class,
            'action_log_id' => $this->actionLogId,
            'case_id' => $this->caseId,
            'error' => $e->getMessage(),
        ], $this->failedLogContext()));
    }

    /**
     * Extra log context keys merged into the failed() error log.
     * Override in concrete jobs when additional fields aid debugging.
     *
     * @return array<string,mixed>
     */
    protected function failedLogContext(): array
    {
        return [];
    }
}
