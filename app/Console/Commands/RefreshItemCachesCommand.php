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

        $byUser = $query->get(['i.id', 'i.user_id'])->groupBy('user_id');
        $total = $byUser->sum(fn ($rows) => $rows->count());
        if ($this->option('dry-run')) {
            $this->info("Would refresh {$total} item(s) across {$byUser->count()} user(s).");

            return self::SUCCESS;
        }
        foreach ($byUser as $userId => $rows) {
            $writer->refreshCachesFor((string) $userId, $rows->pluck('id')->map(fn ($id) => (string) $id)->all());
        }
        $this->info("Refreshed {$total} item(s) across {$byUser->count()} user(s).");

        return self::SUCCESS;
    }
}
