<?php

namespace App\Observers\Core;

use App\Models\Core\Site\Menu;
use RuntimeException;

// DINT-102: MenuFetchJob::clearScrapedContent() only soft-deletes a Menu after
// checking `! $menu->categories()->exists()` — the "never delete a menu that
// still has live children" invariant held by CONVENTION at that one call site,
// not by any model/DB guarantee. This observer makes it a model-level guard so
// a future delete path (a new controller action, a console command, a careless
// refactor) can't silently orphan site.menu_categories / site.menu_items rows
// under a vanished menu_id.
//
// Guards BOTH lifecycle events. ->forceDelete() (PurgeSoftDeleted's retention
// purge) fires `forceDeleting` AND then `deleting`, because SoftDeletes::
// forceDelete() calls the base Model::delete() internally — so `deleting`
// alone would in fact catch both paths today. Both are guarded anyway: it
// blocks at the earliest event on either path, and it avoids depending on
// SoftDeletes' internal plumbing staying that way across framework upgrades.
//
// Not covered: core.users deletion cascades to site.menus at the Postgres FK
// level (AccountDeletionService::purge()), bypassing Eloquent events entirely.
// That's fine — the whole menu family cascades together, so nothing is orphaned.
// This guard is model-layer, not DB-layer.
class MenuObserver
{
    public function deleting(Menu $menu): void
    {
        $this->guardNoLiveCategories($menu);
    }

    public function forceDeleting(Menu $menu): void
    {
        $this->guardNoLiveCategories($menu);
    }

    /**
     * Fails loud (not a silent `return false`) — a call site that hits this
     * has a bug to fix (clear/reassign categories first), not a case to
     * quietly swallow. Matches MenuFetchJob::clearScrapedContent()'s own
     * check so the two never disagree.
     */
    private function guardNoLiveCategories(Menu $menu): void
    {
        if ($menu->categories()->exists()) {
            throw new RuntimeException(
                "Refusing to delete menu {$menu->id}: it still has live categories. ".
                'Clear or reassign its menu_categories rows first (see MenuFetchJob::clearScrapedContent).'
            );
        }
    }
}
