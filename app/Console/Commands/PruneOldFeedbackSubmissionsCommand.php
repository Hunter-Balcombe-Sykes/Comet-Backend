<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PRIV-8: Enforce a retention window on core.feedback (in-app bug/idea/praise/
 * question/other submissions). Nothing else ages this table out — PurgeSoftDeleted
 * (partna:purge-soft-deletes) only force-deletes rows a staffer has ALREADY
 * soft-deleted; a feedback row left sitting in 'new'/'triaged' forever never gets
 * a deleted_at stamp, so its reply_email + free-text message column would
 * otherwise accumulate indefinitely regardless of triage status.
 *
 * Hard-deletes core.feedback rows older than the retention window (by
 * created_at, any status), batched like PurgeRawAnalyticsEvents::purgeBatched()
 * so the sweep never holds one long-running transaction.
 */
class PruneOldFeedbackSubmissionsCommand extends Command
{
    protected $signature = 'feedback:prune-old-submissions
                            {--days= : Override retention window (default from config partna.feedback.retention_days)}
                            {--dry-run : Report eligible rows without deleting}';

    protected $description = 'Hard-delete core.feedback submissions older than the retention window. '
        .'Runs in batches to avoid long-running transactions.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('partna.feedback.retention_days', 90));
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $this->line(sprintf(
            '%s feedback submissions older than %d days (cutoff: %s).',
            $dryRun ? '[DRY RUN] Would delete' : 'Deleting',
            $days,
            $cutoff->toDateTimeString()
        ));

        if ($dryRun) {
            $count = DB::connection('pgsql')
                ->table('core.feedback')
                ->where('created_at', '<', $cutoff)
                ->count();

            $this->info("Would delete {$count} feedback submission(s).");

            Log::info('feedback.prune_old_submissions_dry_run', [
                'eligible' => $count,
                'cutoff' => $cutoff->toIso8601String(),
            ]);

            return self::SUCCESS;
        }

        $deleted = $this->purgeBatched($cutoff);

        $this->info("Deleted {$deleted} feedback submission(s).");

        // Log counts only — never message/reply_email contents.
        Log::info('feedback.prune_old_submissions', [
            'deleted' => $deleted,
            'cutoff' => $cutoff->toIso8601String(),
        ]);

        return self::SUCCESS;
    }

    /**
     * CFG-1-style batched delete (mirrors PurgeRawAnalyticsEvents::purgeBatched):
     * bounds each DELETE's row count so the purge never holds one long-running
     * transaction as core.feedback grows.
     */
    private function purgeBatched(Carbon $cutoff): int
    {
        $batchSize = (int) config('partna.feedback.prune_batch_size', 1000);
        $deleted = 0;

        do {
            $count = DB::connection('pgsql')
                ->table('core.feedback')
                ->where('created_at', '<', $cutoff)
                ->limit($batchSize)
                ->delete();

            $deleted += $count;
        } while ($count === $batchSize);

        return $deleted;
    }
}
