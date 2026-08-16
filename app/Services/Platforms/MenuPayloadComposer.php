<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\Site\MenuItemPlatform;
use App\Models\Core\Site\SectionItem;
use App\Models\Core\User\User;
use App\Services\Content\ManualMenuItems;
use App\Site\Pools\PoolRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Composes the authenticated dashboard menu payload (store fields + categories +
// computed order links) from a user's relational menu. Extracted from
// MenuController so the manual-content write endpoints (MenuContentController)
// return the EXACT same shape the dashboard reads after a write, without
// duplicating the composition. Read-only — never mutates the menu.
//
// Slice 7 Task 5: the DISHES now come from content.* via ManualMenuItems, which
// is MenuProjectionMapper run backwards. `site.menus` is NOT part of the
// teardown — it stays the per-menu bookkeeping row (last_fetched_at,
// dining_modes, suppressed_items, scan_items) — so load(), hasOwnerContent()
// and compose()'s store fields still read it.
//
// The legacy read is kept as a FALLBACK for an owner the content lane holds
// nothing for, live or removed. Phase 2 moves the four write paths one task at
// a time (Tasks 6-8), so between them a menu can exist only in site.menu_*, and
// a hard cutover here would blank those dashboards. Phase 5 deletes the
// fallback with the tables. The gate counts removed items on purpose: an owner
// who deleted their way down to an empty menu must not drop back to the legacy
// rows and watch every deleted dish reappear.
class MenuPayloadComposer
{
    public function __construct(
        private readonly MenuSource $source,
        private readonly ManualMenuItems $items,
    ) {}

    /**
     * The full dashboard menu payload for a user, resolving + orphan-guarding the
     * menu the same way GET /platforms/menu does. Use after a write to echo the
     * fresh menu the dashboard will render.
     *
     * @return array<string, mixed>
     */
    public function dashboardPayload(User $user): array
    {
        $menu = $this->load($user);

        // Orphan guard: a scraped menu with no backing Uber Eats / DoorDash link
        // AND no owner-authored (scan/manual) content is stale — hide it (refresh()
        // can't re-scrape it anyway). Owner content never depends on an ordering link.
        if ($this->source->resolveAll($user) === null && ! $this->hasOwnerContent($menu)) {
            $menu = null;
        }

        return $this->compose($user, $menu);
    }

    /** Eager-load a user's menu in the read shape (position order + platform links). */
    public function load(User $user): ?Menu
    {
        return Menu::query()
            ->where('user_id', $user->id)
            ->with([
                'categories' => fn ($q) => $q->orderBy('position'),
                // Items order by their per-membership pivot position (baked into
                // the MenuCategory::items() relation).
                'categories.items.platformLinks',
                // Menu-level store links — the base each item's deep link derives from.
                'platformLinks',
            ])
            ->first();
    }

    /**
     * Whether $menu carries owner-authored content — a scan/manual category OR any
     * is_manual dish (a scraped category kept alive purely by a manual dish). Such
     * a menu never depends on a live ordering link, so it's never orphaned.
     */
    public function hasOwnerContent(?Menu $menu): bool
    {
        if ($menu === null) {
            return false;
        }

        $legacy = $menu->relationLoaded('categories')
            ? $menu->categories->contains(function (MenuCategory $c) {
                return in_array($c->source_platform, ['scan', 'manual', 'website-scan'], true)
                    || ($c->relationLoaded('items') && $c->items->contains(fn (MenuItem $i) => $i->is_manual));
            })
            : MenuCategory::query()->where('menu_id', $menu->id)
                ->whereIn('source_platform', ['scan', 'manual', 'website-scan'])->exists()
                || MenuItem::query()->where('menu_id', $menu->id)->where('is_manual', true)->exists();

        // The content-lane half is asked on BOTH branches. load() eager-loads
        // `categories`, so a check that only hung off the unloaded branch would
        // never run for the dashboard read — which is the exact call that
        // decides whether a scan-only menu is shown at all.
        return $legacy || $this->hasOwnerContentInContentLane($menu);
    }

    /**
     * The content.* half of the same question, added by slice 7 Task 8 when the
     * scan lane stopped writing site.menu_categories: an owner-owned menu
     * category is now a content.collections row in the `menu:<source>:*`
     * external_ref namespace (MenuScanApplier::categoryRefFor).
     *
     * ORed with the legacy check rather than replacing it — the scrape still
     * writes site.menu_* until Task 7 lands, and a scan-only menu that read as
     * "orphaned" here would be hidden from its own dashboard entirely.
     */
    private function hasOwnerContentInContentLane(Menu $menu): bool
    {
        $userId = DB::connection('pgsql')->table('site.menus')->where('id', $menu->id)->value('user_id');

        return $userId !== null && $this->items->categories((string) $userId)
            ->contains(fn (object $c) => MenuScanApplier::isOwnerCategoryRef(
                $c->external_ref === null ? null : (string) $c->external_ref
            ));
    }

    /**
     * The full menu + computed order links, for an already-resolved menu (or null).
     * Mirrors GET /platforms/menu exactly.
     *
     * @return array<string, mixed>
     */
    public function compose(User $user, ?Menu $menu): array
    {
        return [
            'source' => $menu?->content_source,
            'storeName' => $menu?->store_name,
            'logo' => $menu?->logo_url,
            'rating' => $menu?->rating,
            'reviewCount' => $menu?->review_count,
            'currency' => $menu?->currency,
            'pickupPlatform' => $menu?->pickup_platform,
            'deliveryPlatform' => $menu?->delivery_platform,
            'fetchStatus' => $menu?->fetch_status,
            'diningModes' => $menu?->dining_modes,
            'categories' => $this->categories($user, $menu),
            'links' => $this->source->links($user),
        ];
    }

    /**
     * Categories → items shaped for the dashboard grid. Each category carries
     * its persisted id (PATCH/DELETE /menu/categories/{id}, and the id an item
     * write targets via category_id) and sourcePlatform (drives the "no longer
     * stay synced" warning on editing/deleting a scraped category). Each item
     * carries its representative base price, the aggregate pickup/delivery
     * prices (each tagged with the platform backing the min), the per-platform
     * availability list (platform + price + supported modes + order url), the
     * DoorDash rating + badges, and isManual (owner-authored — skips the
     * sync-detach warning).
     *
     * @return list<array{id:string, name:string, sourcePlatform:?string, items:list<array<string,mixed>>}>
     */
    private function categories(User $user, ?Menu $menu): array
    {
        if ($menu === null) {
            return [];
        }

        // slug => normalized store_url — base for each item's per-platform deep
        // link. Still site.menu_platform_links: the store link is menu-level
        // bookkeeping, not a dish, and only dishes moved in this task.
        $storeUrls = $menu->platformLinks->pluck('store_url', 'platform')->all();

        // includeRemoved is the fallback GATE, not a display choice — see the
        // class docblock. The live set is filtered back out immediately below.
        $rows = $this->items->rows((string) $user->id, includeRemoved: true);

        if ($rows->isEmpty()) {
            return $this->legacyCategories($menu, $storeUrls);
        }

        return $this->contentCategories(
            (string) $user->id,
            $rows->filter(fn (object $row) => $row->removed_at === null)->values(),
            $storeUrls,
        );
    }

    /**
     * Categories → items, read from content.*.
     *
     * Dish ORDER comes from the pool:menus PINS, not from the projection:
     * content.collection_items.position is the ordinal of the COLLECTION within
     * an item's collection list (ProjectionWriter::projectStream), not a dish's
     * rank within a category — ProvisionMenuPinsCommand's docblock says the
     * same and is why it seeded site.section_items.sort_key from the legacy
     * order in slice 4. See pinOrder() / sortByPins().
     *
     * Four legacy signals have no projection target and come back honest rather
     * than guessed. `isManual`, `pickupSource` and `deliverySource` are
     * ManualMenuItems' own documented nulls; the one this method owns is
     * `sourcePlatform` — content.collections carries no source column, so the
     * dashboard's sync-detach warning has nothing to key off yet (Task 6).
     *
     * `links` follows from ManualMenuItems' null dd_external_id: the mapper
     * never carried the DoorDash item id, so MenuItemDeepLinks has nothing to
     * build from and every dish's deep link is null here. 31 of dev's 318
     * dishes hold one — recovering it is a MenuProjectionMapper addition
     * (f_catalog.sku / variant_ref are already projection-supported) plus a
     * `content:backfill-menus` re-run, both outside this task.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @param  array<string, string|null>  $storeUrls
     * @return list<array{id:string, name:string, sourcePlatform:?string, items:list<array<string,mixed>>}>
     */
    private function contentCategories(string $userId, Collection $rows, array $storeUrls): array
    {
        $models = [];
        $itemIdsByCategory = [];
        foreach ($rows as $row) {
            $itemId = (string) $row->id;
            $models[$itemId] = $this->items->toMenuItemModel($row);
            foreach ($row->category_ids as $categoryId) {
                $itemIdsByCategory[(string) $categoryId][] = $itemId;
            }
        }

        $categories = $this->items->categories($userId);

        // Built by walking the categories in menu order, exactly as the legacy
        // path does — a dish's categoryIds must read in the order its
        // categories appear, not in membership-insert order.
        $categoryIdsByItem = [];
        foreach ($categories as $category) {
            foreach ($itemIdsByCategory[(string) $category->id] ?? [] as $itemId) {
                $categoryIdsByItem[$itemId][] = (string) $category->id;
            }
        }

        $pins = $this->pinOrder($userId);

        $out = [];
        foreach ($categories as $category) {
            $categoryId = (string) $category->id;
            $out[] = [
                'id' => $categoryId,
                'name' => (string) $category->label,
                'sourcePlatform' => null,
                'items' => array_map(
                    fn (string $itemId) => $this->item(
                        $models[$itemId],
                        $categoryIdsByItem[$itemId] ?? [$categoryId],
                        $storeUrls,
                    ),
                    $this->sortByPins($itemIdsByCategory[$categoryId] ?? [], $pins),
                ),
            ];
        }

        return $out;
    }

    /**
     * item id => sort_key on this owner's `pool:menus` section — the owner's
     * arrangement, which ProvisionMenuPinsCommand seeded in slice 4 from
     * site.menu_categories.position + site.menu_item_categories.position, and
     * which a drag on the pool page has written since. That command exists
     * BECAUSE content.collection_items.position cannot answer this question,
     * so reading the pins is what carries the vendor's menu order across the
     * teardown rather than losing it to an alphabetical fallback.
     *
     * Scoped through site.sites.user_id, not by section key alone: sort_key is
     * dense 1-based PER SECTION, so the same value means a different rank on
     * every site and is only comparable within one.
     *
     * A plain read — never provisioner->ensure(), which INSERTs a page and a
     * section. Composing a payload must not create rows.
     *
     * @return array<string, float>
     */
    private function pinOrder(string $userId): array
    {
        return DB::connection('pgsql')->table('site.section_items as si')
            ->join('site.sections as s', 's.id', '=', 'si.section_id')
            ->join('site.sites as st', 'st.id', '=', 's.site_id')
            ->where('st.user_id', $userId)
            ->where('s.key', PoolRegistry::sectionKey('menus'))
            ->where('si.state', SectionItem::STATE_PINNED)
            ->whereNotNull('si.sort_key')
            ->pluck('si.sort_key', 'si.item_id')
            ->map(fn ($sortKey) => (float) $sortKey)
            ->all();
    }

    /**
     * The owner's arrangement applied to one category's dishes. Pinned first in
     * sort_key order, then anything unpinned in the order it arrived — the same
     * pins-then-the-rest concatenation PoolResolver::resolve() performs, so the
     * dashboard and the public pool agree about what "the owner's order" means.
     *
     * $itemIds arrives in ManualMenuItems::rows()' own order (alphabetical by
     * headline), which is what an unpinned dish falls back to.
     *
     * @param  list<string>  $itemIds
     * @param  array<string, float>  $pins
     * @return list<string>
     */
    private function sortByPins(array $itemIds, array $pins): array
    {
        $ranked = [];
        foreach ($itemIds as $index => $itemId) {
            $ranked[] = [isset($pins[$itemId]) ? 0 : 1, $pins[$itemId] ?? 0.0, $index, $itemId];
        }

        usort($ranked, fn (array $a, array $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return array_column($ranked, 3);
    }

    /**
     * The pre-cutover read, kept for an owner with no content-lane menu at all.
     * Deleted in Phase 5 along with site.menu_categories / menu_items.
     *
     * @param  array<string, string|null>  $storeUrls
     * @return list<array{id:string, name:string, sourcePlatform:?string, items:list<array<string,mixed>>}>
     */
    private function legacyCategories(Menu $menu, array $storeUrls): array
    {
        // item id => every category id it's a member of. A dish in several
        // categories appears under EACH of them below (sharing its id) — this
        // map lets the dashboard's flat items view dedupe by id and render/edit
        // the full membership set (category_ids on the write side).
        $categoryIdsByItem = [];
        foreach ($menu->categories as $category) {
            foreach ($category->items as $item) {
                $categoryIdsByItem[(string) $item->id][] = (string) $category->id;
            }
        }

        return $menu->categories->map(fn (MenuCategory $category) => [
            // Stable persisted id — addresses PATCH/DELETE /menu/categories/{id}
            // and is the value an item write sends as category_id.
            'id' => (string) $category->id,
            'name' => $category->name,
            // Drives the dashboard's "this will no longer stay synced" warning —
            // only 'manual'/'scan' categories are owner-editable (MenuContentController::EDITABLE_SOURCES).
            'sourcePlatform' => $category->source_platform,
            'items' => $category->items->map(fn (MenuItem $item) => $this->item(
                $item,
                $categoryIdsByItem[(string) $item->id] ?? [(string) $category->id],
                $storeUrls,
            ))->all(),
        ])->all();
    }

    /**
     * One dish, shaped for the dashboard grid. Shared by both reads on purpose:
     * the cutover's whole contract is that the payload does not change, and one
     * shape function is what makes that true by construction rather than by two
     * lists someone has to keep in step.
     *
     * @param  list<string>  $categoryIds
     * @param  array<string, string|null>  $storeUrls
     * @return array<string, mixed>
     */
    private function item(MenuItem $item, array $categoryIds, array $storeUrls): array
    {
        return [
            // Stable persisted id — mirrors PublicMenuController's `id` field.
            // Partna-Frontend's menu-item-detail URLs read THIS endpoint's id.
            'id' => (string) $item->id,
            'name' => $item->name,
            'description' => $item->description,
            'image' => $item->image_url,
            // Full image set, hero first — mirrors PublicMenuController's `images`.
            'images' => $item->images,
            'rating' => $item->rating,
            'ratingCount' => $item->rating_count,
            'badges' => $item->badges,
            'basePrice' => $item->base_price,
            'pickupPrice' => $item->pickup_price,
            'pickupSource' => $item->pickup_source,
            'deliveryPrice' => $item->delivery_price,
            'deliverySource' => $item->delivery_source,
            'currency' => $item->currency,
            // Owner-authored marker — skips the sync-detach warning (an
            // already-manual item has nothing left to detach from).
            'isManual' => $item->is_manual,
            // Every category this dish belongs to — lets the flat view dedupe
            // (same id under several categories) and drives the multi-select
            // category editor + category filter. Includes THIS category.
            'categoryIds' => $categoryIds,
            'platforms' => $this->platforms($item),
            // Per-item deep links ({doordash?: url}) — mirrors PublicMenuController
            // exactly; null when nothing item-level is derivable (see MenuItemDeepLinks).
            'links' => MenuItemDeepLinks::forItem($item->dd_external_id, $storeUrls) ?: null,
        ];
    }

    /**
     * The item's per-platform availability list — one entry per ordering platform
     * the dish is on, each with its pickup price + url and delivery price + url (a
     * mode the store doesn't offer is null on both). Empty when the dish has no
     * platform rows.
     *
     * @return list<array{platform:string, pickupPrice:float|null, pickupUrl:string|null, deliveryPrice:float|null, deliveryUrl:string|null}>
     */
    private function platforms(MenuItem $item): array
    {
        return $item->platformLinks->map(fn (MenuItemPlatform $p) => [
            'platform' => $p->platform,
            'pickupPrice' => $this->numberOrNull($p->pickup_price),
            'pickupUrl' => $this->textOrNull($p->pickup_url),
            'deliveryPrice' => $this->numberOrNull($p->delivery_price),
            'deliveryUrl' => $this->textOrNull($p->delivery_url),
        ])->values()->all();
    }

    private function numberOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function textOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
