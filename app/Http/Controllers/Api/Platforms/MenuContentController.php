<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\CreateMenuCategoryRequest;
use App\Http\Requests\Platforms\CreateMenuItemRequest;
use App\Http\Requests\Platforms\UpdateMenuCategoryRequest;
use App\Http\Requests\Platforms\UpdateMenuItemRequest;
use App\Models\Content\ManualOverride;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\SectionItem;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Cache\SiteCacheInvalidator;
use App\Services\Content\ManualMenuItems;
use App\Services\Content\ManualMenuWriter;
use App\Services\Content\MenuCollections;
use App\Services\Platforms\CleansScrapedStrings;
use App\Services\Platforms\MenuDashboardPayload;
use App\Services\Platforms\MenuProjectionMapper;
use App\Services\Platforms\NormalizesMenuItemNames;
use App\Site\Documents\SiteCacheLanes;
use App\Site\Pools\PoolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// Owner-authored (manual) menu management — the user editing their own menu by
// hand, independent of the Uber Eats / DoorDash scrape (MenuFetchJob) and the
// photo/PDF scan (MenuScanApplier).
//
// Slice 7 Task 6: every write here now lands in `content.*` through
// ManualMenuWriter (dishes) and MenuCollections (categories). `site.menus`
// SURVIVES the teardown and is still the per-menu bookkeeping row — currency,
// last_fetched_at, dining_modes, scan_items, suppressed_items — so it is still
// resolved and written here; `site.menu_categories`, `site.menu_items`,
// `site.menu_item_categories` and `site.menu_item_platforms` are not touched by
// any verb in this file any more.
//
// ── The four behaviour homes (Task 6 Step 1) ─────────────────────────────────
//
// 1. `is_manual` — "the owner edited this dish, so the scrape must not clobber
//    it" — lives in `content.manual_overrides`, one row per column the owner
//    authored. NOT on `site.menus`, which the spec claimed and which has no such
//    column (verified: scan_items, suppressed_items, dining_modes,
//    last_fetched_at, …). This is not a new mechanism: the table's own DDL
//    comment says it "is what replaces is_manual flags", and slice 3b already
//    derives Fresha's is_manual from exactly this
//    (UserServiceController::freshaServiceModel). Every write verb here records
//    an `f_text`/`headline` override (plus `f_text`/`body` when the description
//    was sent), so `hasOwnerEdits()` — an EXISTS against that table — is the
//    content-lane `is_manual`. Freezing the headline on a price-only edit is
//    deliberate and strictly weaker than the legacy flag, which froze the whole
//    dish. `ManualMenuItems::toMenuItemModel()` still reports `is_manual` as a
//    flat false; teaching the read side to join this table is its own change and
//    is NOT owned by this task (the dashboard's sync-detach chip is the only
//    reader, and it fails safe by warning too often, never too little).
//
// 2. `menus.suppressed_items` — "a scraped dish the owner deleted stays dead" —
//    is UNCHANGED. The column is on `site.menus`, which survives, and the key
//    format (normalizeName(category)|normalizeName(name)) is what
//    MenuFetchJob::suppressedItemKeys() matches on. This is the one signal that
//    still couples the two lanes across Phase 2, and it is why deleteItem()
//    still resolves a Menu model.
//
// 3. `menu_categories.source_platform` — "who owns this category" — is
//    `content.collections.is_user_created`. `content.collections` carries no
//    source column and this phase writes no migrations, so the owner-side
//    values ('manual', 'scan', 'website-scan') collapse to `true` and the
//    scraper-owned half ('uber-eats', 'doordash', NULL) to `false`. Every reader
//    of the old column asked exactly that yes/no question. The specific string
//    is a recorded loss, already taken in Task 5 (MenuPayloadComposer emits
//    `sourcePlatform: null` for content-lane categories). Safe against a
//    rebuild: ProjectionWriter::upsertCollections() never writes
//    `is_user_created` on its UPDATE arm, so a scrape re-listing an owner's
//    category cannot take ownership back.
//
// 4. `EDITABLE_SOURCES` — the const is unchanged and is applied against (3) via
//    categorySource(): an owner-created collection reports 'manual', a
//    scraper-owned one reports null. Renaming/deleting a synced category still
//    422s with the same two messages.
//
// Dish ORDER is a PIN, not a position rewrite: `content.collections.position` is
// an insert-only seed and `content.collection_items.position` is
// ProjectionWriter's per-item counter, not a display rank. The owner's
// arrangement is `site.section_items.sort_key` on the `pool:menus` section,
// written through ManualMenuWriter::pin() and read by
// MenuPayloadComposer::pinOrder() — the same contract UserServiceController
// uses for services.
//
// Every write is capability-gated (can_use_menu, a food-business feature) and
// resolves the target strictly through the caller's own content (user-scoped
// queries → 404 for anything not theirs — the same enforcement MenuController
// uses, and why the child models stay policy-exempt). Each write busts all three
// cache lanes and returns the fresh full menu the dashboard reads.
class MenuContentController extends ApiController
{
    use CleansScrapedStrings, NormalizesMenuItemNames, ResolveCurrentUser;

    private const DEFAULT_CATEGORY_NAME = 'Menu';

    // Category sources the owner is allowed to rename/delete — everything else
    // ('uber-eats'/'doordash'/NULL) is scraper-owned and off-limits to hand
    // edits. Applied against categorySource(), see home (3) above.
    private const EDITABLE_SOURCES = ['manual', 'scan', 'website-scan'];

    public function __construct(
        private readonly MenuDashboardPayload $payload,
        private readonly SiteCacheInvalidator $invalidator,
        private readonly ManualMenuItems $items,
        private readonly ManualMenuWriter $writer,
        private readonly MenuCollections $collections,
    ) {}

    // POST /api/platforms/menu/categories — add a manual category.
    public function createCategory(CreateMenuCategoryRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        if ($denied = $this->denyWithoutCapability($user)) {
            return $denied;
        }

        $name = trim($request->validated()['name']);

        // Duplicate check is on the NATURAL KEY, not on a lowercased label:
        // collections_user_kind_external_ref_uq is what the insert would
        // actually violate, so anything that would 23505 must 422 here first.
        // Marginally broader than the old case-insensitive compare (Str::slug
        // also folds punctuation), which is the correct direction.
        $existing = $this->collections->findByLabel((string) $user->id, $name);
        if ($existing !== null && $existing->removed_at === null) {
            return $this->error('A category with this name already exists.', 422);
        }

        // site.menus survives as the bookkeeping row and MenuPayloadComposer
        // still gates the whole payload on it, so a category with no menu row
        // would be invisible.
        $this->resolveMenu($user);
        $this->collections->ensure((string) $user->id, $name, ownerCreated: true);

        return $this->touchAndRespond($user, 'menu-manual-category-create');
    }

    // PATCH /api/platforms/menu/categories/{category} — rename a manual/scan category.
    public function updateCategory(UpdateMenuCategoryRequest $request, string $category): JsonResponse
    {
        $user = $this->currentUser($request);
        if ($denied = $this->denyWithoutCapability($user)) {
            return $denied;
        }

        $model = $this->collections->find((string) $user->id, $category);
        if ($model === null) {
            return $this->error('Not found.', 404);
        }
        if (! $this->isEditable($model)) {
            return $this->error("Synced categories can't be renamed.", 422);
        }

        $name = trim($request->validated()['name']);

        // NEW next to the legacy branch, and forced by the schema: the rename
        // re-derives external_ref, so renaming onto a name another row already
        // holds (live OR tombstoned) is a unique violation. 422 with create()'s
        // own message rather than a 500.
        $clash = $this->collections->findByLabel((string) $user->id, $name);
        if ($clash !== null && (string) $clash->id !== (string) $model->id) {
            return $this->error('A category with this name already exists.', 422);
        }

        $this->collections->rename((string) $user->id, (string) $model->id, $name);

        return $this->touchAndRespond($user, 'menu-manual-category-rename');
    }

    // POST /api/platforms/menu/categories/reorder — persist the user's category
    // order. `ids` is the full desired order; categories it omits keep their
    // relative order after the listed ones. Ordering isn't a content edit, so
    // scraped categories may be reordered too — only their names are synced.
    public function reorderCategories(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        if ($denied = $this->denyWithoutCapability($user)) {
            return $denied;
        }

        $ids = $request->validate([
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['string', 'uuid'],
        ])['ids'];

        if (Menu::query()->where('user_id', $user->id)->doesntExist()) {
            return $this->error('Not found.', 404);
        }

        $owned = $this->collections->list((string) $user->id)->keyBy(fn (\stdClass $row) => (string) $row->id);
        foreach ($ids as $id) {
            if (! $owned->has($id)) {
                return $this->error('Category not found.', 404);
            }
        }

        // A reorder writes content.collections.position — the one column a
        // SCHEDULED run must never write back (ProjectionWriter::upsertCollections
        // keeps position INSERT-only for exactly this reason).
        $this->collections->reposition((string) $user->id, array_values($ids));

        return $this->touchAndRespond($user, 'menu-categories-reorder');
    }

    // POST /api/platforms/menu/items/reorder — persist the dish order WITHIN one
    // category. `ids` is the full desired order for that category; members it
    // omits keep their relative order after the listed ones.
    public function reorderItems(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        if ($denied = $this->denyWithoutCapability($user)) {
            return $denied;
        }

        $data = $request->validate([
            'category_id' => ['required', 'string', 'uuid'],
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['string', 'uuid'],
        ]);

        $category = $this->collections->find((string) $user->id, $data['category_id']);
        $site = $this->currentSite($user);
        if ($category === null || $site === null) {
            return $this->error('Not found.', 404);
        }

        // The category's dishes in the order the dashboard currently renders
        // them: ManualMenuItems::rows()' own order, re-sorted by the pins —
        // byte-for-byte MenuPayloadComposer::sortByPins()'s rule, so "the ids
        // this request omitted keep their relative order" means the order the
        // caller was actually looking at.
        $sectionId = $this->menuSectionId($site);
        $pins = $this->pinOrder($sectionId);
        $members = $this->sortByPins(
            $this->items->rows((string) $user->id)
                ->filter(fn (object $row) => in_array((string) $category->id, array_map('strval', $row->category_ids), true))
                ->map(fn (object $row) => (string) $row->id)
                ->values()
                ->all(),
            $pins,
        );

        $memberSet = array_flip($members);
        foreach ($data['ids'] as $id) {
            if (! array_key_exists($id, $memberSet)) {
                return $this->error('Item not found in this category.', 404);
            }
        }

        $desired = array_merge(
            array_values($data['ids']),
            array_values(array_diff($members, $data['ids'])),
        );

        // Reorder WITHIN the category by permuting the sort_keys this
        // category's dishes already occupy — never by renumbering the section.
        // sort_key is one global scale per site (the `pool:menus` section), so
        // a dense 0..n-1 rewrite here would reshuffle every OTHER category's
        // dishes against these ones.
        $slots = [];
        foreach ($members as $itemId) {
            if (isset($pins[$itemId])) {
                $slots[] = $pins[$itemId];
            }
        }
        sort($slots);
        $next = $this->nextSortKey($sectionId);
        while (count($slots) < count($members)) {
            $slots[] = $next++;
        }

        DB::connection('pgsql')->transaction(function () use ($site, $desired, $slots): void {
            foreach ($desired as $index => $itemId) {
                $this->writer->pin($site, $itemId, (float) $slots[$index]);
            }
        });

        return $this->touchAndRespond($user, 'menu-items-reorder');
    }

    // DELETE /api/platforms/menu/categories/{category} — delete a manual/scan
    // category. Member dishes are DETACHED; a dish left with no remaining
    // category goes with it (suppressed by name when it wasn't owner-authored,
    // so neither the scrape rebuild nor the automatic scan reapply resurrects it).
    public function deleteCategory(Request $request, string $category): JsonResponse
    {
        $user = $this->currentUser($request);
        if ($denied = $this->denyWithoutCapability($user)) {
            return $denied;
        }

        $menu = Menu::query()->where('user_id', $user->id)->first();
        $model = $this->collections->find((string) $user->id, $category);
        if ($menu === null || $model === null) {
            return $this->error('Not found.', 404);
        }
        if (! $this->isEditable($model)) {
            return $this->error("Synced categories can't be deleted.", 422);
        }

        $memberIds = $this->collections->memberIds((string) $user->id, (string) $model->id);
        $names = $this->items->rows((string) $user->id)
            ->mapWithKeys(fn (object $row) => [(string) $row->id => (string) $row->headline]);

        DB::connection('pgsql')->transaction(function () use ($user, $menu, $model, $memberIds, $names): void {
            $this->collections->remove((string) $user->id, (string) $model->id);

            // Members with no remaining LIVE membership anywhere are orphans —
            // remove them (a dish always belongs to ≥1 category), suppressing
            // the ones the owner never authored so the next scrape does not
            // hand them back.
            $counts = $this->collections->liveCategoryCounts((string) $user->id, $memberIds);
            foreach ($memberIds as $itemId) {
                if (($counts[$itemId] ?? 0) > 0) {
                    continue;
                }
                if (! $this->hasOwnerEdits($itemId)) {
                    $this->suppressItem($menu, (string) $model->label, (string) ($names[$itemId] ?? ''));
                }
                // markRemoved(), never a hard delete: MenuPayloadComposer's
                // fallback fires when the content lane holds NOTHING for the
                // owner (removed rows included), so deleting the row would let
                // an owner who deleted their way to an empty menu drop back to
                // the legacy tables and watch every deleted dish reappear. It
                // also frees the dish's content.item_slugs row via SLUGGED_KINDS.
                $this->writer->markRemoved($itemId);
            }
        });

        return $this->touchAndRespond($user, 'menu-manual-category-delete');
    }

    // POST /api/platforms/menu/items — add a manual dish.
    public function createItem(CreateMenuItemRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        if ($denied = $this->denyWithoutCapability($user)) {
            return $denied;
        }

        $data = $request->validated();

        // Resolve the image first — a 404 here must not have created a menu.
        $image = ['image_url' => null, 'images' => null];
        if (! empty($data['image_media_id'])) {
            $media = $this->ownedMedia($user, $data['image_media_id']);
            if ($media === null) {
                return $this->error('Image not found.', 404);
            }
            $image = $this->imageFromMedia($media);
        }

        // Supplied categories (category_ids, or the legacy single category_id)
        // must belong to the caller (any source); when omitted, find-or-create
        // the owner-created default 'Menu' category.
        $categoryIds = $this->requestedCategoryIds($data);
        if ($categoryIds !== []) {
            $menu = Menu::query()->where('user_id', $user->id)->first();
            $categories = $menu !== null ? $this->ownedCategories($user, $categoryIds) : null;
            if ($categories === null) {
                return $this->error('Category not found.', 404);
            }
            // Freshen last_fetched_at like resolveMenu / MenuScanApplier — menu
            // visibility still gates on it.
            $menu->forceFill(['last_fetched_at' => now()])->save();
        } else {
            $menu = $this->resolveMenu($user);
            $categories = collect([$this->defaultCategory($user)]);
        }

        $name = trim($data['name']);
        $description = $this->cleanString($data['description'] ?? null);
        $dish = (object) [
            'name' => $name,
            'description' => $description,
            'base_price' => $data['price'] ?? null,
            'pickup_price' => null,
            'delivery_price' => null,
            'currency' => null,
            'image_url' => $image['image_url'],
            'images' => $image['images'],
            'rating' => null,
            'rating_count' => null,
            'badges' => null,
        ];

        // The slice-4 coord: menu-scoped and NAME-derived, never re-derived here
        // (MenuProjectionMapper's own docblock). A create that collides with an
        // existing dish's normalised name therefore UPDATES that dish rather
        // than minting a second — the identity rule the whole lane is built on.
        $coord = $this->writer->coordFor((string) $menu->id, $name);
        $itemId = $this->writer->write(
            (string) $user->id,
            $coord,
            $this->writer->projectionFor($dish, $this->categoryEntries($categories), [], $menu),
        );

        // Re-adding a dish the owner previously deleted is explicit consent to
        // bring it back (items.removed_at is one-way against a SCRAPE, not
        // against its owner) — the same door ManualPoolWriter::clearRemoved()
        // opens for the services restore endpoint.
        if ($this->isRemoved($itemId)) {
            $this->writer->clearRemoved($itemId);
        }

        $this->syncDescription((string) $user->id, $itemId, $description);
        $this->recordOwnerEdits($itemId, $name, true, $description);
        $this->appendPin($user, $itemId);

        return $this->touchAndRespond($user, 'menu-manual-item-create');
    }

    // PATCH /api/platforms/menu/items/{item} — edit any dish. Editing a scraped
    // dish detaches it from platform sync (it gains manual_overrides rows).
    public function updateItem(UpdateMenuItemRequest $request, string $item): JsonResponse
    {
        $user = $this->currentUser($request);
        if ($denied = $this->denyWithoutCapability($user)) {
            return $denied;
        }

        $row = $this->items->find((string) $user->id, $item);
        $menu = Menu::query()->where('user_id', $user->id)->first();
        if ($row === null || $menu === null) {
            return $this->error('Not found.', 404);
        }

        $data = $request->validated();

        $name = array_key_exists('name', $data) ? trim($data['name']) : (string) $row->headline;
        $descriptionSent = array_key_exists('description', $data);
        $description = $descriptionSent ? $this->cleanString($data['description']) : $row->description;

        // Category memberships: category_ids (or the legacy single category_id)
        // REPLACES the dish's category set. A null/omitted value is "no change" —
        // a dish is never orphaned out of its categories (min 1 enforced by the
        // request rules; an empty resolve is treated as no-change too).
        $categoryIds = $this->requestedCategoryIds($data);
        if ($categoryIds !== []) {
            $categories = $this->ownedCategories($user, $categoryIds);
            if ($categories === null) {
                return $this->error('Category not found.', 404);
            }
        } else {
            $categories = $this->currentCategories($user, $row);
        }

        // Image: remove_image wins; otherwise a supplied image_media_id sets it.
        $imageUrl = $row->image_url;
        $images = $row->images;
        if (! empty($data['remove_image'])) {
            $imageUrl = null;
            $images = null;
        } elseif (! empty($data['image_media_id'])) {
            $media = $this->ownedMedia($user, $data['image_media_id']);
            if ($media === null) {
                return $this->error('Image not found.', 404);
            }
            $image = $this->imageFromMedia($media);
            $imageUrl = $image['image_url'];
            $images = $image['images'];
        }

        $dish = (object) [
            'name' => $name,
            'description' => $description,
            'base_price' => array_key_exists('price', $data) ? $data['price'] : $row->base_price,
            // Carried forward, not re-derived: the projection REPLACES a dish's
            // offers, media, tags and collections wholesale per source, so any
            // scraped value this PATCH does not mention has to travel back
            // through the mapper or it is silently dropped.
            'pickup_price' => $row->pickup_price,
            'delivery_price' => $row->delivery_price,
            'currency' => $row->currency,
            'image_url' => $imageUrl,
            'images' => $images,
            'rating' => $row->rating,
            'rating_count' => $row->rating_count,
            'badges' => $row->badges,
        ];

        // The dish's EXISTING coord, not coordFor($menu, $name) — a rename must
        // update this item in place rather than land a second one under the new
        // name's hash (UserServiceController::update() re-uses the stored coord
        // for the same reason).
        $itemId = $this->writer->write(
            (string) $user->id,
            (string) $row->coord,
            $this->writer->projectionFor($dish, $this->categoryEntries($categories), $this->platformRows($row), $menu),
        );

        $this->syncDescription((string) $user->id, $itemId, $description);
        $this->recordOwnerEdits($itemId, $name, $descriptionSent, $description);

        return $this->touchAndRespond($user, 'menu-manual-item-update');
    }

    // DELETE /api/platforms/menu/items/{item} — remove a dish (from EVERY
    // category it's listed under). A scraped dish is also suppressed so the
    // next scrape doesn't resurrect it.
    public function deleteItem(Request $request, string $item): JsonResponse
    {
        $user = $this->currentUser($request);
        if ($denied = $this->denyWithoutCapability($user)) {
            return $denied;
        }

        $menu = Menu::query()->where('user_id', $user->id)->first();
        if ($menu === null) {
            return $this->error('Not found.', 404);
        }

        $row = $this->items->find((string) $user->id, $item);
        if ($row === null) {
            return $this->error('Not found.', 404);
        }

        DB::connection('pgsql')->transaction(function () use ($user, $menu, $row): void {
            $this->destroyItem($user, $menu, $row);
        });

        return $this->touchAndRespond($user, 'menu-manual-item-delete');
    }

    // POST /api/platforms/menu/items/bulk-delete — remove a batch of dishes in
    // one transaction (the categories table's bulk actions). Ids that don't
    // resolve to the caller's own dishes are skipped, not errors — the table's
    // selection can go stale between render and submit.
    public function bulkDeleteItems(Request $request): JsonResponse
    {
        $user = $this->currentUser($request);
        if ($denied = $this->denyWithoutCapability($user)) {
            return $denied;
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['uuid'],
        ]);

        $menu = Menu::query()->where('user_id', $user->id)->first();
        if ($menu === null) {
            return $this->error('Not found.', 404);
        }

        $wanted = array_flip(array_unique($validated['ids']));
        $rows = $this->items->rows((string) $user->id)
            ->filter(fn (object $row) => array_key_exists((string) $row->id, $wanted))
            ->values();

        DB::connection('pgsql')->transaction(function () use ($user, $menu, $rows): void {
            foreach ($rows as $row) {
                $this->destroyItem($user, $menu, $row);
            }
        });

        return $this->touchAndRespond($user, 'menu-manual-item-bulk-delete');
    }

    /* ------------------------------------------------------------------ */
    /*  Resolution + guards */
    /* ------------------------------------------------------------------ */

    /** 403 when the account can't use the menu feature, else null (food-business gate). */
    private function denyWithoutCapability(User $user): ?JsonResponse
    {
        if (! AccountCapabilities::for($user)->can_use_menu) {
            return $this->error('Menu is not available for your account.', 403);
        }

        return null;
    }

    /**
     * Remove one dish inside an open transaction: suppress it first when it is
     * scraped (no owner edits, holding a scraper-owned membership) so neither
     * the scrape rebuild nor the scan reapply resurrects it, then mark the
     * content item removed.
     */
    private function destroyItem(User $user, Menu $menu, object $row): void
    {
        if (! $this->hasOwnerEdits((string) $row->id)) {
            $scraped = $this->currentCategories($user, $row)
                ->first(fn (\stdClass $category) => ! $this->isEditable($category));
            $this->suppressItem($menu, (string) ($scraped->label ?? ''), (string) $row->headline);
        }

        $this->writer->markRemoved((string) $row->id);
    }

    /**
     * The ids a write addressed, from category_ids (multi) or the legacy
     * single category_id — deduped, [] when neither was supplied.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function requestedCategoryIds(array $data): array
    {
        $ids = $data['category_ids'] ?? null;
        if (! is_array($ids) || $ids === []) {
            $ids = empty($data['category_id']) ? [] : [$data['category_id']];
        }

        return array_values(array_unique(array_map('strval', $ids)));
    }

    /**
     * The caller's own categories for a set of requested ids — null when ANY id
     * doesn't resolve (all-or-nothing, so a write can't silently drop a
     * membership it couldn't see).
     *
     * @param  list<string>  $categoryIds
     * @return Collection<int, \stdClass>|null
     */
    private function ownedCategories(User $user, array $categoryIds): ?Collection
    {
        $owned = $this->collections->list((string) $user->id)
            ->keyBy(fn (\stdClass $row) => (string) $row->id);

        $out = collect();
        foreach ($categoryIds as $id) {
            if (! $owned->has($id)) {
                return null;
            }
            $out->push($owned->get($id));
        }

        return $out;
    }

    /**
     * A dish's CURRENT live categories, in the owner's category order.
     *
     * @return Collection<int, \stdClass>
     */
    private function currentCategories(User $user, object $row): Collection
    {
        $ids = array_flip(array_map('strval', $row->category_ids));

        return $this->collections->list((string) $user->id)
            ->filter(fn (\stdClass $category) => array_key_exists((string) $category->id, $ids))
            ->values();
    }

    /**
     * Categories in the shape MenuProjectionMapper::project() consumes. `name`
     * and `position` are the only keys it reads; `id` is carried for symmetry
     * with the legacy call sites.
     *
     * @param  Collection<int, \stdClass>  $categories
     * @return list<array{id: string, name: string, position: int}>
     */
    private function categoryEntries(Collection $categories): array
    {
        return $categories->map(fn (\stdClass $category) => [
            'id' => (string) $category->id,
            'name' => (string) $category->label,
            'position' => (int) $category->position,
            // Pass the STORED key, never let the mapper re-derive it from the
            // label: owner categories live in their own external_ref namespace
            // (MenuScanApplier::categoryRefFor), so a re-derivation would mint a
            // second, scraper-shaped collection beside this one.
            'external_ref' => $category->external_ref ?? null,
        ])->values()->all();
    }

    /**
     * The dish's ordering-platform rows, rebuilt from the folded read so
     * projectionFor() re-emits both the per-platform offers and the
     * `order_platform` collection memberships an edit must not drop.
     *
     * @return list<object>
     */
    private function platformRows(object $row): array
    {
        return collect($row->platforms)->values()->all();
    }

    /**
     * The user's menu row, creating a manual-sourced one when none exists yet
     * (mirrors MenuScanApplier::resolveMenu). site.menus is NOT part of the
     * teardown — it stays the per-menu bookkeeping row, and last_fetched_at is
     * stamped on every resolve because menu visibility still gates on it.
     */
    private function resolveMenu(User $user): Menu
    {
        $menu = Menu::query()->where('user_id', $user->id)->first();
        if ($menu !== null) {
            $menu->forceFill(['last_fetched_at' => now()])->save();

            return $menu;
        }

        return Menu::create([
            'user_id' => $user->id,
            'content_source' => 'manual',
            'currency' => 'AUD',
            'fetch_status' => 'ok',
            'last_fetched_at' => now(),
        ]);
    }

    /** The owner's default 'Menu' category, creating it when absent (bucket for uncategorised dishes). */
    private function defaultCategory(User $user): \stdClass
    {
        $existing = $this->collections->findByLabel((string) $user->id, self::DEFAULT_CATEGORY_NAME);
        if ($existing !== null && $existing->removed_at === null && $existing->is_user_created) {
            return $existing;
        }

        $id = $this->collections->ensure((string) $user->id, self::DEFAULT_CATEGORY_NAME, ownerCreated: true);

        return $this->collections->find((string) $user->id, $id)
            ?? (object) ['id' => $id, 'label' => self::DEFAULT_CATEGORY_NAME, 'position' => 0, 'is_user_created' => true, 'removed_at' => null, 'external_ref' => null];
    }

    /** A caller-owned SiteMedia by id, resolved through their site (null = not theirs). */
    private function ownedMedia(User $user, string $mediaId): ?SiteMedia
    {
        $site = $user->loadMissing('site')->site;
        if ($site === null) {
            return null;
        }

        return SiteMedia::query()->where('id', $mediaId)->where('site_id', $site->id)->first();
    }

    private function currentSite(User $user): ?Site
    {
        return $user->loadMissing('site')->site;
    }

    /* ------------------------------------------------------------------ */
    /*  Owner-edit marker (behaviour home 1) */
    /* ------------------------------------------------------------------ */

    /**
     * Record the columns this write authored as `content.manual_overrides`
     * rows — the content-lane `is_manual`.
     *
     * The headline override is written on EVERY owner write, including a
     * price-only PATCH: the marker has to exist for a dish the owner touched at
     * all, and the honest column to freeze is the one whose value the owner is
     * now responsible for. `body` joins it only when the request actually sent
     * a description, so an untouched description keeps following the vendor.
     *
     * "On every write" is LOAD-BEARING, not tidiness. MenuFetchJob's whole-dish
     * lock (ownerLockedCoords) reads these rows to decide which dishes a scrape
     * must not touch, and price has no override column of its own —
     * content.offers is a set, which FacetRegistry admits no override for. So
     * this headline row is the ONLY thing standing between an owner's re-priced
     * dish and the vendor's price on the next scrape. Make it conditional on a
     * name being sent and price edits silently revert again;
     * ManualMenuContentTest's price-only case is the guard.
     */
    private function recordOwnerEdits(string $itemId, string $name, bool $descriptionSent, ?string $description): void
    {
        $this->putOverride($itemId, 'f_text', 'headline', $name);

        if ($descriptionSent) {
            $this->putOverride($itemId, 'f_text', 'body', $description);
        }
    }

    private function putOverride(string $itemId, string $facet, string $column, mixed $value): void
    {
        $override = ManualOverride::query()
            ->where('item_id', $itemId)
            ->where('facet', $facet)
            ->where('column_name', $column)
            ->first() ?? new ManualOverride;

        $override->item_id = $itemId;
        $override->facet = $facet;
        $override->column_name = $column;
        $override->value = $value;
        $override->save();
    }

    /** The content-lane `is_manual`: the owner has authored at least one of this dish's columns. */
    private function hasOwnerEdits(string $itemId): bool
    {
        return ManualOverride::query()->where('item_id', $itemId)->exists();
    }

    /* ------------------------------------------------------------------ */
    /*  Small helpers */
    /* ------------------------------------------------------------------ */

    /**
     * The content-lane source string for a category — see behaviour home (3).
     * `is_user_created` collapses the three owner-side `source_platform` values
     * to one bit, so the owner-side answer reports as 'manual'.
     */
    private function categorySource(\stdClass $category): ?string
    {
        return $category->is_user_created ? 'manual' : null;
    }

    private function isEditable(\stdClass $category): bool
    {
        return in_array($this->categorySource($category), self::EDITABLE_SOURCES, true);
    }

    /**
     * Clear `content.f_text.body` when the resolved description is null.
     *
     * upsertSingletonFacet() only writes the columns its input carries, and
     * MenuProjectionMapper::facets() omits `f_text` entirely for a null
     * description — so without this a cleared description silently keeps its old
     * value. ManualServiceWriter solves the same problem with $forceFacets;
     * MenuProjectionMapper has no such parameter and is not this task's to
     * change, so the clear is issued here instead.
     */
    private function syncDescription(string $userId, string $itemId, ?string $description): void
    {
        if ($description !== null) {
            return;
        }

        DB::connection('pgsql')->table('content.f_text')
            ->where('item_id', $itemId)
            ->whereIn('source_id', fn ($query) => $query->from('content.sources')
                ->select('id')->where('user_id', $userId)->where('kind', 'manual'))
            ->update(['body' => null, 'updated_at' => now()]);
    }

    private function isRemoved(string $itemId): bool
    {
        return DB::connection('pgsql')->table('content.items')
            ->where('id', $itemId)->whereNotNull('removed_at')->exists();
    }

    /** Pin a newly-created dish at the end of the owner's arrangement. */
    private function appendPin(User $user, string $itemId): void
    {
        $site = $this->currentSite($user);
        if ($site === null) {
            return;
        }

        $this->writer->pin($site, $itemId, $this->nextSortKey($this->menuSectionId($site)));
    }

    /**
     * Read-only resolve of the site's `pool:menus` section — never
     * provisioner->ensure(), which INSERTs a page and a section; composing an
     * order must not create rows. (ManualMenuWriter::pin() does provision, which
     * is correct on the write path.)
     */
    private function menuSectionId(Site $site): ?string
    {
        $id = DB::connection('pgsql')->table('site.sections')
            ->where('site_id', $site->id)
            ->where('key', PoolRegistry::sectionKey('menus'))
            ->value('id');

        return $id === null ? null : (string) $id;
    }

    private function nextSortKey(?string $sectionId): float
    {
        if ($sectionId === null) {
            return 1.0;
        }

        $highest = DB::connection('pgsql')->table('site.section_items')
            ->where('section_id', $sectionId)
            ->where('state', SectionItem::STATE_PINNED)
            ->max('sort_key');

        return $highest === null ? 1.0 : ((float) $highest) + 1.0;
    }

    /** @return array<string, float> */
    private function pinOrder(?string $sectionId): array
    {
        if ($sectionId === null) {
            return [];
        }

        return DB::connection('pgsql')->table('site.section_items')
            ->where('section_id', $sectionId)
            ->where('state', SectionItem::STATE_PINNED)
            ->whereNotNull('sort_key')
            ->pluck('sort_key', 'item_id')
            ->map(fn ($sortKey) => (float) $sortKey)
            ->all();
    }

    /**
     * Pinned first in sort_key order, then anything unpinned in arrival order —
     * MenuPayloadComposer::sortByPins()'s rule, applied to the same input, so
     * "the order the caller was looking at" is not a second guess at it.
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
     * Append {category, name} to menus.suppressed_items, deduped on the same
     * normalization the rebuild matches with (MenuFetchJob::suppressedItemKeys).
     * Stores the human-readable original strings; matches on their normalized form.
     */
    private function suppressItem(Menu $menu, string $categoryName, string $itemName): void
    {
        $list = $menu->suppressed_items ?? [];
        $key = $this->normalizeName($categoryName).'|'.$this->normalizeName($itemName);

        $already = collect($list)->contains(function ($entry) use ($key) {
            return is_array($entry)
                && $this->normalizeName((string) ($entry['category'] ?? '')).'|'.$this->normalizeName((string) ($entry['name'] ?? '')) === $key;
        });
        if ($already) {
            return;
        }

        $list[] = ['category' => $categoryName, 'name' => $itemName];
        $menu->forceFill(['suppressed_items' => $list])->save();
    }

    /**
     * [image_url, images] from a media item's optimized (else original) webp
     * variant. images is the single-element hero list (mirrors the scrape's shape);
     * both null when the media has no ready variant yet.
     *
     * @return array{image_url: ?string, images: ?list<string>}
     */
    private function imageFromMedia(SiteMedia $media): array
    {
        $variants = $media->variantUrls();
        $url = $variants['optimized'] ?? $variants['original'] ?? null;

        return ['image_url' => $url, 'images' => $url !== null ? [$url] : null];
    }

    /** Bust all three cache lanes for a menu content change, then echo the fresh menu. */
    private function touchAndRespond(User $user, string $reason): JsonResponse
    {
        $site = $this->currentSite($user);
        if ($site !== null) {
            // The single exit point for all ten verbs (Task 6 Step 7). The
            // content.* writes here are raw seams — BuildState::bump alone (which
            // writeManualItem() fires per item) does NOT bust the 60s payload
            // cache, and the category verbs bump nothing at all.
            SiteCacheLanes::bust([(string) $site->id]);
        }

        // bust()'s updated_at write is a query-builder update, so SiteObserver
        // never fires for it — touch explicitly for the Redis/KV sync and cache
        // warm that observer owns. Same EDGE-1 pairing UserServiceController's
        // reorder() uses.
        $this->invalidator->touchSite(fn () => $user->site, $reason, ['user_id' => $user->id]);

        return $this->success($this->payload->for($user));
    }
}
