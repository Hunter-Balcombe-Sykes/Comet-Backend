<?php

namespace App\Console\Commands;

use App\Ingest\ConnectorRegistry;
use App\Ingest\Runtime\SourceScheduler;
use App\Jobs\Ingest\RunSourceJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * #LIFE-5. Re-claim eager-provisioned ingest sources that never ran.
 *
 * `IntegrationConnectionObserver::maybeRunEagerly()` fires ONCE, on creation.
 * Its own comment says the rest: nothing retries it, and `auto_sync = false`
 * keeps the scheduler away no matter what `next_attempt_at` says — `scoreDue()`
 * filters on `auto_sync = true`. So a transient dispatch failure means that
 * user's Instagram media never arrives. Indefinitely. With a `Log::warning`
 * Nightwatch does not alert on.
 *
 * Two ways to get there, and the fix has to cover both:
 *   1. the dispatch throws — the observer calls `release('error')`, which stamps
 *      `last_run_at`, so "never ran" is NOT `last_run_at IS NULL`;
 *   2. the dispatch succeeds and the job is never executed (queue lost it) — the
 *      claim sits until `releaseStranded()`'s 2h backstop, which does NOT stamp
 *      `last_run_at`.
 *
 * Hence the predicate is "no ingest.runs row ever reached a landing outcome",
 * not anything on the source row itself. That is the only signal that means the
 * same thing in both cases.
 *
 * CHOSEN OVER a persisted "needs eager run" flag the scheduler selects, because
 * this is ADDITIVE and REVERSIBLE: it changes no existing write path, and if the
 * flag turns out to be the better shape, deleting this command costs nothing.
 * Recorded in docs/superpowers/plans/2026-08-19-P1-overnight-DECISIONS.md.
 */
class IngestReconcileEagerCommand extends Command
{
    protected $signature = 'ingest:reconcile-eager
                            {--limit=50 : Most sources to re-claim in one pass}
                            {--grace=30 : Minutes a source must have existed before it counts as stranded}
                            {--dry-run : Report what would be re-claimed and change nothing}';

    protected $description = 'Re-dispatch eager-provisioned ingest sources whose one-shot connect run never landed.';

    /**
     * Consecutive failures past which we stop re-dispatching. A source failing
     * this persistently has a real problem, and re-running a METERED connector
     * at it every day spends the vendor budget to learn nothing.
     */
    private const MAX_FAILURES = 3;

    public function handle(SourceScheduler $scheduler): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $graceMinutes = max(0, (int) $this->option('grace'));
        $dryRun = (bool) $this->option('dry-run');

        // Only source keys whose manifest actually asks for an eager run. A
        // source that was never MEANT to run on connect is not stranded, it is
        // waiting for the scheduler like everything else.
        $eagerKeys = [];
        foreach (ConnectorRegistry::all() as $key => $_connector) {
            if (ConnectorRegistry::manifestFor((string) $key)->runsEagerlyOnConnect()) {
                $eagerKeys[] = (string) $key;
            }
        }

        if ($eagerKeys === []) {
            $this->info('Eager reconcile: no connector runs eagerly on connect.');

            return self::SUCCESS;
        }

        $candidates = DB::table('ingest.sources')
            ->whereIn('source_key', $eagerKeys)
            // The scheduler will never pick these up itself; that is the bug.
            ->where('auto_sync', false)
            ->whereNull('in_flight_since')
            ->where('health', '!=', 'dead')
            ->where('consecutive_failures', '<', self::MAX_FAILURES)
            // Honour next_attempt_at, but ONLY for a source that has been
            // DEFERRED. Both halves of that sentence are load-bearing.
            //
            // WHY AT ALL: SourceScheduler::release() returns early for outcome
            // 'deferred' with a retryAfter — it reschedules and returns BEFORE
            // the $qualifies check, so it bumps NEITHER consecutive_failures
            // NOR health. 'deferred' is also not a landed outcome below. So
            // such a source passes every other guard forever and would be
            // re-dispatched on every 04:10 run: on a Metered or Actor-billed
            // connector, precisely the unbounded vendor spend this command
            // exists to prevent. (Dormant today — nothing emits a Deferred
            // message yet — which is why it is worth closing before one does.)
            //
            // WHY ONLY THEN: gating every candidate on next_attempt_at was the
            // first cut, and it was far too blunt. SourceProvisioner writes
            // min_interval_secs = the manifest's defaultIntervalSeconds, and
            // for instagram, spotify and soundcloud that is 604800 — equal to
            // MAX_INTERVAL_FLOOR_SECS, so release()'s
            // min(max, min * 2^failures) is ALREADY MAXED on failure #1. One
            // transient failure would have pushed the recovery this command
            // exists to provide out by a full WEEK, on the very connector its
            // docblock names. A vendor asking us to come back later is a
            // reason to wait; our own queue dropping a job is not.
            ->where(function ($q) {
                $q->where('next_attempt_at', '<=', now())
                    ->orWhereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('ingest.runs')
                            ->whereColumn('ingest.runs.source_id', 'ingest.sources.id')
                            ->where('ingest.runs.outcome', 'deferred');
                    });
            })
            // Grace window: a source created moments ago may have a perfectly
            // healthy eager run in flight that simply has not written its
            // ingest.runs row yet. Re-claiming that would double-charge a
            // metered connector for nothing.
            ->where('created_at', '<', now()->subMinutes($graceMinutes))
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('ingest.runs')
                    ->whereColumn('ingest.runs.source_id', 'ingest.sources.id')
                    // 'degraded' counts as landed: the fetch and the landing
                    // both succeeded and only a projection failed, which is our
                    // bug to fix with `ingest:project`, not a reason to refetch.
                    ->whereIn('outcome', ['ok', 'not_modified', 'degraded']);
            })
            ->orderBy('created_at')
            ->limit($limit)
            ->get(['id', 'user_id', 'source_key', 'created_at']);

        if ($candidates->isEmpty()) {
            $this->info('Eager reconcile: nothing stranded.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn(sprintf('Eager reconcile (dry run): %d source(s) would be re-claimed.', $candidates->count()));
            foreach ($candidates as $source) {
                $this->line(sprintf('  %s  %s  created %s', $source->id, $source->source_key, $source->created_at));
            }

            return self::SUCCESS;
        }

        $dispatched = 0;
        $skipped = 0;

        foreach ($candidates as $source) {
            $runId = (string) Str::uuid();

            // Same claim the observer takes. Losing it means someone else owns
            // the row right now and their run covers this source.
            if (! $scheduler->claimOne((string) $source->id, $runId)) {
                $skipped++;

                continue;
            }

            try {
                RunSourceJob::dispatch((string) $source->id);
                $dispatched++;
            } catch (Throwable $e) {
                // Exactly the observer's reasoning: a dispatch that never lands
                // reaches neither the job's finally nor its failed(), so the row
                // would stay claimed until the 2h stranded backstop. Release it
                // here and leave it re-runnable — the next pass retries it,
                // which is the whole point of this command existing.
                $scheduler->release((string) $source->id, 'error', false);
                report($e);
                $this->error(sprintf('Eager reconcile: dispatch failed for %s (%s)', $source->id, $e->getMessage()));
            }
        }

        $this->warn(sprintf(
            'Eager reconcile: re-dispatched %d source(s), skipped %d already claimed.',
            $dispatched,
            $skipped,
        ));

        return self::SUCCESS;
    }
}
