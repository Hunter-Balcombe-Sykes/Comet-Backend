<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\Menu;
use App\Models\Core\User\User;
use App\Services\Content\ManualMenuItems;
use App\Services\Content\MenuCollections;
use Illuminate\Support\Facades\DB;

/**
 * Slice 7 Task 6: the dashboard menu's ORPHAN GATE, taught about the content
 * lane.
 *
 * `MenuPayloadComposer` moved the dish READ onto `content.*` in Task 5 but left
 * three signals behind on `site.menu_*`: `hasOwnerContent()`, and — one file
 * over — `MenuController::status()`'s item count. Task 6 moved the ten owner
 * WRITE verbs, so those three now ask the wrong table: a hand-built menu lives
 * entirely in `content.*`, `site.menu_categories` is empty for it, and the
 * guard in `dashboardPayload()` would blank the payload of every manual-only
 * owner who has no Uber Eats / DoorDash link to fall back on.
 *
 * This class is that guard, re-asked of both lanes, and is what the two menu
 * controllers call instead of `MenuPayloadComposer::dashboardPayload()`. It is
 * a separate class rather than an edit to the composer because Task 6 does not
 * own that file; folding these three methods back into it — and deleting the
 * legacy arms with the tables — is Phase 5/6 work, and is the intended end
 * state, not a permanent split.
 *
 * The composer's own `load()`/`compose()` are used unchanged: the payload shape
 * is Task 5's contract and nothing here touches it.
 */
class MenuDashboardPayload
{
    public function __construct(
        private readonly MenuPayloadComposer $composer,
        private readonly MenuSource $source,
        private readonly ManualMenuItems $items,
        private readonly MenuCollections $collections,
    ) {}

    /**
     * The full dashboard menu payload — `MenuPayloadComposer::dashboardPayload()`
     * with the orphan guard widened to the content lane.
     *
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $menu = $this->composer->load($user);

        // A scraped menu with no backing ordering link AND no owner-authored
        // content is stale — hide it (refresh() cannot re-scrape it anyway).
        // Owner content never depends on an ordering link.
        if ($this->source->resolveAll($user) === null && ! $this->hasOwnerContent($user, $menu)) {
            $menu = null;
        }

        $payload = $this->composer->compose($user, $menu);
        $payload = $this->withEmptyContentCategories((string) $user->id, $menu, $payload);

        return $this->withCategorySources((string) $user->id, $payload);
    }

    /**
     * List the owner's categories when the content lane holds no DISHES yet.
     *
     * `MenuPayloadComposer::categories()` picks its lane on the dish rows alone
     * — content.* wins iff `ManualMenuItems::rows(includeRemoved: true)` is
     * non-empty — so a brand-new menu whose owner has created a category but no
     * dish falls through to the (empty) legacy tables and renders as no
     * categories at all. The dashboard then has nowhere to add the first dish.
     *
     * Deliberately narrow: it only fires when the composer produced NOTHING and
     * a menu row exists, so it can never reorder, drop or duplicate a category
     * the composer did emit. It disappears with the legacy fallback in Phase 5.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withEmptyContentCategories(string $userId, ?Menu $menu, array $payload): array
    {
        if ($menu === null || ($payload['categories'] ?? []) !== []) {
            return $payload;
        }

        $payload['categories'] = $this->collections->list($userId)->map(fn (\stdClass $row) => [
            'id' => (string) $row->id,
            'name' => (string) $row->label,
            'sourcePlatform' => null,
            'items' => [],
        ])->values()->all();

        return $payload;
    }

    /**
     * Refill `sourcePlatform` for content-lane categories.
     *
     * `MenuPayloadComposer::contentCategories()` emits a flat null for it — a
     * loss Task 5 recorded because `content.collections` had no source column
     * and nothing yet decided what replaced it. Task 6 decided
     * (`is_user_created`), so the key can carry a real answer again: the
     * dashboard's edit/delete affordances and its "will no longer stay synced"
     * warning both read it, and a flat null tells the owner every category they
     * created by hand is off-limits — the exact opposite of what the controller
     * enforces.
     *
     * Only ids this class recognises are rewritten, so the legacy fallback
     * branch's own `source_platform` values pass through untouched.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withCategorySources(string $userId, array $payload): array
    {
        $categories = $payload['categories'] ?? null;
        if (! is_array($categories) || $categories === []) {
            return $payload;
        }

        $owned = $this->collections->list($userId)
            ->mapWithKeys(fn (\stdClass $row) => [(string) $row->id => $row->is_user_created])
            ->all();

        $payload['categories'] = array_map(function (array $category) use ($owned) {
            $id = (string) ($category['id'] ?? '');
            if (array_key_exists($id, $owned)) {
                // The three owner-side source_platform values collapse to one
                // bit (Task 6 decision 3), so the owner-side answer reports as
                // 'manual' — the value MenuContentController::EDITABLE_SOURCES
                // matches and the dashboard already understands.
                $category['sourcePlatform'] = $owned[$id] ? 'manual' : null;
            }

            return $category;
        }, $categories);

        return $payload;
    }

    /**
     * Owner-authored content in EITHER lane.
     *
     * Content lane: a live owner-created category (decision 3 — `is_user_created`
     * is `source_platform`'s owner-side half), or a live dish carrying a
     * `content.manual_overrides` row (decision 1 — that IS `is_manual` in
     * `content.*`, the same derivation slice 3b shipped for Fresha).
     *
     * ORed with the legacy answer rather than gated on the content lane being
     * non-empty: during Phase 2 a scrape (Task 7) and a scan (Task 8) still
     * write `site.menu_*`, so an owner can genuinely hold owner-content in the
     * table this task did not move, and the cheaper "content lane wins when
     * non-empty" rule would orphan them.
     */
    public function hasOwnerContent(User $user, ?Menu $menu): bool
    {
        $userId = (string) $user->id;

        if ($this->collections->list($userId)->contains(fn (\stdClass $row) => $row->is_user_created)) {
            return true;
        }

        $liveItemIds = $this->items->rows($userId)->pluck('id')->map(fn ($id) => (string) $id)->all();
        if ($liveItemIds !== [] && DB::connection('pgsql')->table('content.manual_overrides')
            ->whereIn('item_id', $liveItemIds)->exists()) {
            return true;
        }

        return $this->composer->hasOwnerContent($menu);
    }

    /**
     * The dish count the integrations card reads.
     *
     * Slice 7 Phase 6: the legacy fallback went with site.menu_items, so there
     * is no gate left to keep in step — this and the rendered menu read the one
     * lane, which is what made them agree in the first place.
     */
    public function itemCount(User $user, ?Menu $menu): int
    {
        return $this->items->rows((string) $user->id)->count();
    }
}
