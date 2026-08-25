<?php

namespace App\Console\Commands;

use App\Ingest\Projection\ProjectionWriter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuild content.items.headline_cache / facets_cache for rows whose cache
 * disagrees with their facts (X4, overnight 2026-08-18: a dish with a
 * landed f_text.headline and a NULL headline_cache read "Untitled" on the
 * wire). Default scope is the stale set only — cache NULL while a headline
 * exists; --all rebuilds every live item for the user(s). Idempotent, safe to
 * schedule.
 */
class RefreshItemCachesCommand extends Command
{
    protected $signature = 'content:refresh-item-caches
        {--user= : one user handle; default every user}
        {--all : rebuild every live item, not just the stale ones}
        {--dry-run : report only}';

    protected $description = 'Rebuild headline/facets caches on content.items rows that missed their projection refresh.';

    public function handle(ProjectionWriter $writer): int
    {
        $query = DB::table('content.items as i')->whereNull('i.removed_at');
        if ($handle = $this->option('user')) {
            $userId = DB::table('users')->where('handle', $handle)->value('id');
            if ($userId === null) {
                $this->error("No user '{$handle}'.");

                return self::FAILURE;
            }
            $query->where('i.user_id', $userId);
        }
        if (! $this->option('all')) {
            $query->whereNull('i.headline_cache')
                ->whereExists(function ($q) {
                    $q->from('content.f_text as ft')
                        ->whereColumn('ft.item_id', 'i.id')
                        ->whereNotNull('ft.headline');
                });
        }

        $dryRun = (bool) $this->option('dry-run');

        // Phase 1: the distinct user ids matching the filter. A deduped uuid
        // column is orders of magnitude smaller than the item set, so it's
        // cheap to page through whole here — and safely: this phase makes no
        // writes, so unlike phase 2 below, there is nothing for an OFFSET page
        // boundary to skip mid-scan. With --user this returns at most one row.
        $userIds = [];
        (clone $query)->select('i.user_id')->distinct()->orderBy('i.user_id')
            ->chunk(500, function ($rows) use (&$userIds): void {
                foreach ($rows as $row) {
                    $userIds[] = (string) $row->user_id;
                }
            });

        // Phase 2: chunkById SCOPED TO ONE USER AT A TIME, so a user's items
        // stay contiguous under refreshCachesFor()'s own array_chunk()
        // batching. Keyset-paging the WHOLE backlog by i.id (the first cut of
        // this fix) scattered one user's 100 items across many pages because
        // ids are random UUIDs, not sequential — that scattering is what
        // inflated the query count well past the old ->get()+groupBy shape's.
        // Scoping per user restores one clean batch per user (same shape the
        // old code produced) while keeping memory bounded to one user's chunk,
        // not the whole backlog.
        $total = 0;
        foreach ($userIds as $userId) {
            (clone $query)->where('i.user_id', $userId)->select(['i.id', 'i.user_id'])
                ->chunkById(500, function ($rows) use ($writer, $dryRun, $userId, &$total): void {
                    $total += $rows->count();
                    if (! $dryRun) {
                        $writer->refreshCachesFor($userId, $rows->pluck('id')->map(fn ($id) => (string) $id)->all());
                    }
                }, 'i.id', 'id');
        }

        $users = count($userIds);
        $this->info(($dryRun ? "Would refresh {$total} item(s)" : "Refreshed {$total} item(s)")." across {$users} user(s).");

        return self::SUCCESS;
    }
}
