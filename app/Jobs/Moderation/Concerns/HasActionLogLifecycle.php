<?php

namespace App\Jobs\Moderation\Concerns;

use App\Models\Moderation\ActionLogEntry;
use Illuminate\Support\Facades\Log;
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
     * Report the exception, mark the action log entry as 'failed', and emit a
     * structured error log. All jobs using this trait are expected to have a
     * $caseId property. Override failedLogContext() to merge extra keys.
     */
    public function failed(Throwable $e): void
    {
        report($e);
        ActionLogEntry::query()->where('id', $this->actionLogId)->update([
            'status' => 'failed',
            'failed_at' => now(),
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
