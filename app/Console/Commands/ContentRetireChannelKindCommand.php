<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes the orphan `channel` rows left behind when convergence Phase 4
 * retired the kind (convergence-log F9/F32).
 *
 * Every remaining row is an orphan by the time this runs: Twitch's were
 * stranded when Phase 1 de-sourced the platform, and Spotify's and
 * SoundCloud's were superseded when both connectors became `track` producers.
 * Nothing emits the kind any more, so this is a one-way cleanup rather than a
 * recurring reconciliation.
 *
 * DRY RUN BY DEFAULT. --apply is required to write, because this deletes item
 * rows and `ingest:project --rebuild` will NOT put them back: rebuild
 * re-derives from the record log, and these records are gone with their
 * connectors.
 */
class ContentRetireChannelKindCommand extends Command
{
    protected $signature = 'content:retire-channel-kind {--apply : Actually delete; omit for a dry run}';

    protected $description = 'Delete the orphan channel items, facet rows and source_items left by Phase 4.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $itemIds = DB::table('content.items')->where('kind', 'channel')->pluck('id');
        $sourceItems = DB::table('content.source_items')->where('kind', 'channel')->count();
        $facets = DB::table('content.f_channel')->count();

        $this->table(['table', 'rows'], [
            ['content.items (kind=channel)', $itemIds->count()],
            ['content.source_items (kind=channel)', $sourceItems],
            ['content.f_channel', $facets],
        ]);

        if ($itemIds->isEmpty() && $sourceItems === 0 && $facets === 0) {
            $this->info('Nothing to retire.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn('DRY RUN — nothing written. Re-run with --apply to delete.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($itemIds, &$deleted) {
            // source_items FIRST. Its item_id FK is SET NULL, not CASCADE — the
            // only such FK among ~30 into content.items — so deleting items
            // first would leave orphaned source_items with a null item_id
            // rather than removing them.
            $deleted['source_items'] = DB::table('content.source_items')->where('kind', 'channel')->delete();

            // f_channel cascades from items, but any row whose item is already
            // gone would survive that, so it is cleared explicitly.
            $deleted['f_channel'] = DB::table('content.f_channel')
                ->when($itemIds->isNotEmpty(), fn ($q) => $q->orWhereIn('item_id', $itemIds))
                ->delete();

            $deleted['items'] = $itemIds->isEmpty()
                ? 0
                : DB::table('content.items')->whereIn('id', $itemIds)->delete();
        });

        $this->info(sprintf(
            'Retired: %d item(s), %d source_item(s), %d f_channel row(s).',
            $deleted['items'] ?? 0,
            $deleted['source_items'] ?? 0,
            $deleted['f_channel'] ?? 0,
        ));

        return self::SUCCESS;
    }
}
