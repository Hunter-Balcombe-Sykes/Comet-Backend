<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PruneRetiredItemSlugs extends Command
{
    protected $signature = 'slugs:prune-retired {--dry-run : Report counts without deleting}';

    protected $description = 'Hard-delete site.item_slugs rows whose retention window has lapsed (271-PRIV-1).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $pgsql = DB::connection('pgsql');

        // Fix the cutoff once so the counts and the deletes see the same boundary
        // (the TOCTOU note on PruneExpiredHandleAliases applies here too). SCALE-9:
        // delete in-place by predicate rather than plucking every id into memory.
        $cutoff = now()->subDays((int) config('partna.item_slugs.retirement_days', 90));

        // Predicate 1: properly retired rows past the window.
        $expiredRetired = fn () => $pgsql->table('site.item_slugs')
            ->whereNotNull('retired_at')->where('retired_at', '<', $cutoff);

        // Predicate 2 (adopted, diverges from the handle-alias command's shape):
        // stranded is_current=false rows that were never stamped. Post-backfill,
        // the only way to produce one is a crash between insertUnique(..., false)
        // and promote() inside ensureCurrent() -- those two calls are NOT wrapped
        // in one transaction. Gating on created_at < $cutoff (rather than any
        // is_current=false row) makes it impossible to catch an in-flight rename.
        $strandedRows = fn () => $pgsql->table('site.item_slugs')
            ->where('is_current', false)->whereNull('retired_at')
            ->where('created_at', '<', $cutoff);

        $retiredCount = $expiredRetired()->count();
        $strandedCount = $strandedRows()->count();

        $this->info("Expired retired item slugs: {$retiredCount}");
        $this->info("Stranded unstamped item slugs: {$strandedCount}");

        if ($dry) {
            return self::SUCCESS;
        }

        // No Cloudflare KV re-sync and no cache purge here, unlike the handle-alias
        // sibling command. Handles/subdomains are routing keys in KV; item slugs
        // are not. And the delete changes nothing observable at the moment it
        // runs: lookupCurrent()'s active-window predicate already stopped serving
        // these as 301 aliases in the public payload the instant retired_at (or
        // created_at, for a stranded row) crossed the same cutoff.
        $pgsql->transaction(function () use ($expiredRetired, $strandedRows) {
            $expiredRetired()->delete();
            $strandedRows()->delete();
        });

        Log::info('slugs.prune.completed', [
            'retired_slugs_deleted' => $retiredCount,
            'stranded_slugs_deleted' => $strandedCount,
        ]);

        return self::SUCCESS;
    }
}
