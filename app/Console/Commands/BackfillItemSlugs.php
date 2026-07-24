<?php

namespace App\Console\Commands;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuItem;
use App\Services\Platforms\EventSlugSync;
use App\Services\Site\ItemSlugAllocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// One-off backfill (#item-url-slugs): mint site.item_slugs rows for menu items
// and synced events that predate the slug system, so their old hex/uuid URLs
// start redirecting to a pretty slug immediately once apps/pages ships, rather
// than waiting for the next rename/re-sync. ensureCurrent() is idempotent per
// item, so this is safe to run repeatedly (a re-run mints nothing new).
class BackfillItemSlugs extends Command
{
    protected $signature = 'slugs:backfill {--prune : Also DELETE item_slugs rows whose item no longer exists}';

    protected $description = 'Backfill site.item_slugs for existing menu items and synced events';

    public function handle(ItemSlugAllocator $allocator, EventSlugSync $eventSync): int
    {
        $menuFilled = 0;
        $menuFailed = 0;

        // Iterate menu -> items (rather than a raw join) so the query stays
        // portable between the SQLite test mirror and production Postgres —
        // SQLite rejects a schema-qualified wildcard select against a joined
        // ATTACHed table.
        Menu::query()->orderBy('id')->cursor()->each(function (Menu $menu) use ($allocator, &$menuFilled, &$menuFailed) {
            /** @var MenuItem $item */
            foreach ($menu->items()->cursor() as $item) {
                try {
                    $allocator->ensureCurrent((string) $menu->user_id, ItemSlugAllocator::TYPE_MENU_ITEM, $item->id, (string) $item->name);
                    $menuFilled++;
                } catch (\Throwable $e) {
                    report($e);
                    $menuFailed++;
                    $this->warn("  ! menu item {$item->id}: {$e->getMessage()}");
                }
            }
        });

        $this->info("Menu items: {$menuFilled} synced".($menuFailed > 0 ? ", {$menuFailed} failed" : '.'));

        $eventConnections = 0;
        $eventFailed = 0;

        IntegrationConnection::query()
            ->whereIn('platform', EventSlugSync::PLATFORMS)
            ->where('is_active', true)
            ->orderBy('id')
            ->cursor()
            ->each(function (IntegrationConnection $connection) use ($eventSync, &$eventConnections, &$eventFailed) {
                try {
                    $events = EventSlugSync::extractEvents($connection->resource_kind, $connection->payload);
                    $eventSync->syncEvents($connection->user_id, $events);
                    $eventConnections++;
                } catch (\Throwable $e) {
                    report($e);
                    $eventFailed++;
                    $this->warn("  ! connection {$connection->id}: {$e->getMessage()}");
                }
            });

        $this->info("Event connections: {$eventConnections} synced".($eventFailed > 0 ? ", {$eventFailed} failed" : '.'));

        $pruneFailed = 0;
        if ($this->option('prune')) {
            $pruneFailed = $this->prune($allocator);
        }

        return ($menuFailed > 0 || $eventFailed > 0 || $pruneFailed > 0) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * OPT-IN orphan sweep. The writers that mint slugs used to have no
     * retirement counterpart (a wholesale menu rebuild and an events refresh
     * both dropped items without freeing their slug), so production can already
     * hold rows for items that no longer exist. Those rows are not merely
     * cosmetic: item_slugs_unique_slug is NON-partial and scoped to
     * (user_id, slug), so every orphan permanently pushes a future same-named
     * item onto a `-N` suffix.
     *
     * Off by default and never scheduled: a diff-driven mass DELETE gets a
     * verified manual run first.
     *
     * Deliberately JOIN-FREE — the query stays portable between the SQLite test
     * mirror and production Postgres (SQLite rejects a schema-qualified
     * wildcard select against a joined ATTACHed table), which is why the whole
     * file reads with a cursor and diffs in PHP.
     */
    private function prune(ItemSlugAllocator $allocator): int
    {
        $userIds = DB::connection('pgsql')->table('site.item_slugs')
            ->distinct()->pluck('user_id')->map(fn ($id) => (string) $id)->all();

        $menuPruned = 0;
        $eventPruned = 0;
        $pruneFailed = 0;

        foreach ($userIds as $userId) {
            // The slug set is read BEFORE the live set, for both types below —
            // closes the race where a slug minted concurrently (a MenuFetchJob
            // or a connect landing mid-sweep) would otherwise be captured in
            // the live set's read but miss an earlier slug-set read, or vice
            // versa. Reading the slug set first means a slug minted after that
            // read simply isn't a pruning candidate at all: it can't appear in
            // $slugKeys, so it can't be misdiagnosed as an orphan even though
            // it may or may not have made it into the live set's later read.
            try {
                $menuSlugKeys = $this->slugKeysFor($userId, ItemSlugAllocator::TYPE_MENU_ITEM);

                // Menu items — a user with no LIVE (non-soft-deleted) menu row has
                // no live ids here, so every menu_item slug they own is treated as
                // an orphan. Menu uses SoftDeletes but MenuItem does not, so a
                // soft-deleted menu can in principle still have hard menu_items
                // rows under it; Menu::query() (default scope) can't see past the
                // soft-delete either way. Benign: a soft-deleted menu is never
                // served publicly, so there's no live URL for a freed slug to break.
                $menuId = Menu::query()->where('user_id', $userId)->value('id');
                $liveItemIds = $menuId === null
                    ? []
                    : MenuItem::query()->where('menu_id', $menuId)->pluck('id')->map(fn ($id) => (string) $id)->all();
                $menuPruned += $this->pruneType($allocator, $userId, ItemSlugAllocator::TYPE_MENU_ITEM, $menuSlugKeys, $liveItemIds);
            } catch (\Throwable $e) {
                report($e);
                $pruneFailed++;
                $this->warn("  ! prune menu-item user {$userId}: {$e->getMessage()}");
            }

            try {
                $eventSlugKeys = $this->slugKeysFor($userId, ItemSlugAllocator::TYPE_EVENT);

                // Events — the union across ALL of the user's live event
                // connections, ACTIVE AND INACTIVE, exactly the inclusive set the
                // observer's sibling guard uses (an inactive connection is hidden,
                // not gone, and must keep its slugs).
                $liveEventIds = [];
                IntegrationConnection::query()
                    ->where('user_id', $userId)
                    ->whereIn('platform', EventSlugSync::PLATFORMS)
                    ->orderBy('id')
                    ->cursor()
                    ->each(function (IntegrationConnection $connection) use (&$liveEventIds) {
                        foreach (EventSlugSync::eventIds($connection->resource_kind, $connection->payload) as $id) {
                            $liveEventIds[] = $id;
                        }
                    });
                $eventPruned += $this->pruneType($allocator, $userId, ItemSlugAllocator::TYPE_EVENT, $eventSlugKeys, $liveEventIds);
            } catch (\Throwable $e) {
                report($e);
                $pruneFailed++;
                $this->warn("  ! prune event user {$userId}: {$e->getMessage()}");
            }
        }

        $this->info("Pruned orphans: {$menuPruned} menu-item, {$eventPruned} event".($pruneFailed > 0 ? ", {$pruneFailed} failed" : '.'));

        return $pruneFailed;
    }

    /**
     * The distinct item_slugs keys a user owns for $itemType. Split out of
     * pruneType() so prune() can read it BEFORE computing the live set — see
     * prune()'s note on why that order matters.
     *
     * @return list<string>
     */
    private function slugKeysFor(string $userId, string $itemType): array
    {
        return DB::connection('pgsql')->table('site.item_slugs')
            ->where('user_id', $userId)->where('item_type', $itemType)
            ->distinct()->pluck('item_key')->map(fn ($key) => (string) $key)->all();
    }

    /**
     * Delete one user's item_slugs rows of $itemType whose item_key is in
     * $slugKeys but not in $liveKeys. Returns the number of distinct item keys
     * forgotten.
     *
     * @param  list<string>  $slugKeys
     * @param  list<string>  $liveKeys
     */
    private function pruneType(ItemSlugAllocator $allocator, string $userId, string $itemType, array $slugKeys, array $liveKeys): int
    {
        $orphans = array_values(array_diff($slugKeys, array_map('strval', $liveKeys)));
        if ($orphans === []) {
            return 0;
        }

        $allocator->forgetMany($userId, $itemType, $orphans);

        return count($orphans);
    }
}
