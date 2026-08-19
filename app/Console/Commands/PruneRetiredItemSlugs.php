<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sweeps content.item_slugs on the retired_at/is_current retention shape
 * (271-PRIV-1).
 *
 * It used to sweep site.item_slugs on the same cutoff. That table was dropped
 * on dev (20260819130000) once slice 7 Phase 6 retired its last writer, and its
 * two arms went with it -- they had become unsatisfiable by construction, since
 * nothing was left to stamp retired_at or clear is_current. Do not restore them
 * as a "harmless" no-op: a dead arm alongside a live one is what made this
 * command read as an inert job under a partial look at the file.
 */
class PruneRetiredItemSlugs extends Command
{
    protected $signature = 'slugs:prune-retired {--dry-run : Report counts without deleting}';

    protected $description = 'Hard-delete content.item_slugs rows whose retention window has lapsed (271-PRIV-1).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $pgsql = DB::connection('pgsql');

        // Fix the cutoff once so the counts and the deletes see the same boundary
        // (the TOCTOU note on PruneExpiredHandleAliases applies here too). SCALE-9:
        // delete in-place by predicate rather than plucking every id into memory.
        $cutoff = now()->subDays((int) config('partna.item_slugs.retirement_days', 90));

        // Predicate 1: properly retired rows past the window.
        $expiredRetired = fn () => $pgsql->table('content.item_slugs')
            ->whereNotNull('retired_at')->where('retired_at', '<', $cutoff);

        // Predicate 2 (adopted, diverges from the handle-alias command's shape):
        // stranded is_current=false rows that were never stamped. Real in two
        // ways: retired_at shipped with no backfill (20260731210000), and
        // ContentItemSlugAllocator::allocate() inserts a non-current row before
        // promote() stamps it, so a crash between the two strands one. Gating on
        // created_at < $cutoff (rather than any is_current=false row) makes it
        // impossible to catch an in-flight rename.
        // ContentItemSlugAllocator::lookupCurrent() serves stranded rows off
        // created_at, and this arm ages them out on the same window -- the two
        // must agree.
        $strandedRows = fn () => $pgsql->table('content.item_slugs')
            ->where('is_current', false)->whereNull('retired_at')
            ->where('created_at', '<', $cutoff);

        $retiredCount = $expiredRetired()->count();
        $strandedCount = $strandedRows()->count();

        $this->info("Expired retired item slugs (content): {$retiredCount}");
        $this->info("Stranded unstamped item slugs (content): {$strandedCount}");

        if ($dry) {
            return self::SUCCESS;
        }

        // No Cloudflare KV re-sync and no cache purge here, unlike the handle-alias
        // sibling command. Handles/subdomains are routing keys in KV; item slugs
        // are not.
        //
        // content.item_slugs DOES have a live read path (slice 2 Task 9):
        // PoolResolver::itemPayloads() serves slug/aliases from it on every pool
        // resolve, and that payload is Redis- and edge-cached. It is not built
        // into site.documents, and an alias this sweep deletes had already
        // stopped resolving anyway -- lookupCurrent() filters on the identical
        // window, for arm 1 on retired_at and for arm 2 on created_at.
        $pgsql->transaction(function () use ($expiredRetired, $strandedRows) {
            $expiredRetired()->delete();
            $strandedRows()->delete();
        });

        Log::info('slugs.prune.completed', [
            'retired_slugs_deleted_content' => $retiredCount,
            'stranded_slugs_deleted_content' => $strandedCount,
        ]);

        return self::SUCCESS;
    }
}
