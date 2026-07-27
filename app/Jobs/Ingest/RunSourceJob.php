<?php

namespace App\Jobs\Ingest;

use App\Ingest\ConnectorRegistry;
use App\Ingest\Runtime\RunExecutor;
use App\Ingest\Runtime\SourceScheduler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs ONE claimed ingest.sources row: resolves its connector from the
 * registry, executes it, and reports the outcome back to SourceScheduler.
 *
 * Deliberately NOT ShouldBeUnique. SourceScheduler::claimDue()'s conditional
 * UPDATE (in_flight_since IS NULL) is already the one mutual-exclusion
 * mechanism for a source (see that class's docblock: ONE mechanism, not a
 * second queue-level lock layered on top).
 *
 * $tries = 1: a fetch is not safely re-runnable by the queue. Retrying a
 * failed attempt is SourceScheduler's job via next_attempt_at/backoff, not
 * Horizon's — blindly re-running a fetch could double-charge a billed effect
 * that the EffectLedger didn't get a chance to record as settled.
 */
class RunSourceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public readonly string $sourceId)
    {
        $this->onQueue('ingest');
    }

    public function handle(SourceScheduler $scheduler, RunExecutor $executor): void
    {
        $released = false;

        try {
            $row = DB::table('ingest.sources')->where('id', $this->sourceId)->first();

            if ($row !== null) {
                $source = (array) $row;
                $sourceKey = (string) $source['source_key'];

                $connector = ConnectorRegistry::for($sourceKey);
                $manifest = ConnectorRegistry::manifestFor($sourceKey);

                $result = $executor->execute($source, $connector, $manifest, 'schedule');

                // execute()'s return contract carries the outcome but not a
                // changed flag — the run row it just wrote has the count that
                // answers it, so the EWMA input is read back rather than
                // widening RunExecutor's return shape for one caller.
                $recordsChanged = (int) DB::table('ingest.runs')->where('id', $result['run_id'])->value('records_changed');

                $scheduler->release(
                    sourceId: $this->sourceId,
                    outcome: $result['outcome'],
                    changed: $recordsChanged > 0,
                    retryAfterSeconds: $result['retry_after'],
                );
            }
            // A null row means the source vanished between claim and run (a
            // cascaded user/connection delete) — nothing to execute and
            // nothing to release.

            $released = true;
        } finally {
            if (! $released) {
                // Anything above throwing before the normal release — an
                // unregistered source_key, a DB error, a bug in the executor
                // — must still drop the claim. A held claim is worse than one
                // run recorded as a plain failure: the scheduler's own
                // backoff takes over immediately instead of waiting for
                // releaseStranded()'s 2h backstop.
                $scheduler->release($this->sourceId, 'error', false);
            }
        }
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('ingest.run_source.job_failed', [
            'source_id' => $this->sourceId,
            'error' => $e->getMessage(),
        ]);

        // Defensive backstop, not the normal path: handle()'s own finally
        // above already releases the claim before this ever runs (failed()
        // fires from a freshly-unserialized copy of this job, after the
        // worker catches the exception handle() threw — the finally block
        // already ran on the ORIGINAL instance by then). Only a release that
        // never reached the finally at all — a hard kill, not a thrown
        // exception — leaves the claim held, so this checks the row rather
        // than releasing unconditionally, which would double-count the
        // failure and corrupt consecutive_failures/backoff.
        $stillClaimed = DB::table('ingest.sources')
            ->where('id', $this->sourceId)
            ->whereNotNull('in_flight_since')
            ->exists();

        if ($stillClaimed) {
            app(SourceScheduler::class)->release($this->sourceId, 'error', false);
        }
    }
}
