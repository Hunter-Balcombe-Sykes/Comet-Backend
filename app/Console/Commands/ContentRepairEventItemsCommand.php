<?php

namespace App\Console\Commands;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Site\Documents\BuildState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Finds event items that projection left in a bad state.
 *
 * Two distinct populations, both observed on dev 2026-08-11:
 *
 *  incomplete — the item has a live source_item but no f_text row. Signature
 *    of ProjectionWriter::writeFacets() aborting mid-loop, which is what the
 *    f_occurrence zone_confidence CHECK violation did to six Humanitix items
 *    on 2026-07-28 (constraint since widened by 20260731230000). The record
 *    log still holds the good docs, so `ingest:project` is the whole repair —
 *    RunExecutor gates projection on records having CHANGED, which is why
 *    the widened constraint never reached this data on its own.
 *
 *  orphaned — every source_item for the item carries removed_at, but the item
 *    itself does not (spec §9.8). Retired by --retire; see that flag's note
 *    on irreversibility.
 *
 * Read-only unless --retire. Nothing here re-fetches a byte.
 */
class ContentRepairEventItemsCommand extends Command
{
    protected $signature = 'content:repair-event-items
        {--dry-run : Report counts without writing}
        {--user= : Only items belonging to this user id}
        {--retire : Set removed_at on items whose every source item is retired. ONE-WAY — see the class docblock}';

    protected $description = 'Report event items left incomplete or orphaned by projection.';

    public function handle(): int
    {
        $incomplete = $this->incompleteQuery()->get(['content.items.id', 'content.items.user_id']);
        $orphaned = $this->orphanedQuery()->get(['content.items.id', 'content.items.user_id']);

        $this->line('incomplete: '.$incomplete->count());
        $this->line('orphaned: '.$orphaned->count());

        if ($incomplete->isNotEmpty()) {
            // Re-projection is per ingest SOURCE, not per item — projectStream()
            // resolves identity across the whole stream, so asking for one item
            // would give a different (and wrong) merge result.
            $this->warn('Re-project the affected users with:');
            foreach ($incomplete->pluck('user_id')->unique() as $userId) {
                $this->line("  php artisan ingest:project --user={$userId}");
            }
        }

        if ($this->option('retire') && $this->option('dry-run')) {
            // --dry-run wins. Retirement is one-way, and the person most
            // likely to pair the flags is the person trying to preview it.
            $this->warn('--dry-run: would retire '.$orphaned->count().' orphaned event item(s):');
            foreach ($orphaned as $item) {
                $this->line('  '.$item->id);
            }

            return self::SUCCESS;
        }

        if ($this->option('retire') && $orphaned->isNotEmpty()) {
            // Raw write — no Eloquent, so no observer fires. Three things must
            // happen by hand, and nothing in CI will catch a missing one:
            //
            //  1. content.items.removed_at         (the retirement itself)
            //  2. site.sites.updated_at            (bumped via the touch below) —
            //     IndividualProfilePayloadBuilder::cacheKey() is keyed on it,
            //     and BuildState::bump() does NOT move it, so without this the
            //     60s Redis payload cache keeps serving the retired event.
            //  3. the Cloudflare edge purge — same reason PoolController::
            //     poolChanged() does it: the CDN outlives a pool edit.
            DB::connection('pgsql')->table('content.items')
                ->whereIn('id', $orphaned->pluck('id')->all())
                ->update(['removed_at' => now(), 'updated_at' => now()]);

            $sites = DB::connection('pgsql')->table('site.sites')
                ->whereIn('user_id', $orphaned->pluck('user_id')->unique()->all())
                ->whereNull('deleted_at')
                ->get(['id', 'subdomain']);

            foreach ($sites as $site) {
                BuildState::bump((string) $site->id);
                DB::connection('pgsql')->table('site.sites')
                    ->where('id', $site->id)->update(['updated_at' => now()]);
                if (($site->subdomain ?? '') !== '') {
                    CloudflareCachePurgeJob::dispatch($site->subdomain);
                }
            }

            $this->info('Retired '.$orphaned->count().' orphaned event item(s). This is not reversible by re-sync.');
        }

        return self::SUCCESS;
    }

    /** Live event items with a live source item but no headline facet. */
    private function incompleteQuery()
    {
        return DB::connection('pgsql')->table('content.items')
            ->where('content.items.kind', 'event')
            ->whereNull('content.items.removed_at')
            ->when($this->option('user'), fn ($q, $u) => $q->where('content.items.user_id', $u))
            ->whereExists(fn ($e) => $e->from('content.source_items')
                ->whereColumn('content.source_items.item_id', 'content.items.id')
                ->whereNull('content.source_items.removed_at'))
            ->whereNotExists(fn ($e) => $e->from('content.f_text')
                ->whereColumn('content.f_text.item_id', 'content.items.id'));
    }

    /** Live event items whose every source item has been retired. */
    private function orphanedQuery()
    {
        return DB::connection('pgsql')->table('content.items')
            ->where('content.items.kind', 'event')
            ->whereNull('content.items.removed_at')
            ->when($this->option('user'), fn ($q, $u) => $q->where('content.items.user_id', $u))
            ->whereExists(fn ($e) => $e->from('content.source_items')
                ->whereColumn('content.source_items.item_id', 'content.items.id'))
            ->whereNotExists(fn ($e) => $e->from('content.source_items')
                ->whereColumn('content.source_items.item_id', 'content.items.id')
                ->whereNull('content.source_items.removed_at'));
    }
}
