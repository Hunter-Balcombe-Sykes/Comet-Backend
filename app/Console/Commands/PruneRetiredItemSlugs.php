<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sweeps two tables that share the retired_at/is_current retention shape
 * (271-PRIV-1): site.item_slugs (the live public-URL registry, with its own
 * allocator and 301-alias read path) and content.item_slugs (a currently
 * vestigial table -- ItemMerger::moveSlugs() is its only writer, and nothing
 * reads it for a public payload). One command, one shared cutoff, because the
 * predicates and reasoning are identical; splitting them would just be two
 * copies of the same TOCTOU note.
 */
class PruneRetiredItemSlugs extends Command
{
    protected $signature = 'slugs:prune-retired {--dry-run : Report counts without deleting}';

    protected $description = 'Hard-delete site.item_slugs and content.item_slugs rows whose retention window has lapsed (271-PRIV-1).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $pgsql = DB::connection('pgsql');

        // Fix the cutoff once so the counts and the deletes see the same boundary
        // (the TOCTOU note on PruneExpiredHandleAliases applies here too). SCALE-9:
        // delete in-place by predicate rather than plucking every id into memory.
        // Shared across both tables below -- one boundary, not two clocks.
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

        // Same two-arm shape against content.item_slugs. The stranded arm here
        // is theoretical today -- ItemMerger::moveSlugs() stamps retired_at and
        // is_current in the SAME update, so it cannot itself produce a stranded
        // row -- but the predicate is kept symmetric with the site table so a
        // future minter/allocator for this table (see the migration's trap
        // comment) is covered by construction rather than by someone remembering
        // to come back and add it.
        $expiredRetiredContent = fn () => $pgsql->table('content.item_slugs')
            ->whereNotNull('retired_at')->where('retired_at', '<', $cutoff);

        $strandedRowsContent = fn () => $pgsql->table('content.item_slugs')
            ->where('is_current', false)->whereNull('retired_at')
            ->where('created_at', '<', $cutoff);

        $retiredCount = $expiredRetired()->count();
        $strandedCount = $strandedRows()->count();
        $retiredCountContent = $expiredRetiredContent()->count();
        $strandedCountContent = $strandedRowsContent()->count();

        $this->info("Expired retired item slugs (site): {$retiredCount}");
        $this->info("Stranded unstamped item slugs (site): {$strandedCount}");
        $this->info("Expired retired item slugs (content): {$retiredCountContent}");
        $this->info("Stranded unstamped item slugs (content): {$strandedCountContent}");

        if ($dry) {
            return self::SUCCESS;
        }

        // No Cloudflare KV re-sync and no cache purge here, unlike the handle-alias
        // sibling command. Handles/subdomains are routing keys in KV; item slugs
        // are not. And the site.item_slugs delete changes nothing observable at
        // the moment it runs: lookupCurrent()'s active-window predicate is
        // deliberately kept symmetric with both predicates above (retired_at for
        // arm 1, created_at for the stranded arm 2), so it already stopped
        // serving a row as a 301 alias in the public payload the instant that
        // same cutoff crossed it.
        //
        // content.item_slugs has no such read path to worry about at all: it is
        // not built into site.documents (the cache-fronted public payload) or
        // any Cache:: key -- content staleness is tracked separately via
        // BuildState::bump()/site_build_state.content_revision, and nothing
        // populates that revision from this table today because nothing reads
        // it. The delete is observably a no-op the same instant it runs, for a
        // stronger reason than the site table: there was never a live reader.
        $pgsql->transaction(function () use ($expiredRetired, $strandedRows, $expiredRetiredContent, $strandedRowsContent) {
            $expiredRetired()->delete();
            $strandedRows()->delete();
            $expiredRetiredContent()->delete();
            $strandedRowsContent()->delete();
        });

        Log::info('slugs.prune.completed', [
            'retired_slugs_deleted' => $retiredCount,
            'stranded_slugs_deleted' => $strandedCount,
            'retired_slugs_deleted_content' => $retiredCountContent,
            'stranded_slugs_deleted_content' => $strandedCountContent,
        ]);

        return self::SUCCESS;
    }
}
