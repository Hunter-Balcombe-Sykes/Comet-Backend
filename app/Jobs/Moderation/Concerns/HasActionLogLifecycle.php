<?php

namespace App\Jobs\Moderation\Concerns;

use App\Models\Moderation\ActionLogEntry;

/**
 * Shared ActionLogEntry lifecycle for moderation enforcement and notification jobs (SLOP-11).
 *
 * Provides the invariant retry configuration and the two helper methods that write
 * lifecycle timestamps to the action log. markDispatched/markCompleted are
 * connection-agnostic — the caller owns any surrounding DB transaction.
 *
 * NOT included here: $timeout (30 vs 60 per job), $queue (varies), constructor,
 * failed() (log messages differ between enforcement and notification job families).
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
}
