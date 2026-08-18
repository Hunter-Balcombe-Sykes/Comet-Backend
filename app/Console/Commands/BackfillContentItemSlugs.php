<?php

namespace App\Console\Commands;

use App\Services\Content\ContentItemSlugAllocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Mint `content.item_slugs` rows for content items that predate the pool
 * lane's slug registry (slice 2, Task 9 Step 2).
 *
 * Why this exists: the legacy events lane emitted `slug`/`aliases` from
 * `site.item_slugs` onto `GET /api/public/profiles/{handle}/integrations`.
 * Retiring that lane removes the only surface serving event slugs, so the
 * pool payload has to serve them instead — and it can only do that once this
 * table is populated (it had ZERO rows and, before this slice, zero readers).
 *
 * Idempotent: ensureCurrent() is a no-op when the live slug already matches
 * the headline, so a re-run mints nothing. Safe to run repeatedly.
 *
 * NOT scheduled — a one-off, like slugs:backfill. Ongoing minting rides the
 * write paths.
 *
 * Deliberately in app/Console/Commands rather than the app/Services/Migration
 * the plan named: that directory does not exist in this codebase, and every
 * sibling backfiller (BackfillItemSlugs, BackfillSocialLinksCommand, …) lives
 * here. The allocator it delegates to IS the production code.
 */
class BackfillContentItemSlugs extends Command
{
    protected $signature = 'content:backfill-item-slugs
        {--dry-run : Report what would be minted without writing}
        {--kind=* : Only these content kinds (default: event)}
        {--user= : Only items belonging to this user id}';

    protected $description = 'Backfill content.item_slugs for existing content items';

    public function handle(ContentItemSlugAllocator $allocator): int
    {
        $dry = (bool) $this->option('dry-run');
        $kinds = $this->option('kind') ?: ['event'];

        $minted = 0;
        $skipped = 0;
        $failed = 0;
        $chunk = (int) config('partna.content.slug_backfill_chunk', 500);

        // chunkById(id), not cursor(): pdo_pgsql has no unbuffered fetch mode,
        // so cursor() still buffers the whole result set in libpq while
        // pinning one open result set for the run's duration — the same
        // reasoning as IngestProjectCommand's source walk. The loop body
        // writes only content.item_slugs, so the content.items predicate
        // (kind/removed_at/user) is invariant across pages — no row here can
        // move in or out of scope as a side effect of processing it, unlike
        // BackfillMediaPaletteCommand's mutating whereNull('palette'). The
        // per-chunk preload below is scoped to THIS page's ids, never a
        // global whereIn — a global one would reintroduce the unbounded load
        // chunking exists to avoid. JOIN-free throughout: the SQLite test
        // mirror rejects a schema-qualified wildcard select against a joined
        // ATTACHed table, which is why every sibling backfiller reads this
        // way and why the preload below selects explicit columns, never `*`.
        DB::connection('pgsql')->table('content.items')
            ->whereIn('kind', $kinds)
            ->whereNull('removed_at')
            ->when($this->option('user'), fn ($q, $u) => $q->where('user_id', $u))
            ->select(['id', 'user_id', 'headline_cache'])
            ->chunkById($chunk, function ($items) use ($allocator, $dry, &$minted, &$skipped, &$failed) {
                // One query per chunk, keyed on the composite (user_id, item_id) —
                // NOT item_id alone. The unique index makes them usually agree,
                // but a slug row whose user_id disagrees with the item's owner
                // would otherwise flip a "mint" into a "skip".
                $slugRows = DB::connection('pgsql')->table('content.item_slugs')
                    ->whereIn('item_id', $items->pluck('id')->all())
                    ->where('is_current', true)
                    ->get(['user_id', 'item_id']);

                $hasCurrent = [];
                foreach ($slugRows as $row) {
                    $hasCurrent[$row->user_id.'|'.$row->item_id] = true;
                }

                foreach ($items as $item) {
                    $userId = (string) $item->user_id;
                    $itemId = (string) $item->id;

                    // An unheadlined item would slug to `item-<6 hex>`, which is
                    // not a human-readable URL and would then squat that name.
                    // Leave it: the next projection that lands a headline mints it.
                    $headline = trim((string) ($item->headline_cache ?? ''));
                    if ($headline === '') {
                        $skipped++;

                        continue;
                    }

                    if (isset($hasCurrent[$userId.'|'.$itemId])) {
                        $skipped++;

                        continue;
                    }

                    if ($dry) {
                        $this->line("  + {$itemId} → ".Str::slug($headline));
                        $minted++;

                        continue;
                    }

                    try {
                        $allocator->ensureCurrent($userId, $itemId, $headline);
                        $minted++;
                    } catch (\Throwable $e) {
                        report($e);
                        $failed++;
                        $this->warn("  ! item {$itemId}: {$e->getMessage()}");
                    }
                }
            });

        $verb = $dry ? 'would mint' : 'minted';
        $this->info("Content item slugs: {$verb} {$minted}, skipped {$skipped}".($failed > 0 ? ", {$failed} failed" : '.'));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
