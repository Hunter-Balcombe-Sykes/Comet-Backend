<?php

namespace App\Observers;

use App\Models\Core\Site\MenuItem;
use App\Services\Site\ItemSlugAllocator;
use Illuminate\Support\Facades\Log;
use Throwable;

// Keeps site.item_slugs in step with a dish's lifecycle: mint on create,
// re-slug (old slug retained as a 301 redirect) when the name changes, and
// free the slug when the dish is deleted. Menu items are the only in-scope
// entity a user can rename from the dashboard.
//
// Slug bookkeeping is best-effort: it must never break a dish write. If it
// fails, the pages app's raw-id URL fallback still resolves the item, and the
// next edit or `slugs:backfill` run mints the slug. So every hook swallows +
// logs rather than propagating.
class MenuItemObserver
{
    public function __construct(private ItemSlugAllocator $slugs) {}

    public function created(MenuItem $item): void
    {
        $this->sync($item);
    }

    public function updated(MenuItem $item): void
    {
        if ($item->wasChanged('name')) {
            $this->sync($item);
        }
    }

    public function deleted(MenuItem $item): void
    {
        $owner = $this->ownerId($item);
        if ($owner === '') {
            return;
        }
        try {
            $this->slugs->forget($owner, ItemSlugAllocator::TYPE_MENU_ITEM, $item->id);
        } catch (Throwable $e) {
            Log::warning('item-slug forget failed', ['menu_item' => $item->id, 'error' => $e->getMessage()]);
        }
    }

    private function sync(MenuItem $item): void
    {
        $owner = $this->ownerId($item);
        if ($owner === '') {
            return;
        }
        try {
            $this->slugs->ensureCurrent($owner, ItemSlugAllocator::TYPE_MENU_ITEM, $item->id, (string) $item->name);
        } catch (Throwable $e) {
            Log::warning('item-slug mint failed', ['menu_item' => $item->id, 'error' => $e->getMessage()]);
        }
    }

    // The owning profile — a menu item's user is its menu's user (one menu per user).
    private function ownerId(MenuItem $item): string
    {
        return (string) ($item->menu()->value('user_id') ?? '');
    }
}
