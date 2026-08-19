<?php

namespace App\Console\Commands;

use App\Jobs\Content\EnrichPoolLinkJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read the page for every links-pool item that never had one read for it.
 *
 * LinkPoolWriter::add() enriches on write since 2026-08-19, but the links that
 * predate that — the Phase-3 backfill's rows, and everything the ordering /
 * booking / reservations fallbacks and the Google harvest pooled — carry no
 * favicon, no share image and no description. The owner sees them beside
 * dashboard-added links that have all three: same pool, two different-looking
 * halves, for no reason a person could name.
 *
 * Dispatches the ordinary enrichment job, so this shares the manual lane's
 * rules exactly: the title upgrades only off the host fallback, the body only
 * when empty, images whenever the page has them.
 */
class EnrichPoolLinksCommand extends Command
{
    protected $signature = 'content:enrich-pool-links
        {--user= : limit to one user id}
        {--missing : only items with no cover/logo media AND no body (the default target)}
        {--dry-run}';

    protected $description = 'Fetch favicon, share image and description for links-pool items that have none';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $onlyMissing = ! $this->option('user') || (bool) $this->option('missing');

        $rows = DB::connection('pgsql')->table('content.source_items as si')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->join('content.items as i', 'i.id', '=', 'si.item_id')
            ->leftJoin('content.f_link as fl', 'fl.item_id', '=', 'i.id')
            ->leftJoin('content.f_text as ft', 'ft.item_id', '=', 'i.id')
            ->where('cs.kind', 'manual')
            ->where('i.kind', 'link')
            ->whereNull('i.removed_at')
            ->whereNull('si.removed_at')
            ->whereNotNull('fl.url')
            ->when($this->option('user'), fn ($q, $id) => $q->where('cs.user_id', $id))
            // The target: nothing was ever read for this link. An item with a
            // body or a picture has been enriched (or the owner typed it), and
            // re-fetching would spend an HTTP call to learn nothing.
            ->when($onlyMissing, fn ($q) => $q
                ->where(fn ($w) => $w->whereNull('ft.body')->orWhere('ft.body', ''))
                ->whereNotExists(fn ($e) => $e->select(DB::raw(1))
                    ->from('content.item_media as im')
                    ->whereColumn('im.item_id', 'i.id')
                    ->whereIn('im.role', ['cover', 'logo'])))
            ->distinct()
            ->get(['cs.user_id', 'fl.url']);

        foreach ($rows as $row) {
            if (! $dryRun) {
                EnrichPoolLinkJob::dispatch((string) $row->user_id, (string) $row->url);
            }
        }

        $this->line(($dryRun ? '[dry-run] would enrich ' : 'queued ').$rows->count().' pool link(s)');

        return self::SUCCESS;
    }
}
