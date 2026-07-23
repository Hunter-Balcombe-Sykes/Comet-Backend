<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * DINT-1 / PRIV-7 Gap 2: Enforce the unsubscribed-subscription PII retention window.
 *
 * Hard-deletes unsubscribed rows older than the retention window. NULLing the PII
 * columns is NOT an option: both email and email_lc are NOT NULL in Postgres
 * (baseline 20260526000000, lines 1088/1099), and email_lc is itself PII — there
 * is no skeleton worth preserving once consent is withdrawn and the window has
 * elapsed. Hard delete matches the existing
 * AccountDeletionService::purgeGlobalEmailSubscriptions() behaviour.
 */
class PruneUnsubscribedSubscriptionsCommand extends Command
{
    protected $signature = 'notifications:prune-unsubscribed-subscriptions
                            {--days= : Override retention window (default from config notifications.unsubscribed_retention_days)}
                            {--dry-run : Report eligible rows without deleting anything}';

    protected $description = 'Hard-delete unsubscribed email_subscriptions rows older than the retention window. '
        .'Removes withdrawn-consent PII (email, email_lc, full_name, consent_*) the platform no longer has a basis to keep.';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('partna.notifications.unsubscribed_retention_days', 365));
        $cutoff = now()->subDays($days);
        $dryRun = (bool) $this->option('dry-run');

        $this->line(sprintf(
            '%s unsubscribed subscriptions older than %d days (cutoff: %s).',
            $dryRun ? '[DRY RUN] Would delete' : 'Deleting',
            $days,
            $cutoff->toDateTimeString()
        ));

        $query = DB::connection('pgsql')
            ->table('notifications.email_subscriptions')
            ->where('status', 'unsubscribed')
            ->where('unsubscribed_at', '<', $cutoff->toDateTimeString());

        if ($dryRun) {
            $count = $query->count();
            $this->info("Would delete {$count} unsubscribed subscription(s).");

            return self::SUCCESS;
        }

        // No per-row external side-effect (unlike PruneCompletedExports' R2 file), and the
        // DINT-2 FK (ON DELETE CASCADE, migration 20260624010000) auto-cleans child
        // broadcast_email_receipts rows. The model has no deleting/deleted observer (only
        // saved), so a bulk delete fires no events. Batched (R2-SCHED-2) so a large cohort
        // (e.g. a growth-campaign unsubscribe wave) never holds one long-running transaction.
        $deleted = $this->purgeBatched($cutoff);

        $this->info("Deleted {$deleted} unsubscribed subscription(s).");

        Log::info('notifications.prune_unsubscribed', [
            'deleted' => $deleted,
            'cutoff' => $cutoff->toIso8601String(),
        ]);

        return self::SUCCESS;
    }

    /**
     * CFG-1-style batched delete (mirrors PruneOldFeedbackSubmissionsCommand::purgeBatched):
     * bounds each DELETE's row count so the purge never holds one long-running transaction
     * as notifications.email_subscriptions grows.
     */
    private function purgeBatched(Carbon $cutoff): int
    {
        $batchSize = (int) config('partna.notifications.prune_batch_size', 1000);
        $deleted = 0;

        do {
            $count = DB::connection('pgsql')
                ->table('notifications.email_subscriptions')
                ->where('status', 'unsubscribed')
                ->where('unsubscribed_at', '<', $cutoff->toDateTimeString())
                ->limit($batchSize)
                ->delete();

            $deleted += $count;
        } while ($count === $batchSize);

        return $deleted;
    }
}
