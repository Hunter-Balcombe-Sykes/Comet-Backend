<?php

namespace App\Console\Commands;

use App\Services\Migration\BorrowedAssetPruner;
use Illuminate\Console\Command;

/**
 * Artisan wrapper for the slice-1b borrowed-asset prune (spec D4).
 *
 * Not housekeeping: Google's Places terms grant photos no caching exemption,
 * so this is the mechanism by which borrowed content stops being retained.
 * Idempotent and safe to re-run — a second pass finds nothing.
 */
class PruneBorrowedAssetsCommand extends Command
{
    protected $signature = 'content:prune-borrowed-assets
        {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete unreferenced borrowed media assets past the vendor url expiry window';

    public function handle(BorrowedAssetPruner $pruner): int
    {
        $dry = (bool) $this->option('dry-run');
        $result = $pruner->run($dry);

        $verb = $dry ? 'would prune' : 'pruned';
        $this->info("Borrowed assets: {$verb} {$result['pruned']}");
        $this->line("Spared (owned bytes or upload-backed): {$result['spared_owned']}");
        $this->line("Spared (still referenced by a live item): {$result['spared_referenced']}");
        $this->line("Spared (inside the 30-day url window):   {$result['spared_recent']}");

        return self::SUCCESS;
    }
}
