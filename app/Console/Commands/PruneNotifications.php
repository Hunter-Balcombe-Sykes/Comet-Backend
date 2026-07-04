<?php

namespace App\Console\Commands;

use App\Models\Core\Notifications\Notification;
use Illuminate\Console\Command;

// V2: Deletes expired notifications older than N days. Cascades to notification receipts.
class PruneNotifications extends Command
{
    protected $signature = 'partna:prune-notifications {--days=30} {--dry-run}';

    protected $description = 'Delete notifications whose ends_at is older than N days (cascades receipts)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $q = Notification::query()
            ->whereNotNull('ends_at')
            ->where('ends_at', '<', $cutoff);

        $count = (clone $q)->count();

        if ($this->option('dry-run')) {
            $this->info("Would delete {$count} notifications with ends_at < {$cutoff}");

            return self::SUCCESS;
        }

        $deleted = $q->delete(); // relies ON DELETE CASCADE to remove receipts
        $this->info("Deleted {$deleted} notifications.");

        return self::SUCCESS;
    }
}
