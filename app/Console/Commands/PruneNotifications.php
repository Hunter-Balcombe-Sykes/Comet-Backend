<?php

namespace App\Console\Commands;

use App\Models\Core\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// V2: Deletes expired non-critical notifications older than N days. Cascades to notification receipts.
// OV-H: critical notifications (critical=true) are NEVER pruned — they persist until the user
// resolves/dismisses them (and are stored with ends_at=null anyway). This guard is belt-and-suspenders
// against any critical row that somehow carries an ends_at.
//
// SCALE-1: batched like PurgeRawAnalyticsEvents::purgeBatched / PruneOldFeedbackSubmissionsCommand
// so the daily-scheduled prune never holds one long-running unbounded DELETE as the table grows.
//
// CFG-2: --days is a grace period measured from ends_at, NOT a retention window, so it does not
// bypass per-category retention: NotificationPublisher stamps ends_at = now +
// partna.notification_retention_days.<category> at publish time, and this command deletes on
// ends_at < cutoff. A 365-day policy_update is therefore removed at day 395.
class PruneNotifications extends Command
{
    // Default batch size for the delete loop (mirrors the sibling commands'
    // config-driven defaults — kept as a literal default here rather than a new
    // config('partna.*') key, since --batch-size already covers the same override
    // need without adding a shared config surface).
    private const DEFAULT_BATCH_SIZE = 1000;

    protected $signature = 'partna:prune-notifications {--days=30} {--batch-size='.self::DEFAULT_BATCH_SIZE.'} {--dry-run}';

    protected $description = 'Delete expired non-critical notifications whose ends_at is older than N days (cascades receipts; keeps critical). Runs in batches to avoid long-running transactions.';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        if ($this->option('dry-run')) {
            $count = (int) Notification::query()
                ->where('critical', false)
                ->whereNotNull('ends_at')
                ->where('ends_at', '<', $cutoff)
                ->count();

            $this->info("Would delete {$count} notifications with ends_at < {$cutoff}");

            return self::SUCCESS;
        }

        $deleted = $this->purgeBatched($cutoff);
        $this->info("Deleted {$deleted} notifications.");

        return self::SUCCESS;
    }

    /**
     * CFG-1-style batched delete (mirrors PurgeRawAnalyticsEvents::purgeBatched):
     * bounds each DELETE's row count so the sweep never holds one long-running
     * transaction. Bypasses Eloquent (no observers registered on Notification;
     * receipts still cascade via the DB-level FK) so ->limit()->delete() compiles
     * to the batched query the grammar supports.
     */
    private function purgeBatched(Carbon $cutoff): int
    {
        $batchSize = (int) $this->option('batch-size');

        // A non-positive batch size would make `while ($count === $batchSize)`
        // spin forever (0 === 0 on every iteration, and a negative limit deletes
        // nothing while the loop condition still holds) — fail safe instead.
        if ($batchSize < 1) {
            $this->error("Invalid prune batch size ({$batchSize}); must be >= 1. Aborting.");

            return 0;
        }

        $deleted = 0;

        do {
            $count = DB::connection('pgsql')
                ->table('notifications.notifications')
                ->where('critical', false)
                ->whereNotNull('ends_at')
                ->where('ends_at', '<', $cutoff)
                ->limit($batchSize)
                ->delete(); // relies ON DELETE CASCADE to remove receipts

            $deleted += $count;
        } while ($count === $batchSize);

        return $deleted;
    }
}
