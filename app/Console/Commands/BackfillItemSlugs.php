<?php

namespace App\Console\Commands;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuItem;
use App\Services\Platforms\EventSlugSync;
use App\Services\Site\ItemSlugAllocator;
use Illuminate\Console\Command;

// One-off backfill (#item-url-slugs): mint site.item_slugs rows for menu items
// and synced events that predate the slug system, so their old hex/uuid URLs
// start redirecting to a pretty slug immediately once apps/pages ships, rather
// than waiting for the next rename/re-sync. ensureCurrent() is idempotent per
// item, so this is safe to run repeatedly (a re-run mints nothing new).
class BackfillItemSlugs extends Command
{
    protected $signature = 'slugs:backfill';

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

        return ($menuFailed > 0 || $eventFailed > 0) ? self::FAILURE : self::SUCCESS;
    }
}
