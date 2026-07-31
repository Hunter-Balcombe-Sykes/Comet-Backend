<?php

namespace App\Observers\Core;

use App\Models\Core\Site\Menu;
use RuntimeException;

// DINT-102: MenuFetchJob::clearScrapedContent() only soft-deletes a Menu after
// checking `! $menu->categories()->exists()` — the "never delete a menu that
// still has live children" invariant held by CONVENTION at that one call site,
// not by any model/DB guarantee. This observer makes it a model-level guard so
// a future delete path (a new controller action, a console command, a careless
// refactor) can't leave a category tree pointing at a menu the user can no
// longer see.
//
// SOFT DELETE ONLY. The guard originally covered the retention hard-delete too;
// that was wrong and jammed PurgeSoftDeleted permanently (Nightwatch #297,
// reopened four times). MenuCategory does NOT use SoftDeletes and nothing
// deletes categories when a Menu is soft-deleted, so `categories()->exists()`
// stays true forever — the nightly 03:20 UTC purge retried the same rows and
// threw on every one of them, and no menu could ever age out.
//
// The premise for guarding the hard-delete was false. All three children are
// ON DELETE CASCADE at the Postgres level (verified on dev
// glncumufgaqcmqhzwrxm, confdeltype = 'c'):
//
//   menu_categories_menu_id_fkey     -> site.menus
//   menu_items_menu_id_fkey          -> site.menus
//   menu_platform_links_menu_id_fkey -> site.menus
//
// so a hard delete cannot orphan anything — Postgres erases the tree with the
// menu. That is the same reasoning this file already applied to the core.users
// cascade below. A retention purge whose entire purpose is to erase the tree
// was never the case the guard was defending against.
//
// A soft delete is still guarded: it leaves the category tree live and
// reachable under a menu the user can no longer see, which is exactly the
// orphan-shaped state DINT-102 was about.
//
// The `deleting` event alone covers both paths, gated on isForceDeleting().
// Read SoftDeletes::forceDelete() carefully before touching this — the order
// is load-bearing and not the intuitive one:
//
//   1. fires `forceDeleting`        <- the flag is still FALSE here
//   2. sets $forceDeleting = true
//   3. calls Model::delete()        -> fires `deleting`, flag now TRUE
//
// So `deleting` fires on BOTH paths and is the only event that can tell them
// apart. Two consequences:
//
//   - Simply dropping the old forceDeleting() handler would NOT have unblocked
//     the purge. `deleting` fires afterwards regardless and would still have
//     thrown. The gate is the fix, not the deletion of the handler.
//   - Do NOT re-add a forceDeleting() handler gated the same way. At step 1
//     isForceDeleting() is still false, so the gate would not fire, the guard
//     would run, and #297 would return verbatim.
//
// Not covered: core.users deletion cascades to site.menus at the Postgres FK
// level (AccountDeletionService::purge()), bypassing Eloquent events entirely.
// That's fine — the whole menu family cascades together, so nothing is orphaned.
// This guard is model-layer, not DB-layer.
class MenuObserver
{
    public function deleting(Menu $menu): void
    {
        // The retention hard-delete is the one delete that is SUPPOSED to take
        // the tree with it, and the FK cascade guarantees it does.
        if ($menu->isForceDeleting()) {
            return;
        }

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
