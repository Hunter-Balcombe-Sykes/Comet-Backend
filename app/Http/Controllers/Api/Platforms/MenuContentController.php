<?php

namespace App\Http\Controllers\Api\Platforms;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Platforms\CreateMenuCategoryRequest;
use App\Http\Requests\Platforms\CreateMenuItemRequest;
use App\Http\Requests\Platforms\UpdateMenuCategoryRequest;
use App\Http\Requests\Platforms\UpdateMenuItemRequest;
use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\Site\MenuItemPlatform;
use App\Models\Core\Site\SiteMedia;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Cache\SiteCacheInvalidator;
use App\Services\Platforms\MenuPayloadComposer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Owner-authored (manual) menu management — the user editing their own menu by
// hand, independent of the Uber Eats / DoorDash scrape (MenuFetchJob) and the
// photo/PDF scan (MenuScanApplier). Categories are source_platform='manual';
// items are is_manual=true. Both survive every scrape rebuild (see
// MenuFetchJob::persist()). Editing a SCRAPED dish detaches it from platform
// sync (is_manual flips to true); deleting a scraped dish suppresses it so the
// next scrape doesn't resurrect it (menus.suppressed_items).
//
// Every write is capability-gated (can_use_menu, a food-business feature) and
// resolves the target strictly through the caller's own menu (user-scoped
// queries → 404 for anything not theirs — the same enforcement MenuController
// uses, and why the child models stay policy-exempt). Each write busts the
// public sitepage cache and returns the fresh full menu the dashboard reads,
// composed by the shared MenuPayloadComposer.
class MenuContentController extends ApiController
{
    use ResolveCurrentUser;

    private const DEFAULT_CATEGORY_NAME = 'Menu';

    // Category sources the owner is allowed to rename/delete — everything else
    // ('uber-eats'/'doordash'/NULL) is scraper-owned and off-limits to hand edits.
    private const EDITABLE_SOURCES = ['manual', 'scan'];

    public function __construct(
        private readonly MenuPayloadComposer $composer,
        private readonly SiteCacheInvalidator $invalidator,
    ) {}

    // POST /api/platforms/menu/categories — add a manual category.
    public function createCategory(CreateMenuCategoryRequest $request): JsonResponse
    {
        $user = $this->currentUser($request);
        if ($denied = $this->denyWithoutCapability($user)) {
            return $denied;
        }

        $name = trim($request->validated()['name']);

        // Duplicate name (case-insensitive) among the menu's live categories.
        $existing = Menu::query()->where('user_id', $user->id)->first();
        if ($existing !== null && $this->categoryNameExists($existing, $name)) {
            return $this->error('A category with this name already exists.', 422);
        }

        $menu = $this->resolveMenu($user);
        MenuCategory::create([
            'menu_id' => $menu->id,
            'name' => $name,
            'position' => $this->nextPosition(MenuCategory::query()->where('menu_id', $menu->id)),
            'source_platform' => 'manual',
        ]);

        return $this->touchAndRespond($user, 'menu-manual-category-create');
    }

    // PATCH /api/platforms/menu/categories/{category} — rename a manual/scan category.
    public function updateCategory(UpdateMenuCategoryRequest $request, string $category): JsonResponse
    {
        $user = $this->currentUser($request);
        if ($denied = $this->denyWithoutCapability($user)) {
            return $denied;
        }

        $model = $this->findCategory($user, $category);
        if ($model === null) {
            return $this->error('Not found.', 404);
        }
        if (! in_array($model->source_platform, self::EDITABLE_SOURCES, true)) {
            return $this->error("Synced categories can't be renamed.", 422);
        }

        $model->update(['name' => trim($request->validated()['name'])]);

        return $this->touchAndRespond($user, 'menu-manual-category-rename');
    }

    // DELETE /api/platforms/menu/categories/{category} — delete a manual/scan
    // category and its items.
    public function deleteCategory(Request $request, string $category): JsonResponse
    {
        $user = $this->currentUser($request);
        if ($denied = $this->denyWithoutCapability($user)) {
            return $denied;
        }

        $model = $this->findCategory($user, $category);
        if ($model === null) {
            return $this->error('Not found.', 404);
        }
        if (! in_array($model->source_platform, self::EDITABLE_SOURCES, true)) {
            return $this->error("Synced categories can't be deleted.", 422);
        }

        DB::connection('pgsql')->transaction(function () use ($model) {
            $itemIds = MenuItem::query()->where('category_id', $model->id)->pluck('id');
            MenuItemPlatform::query()->whereIn('menu_item_id', $itemIds)->delete();
            MenuItem::query()->where('category_id', $model->id)->delete();
            $model->delete();
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

        // A supplied category must belong to the caller's menu (any source); when
        // omitted, find-or-create the manual default 'Menu' category.
        if (! empty($data['category_id'])) {
            $menu = Menu::query()->where('user_id', $user->id)->first();
            $category = $menu !== null
                ? MenuCategory::query()->where('menu_id', $menu->id)->find($data['category_id'])
                : null;
            if ($category === null) {
                return $this->error('Category not found.', 404);
            }
            // Freshen last_fetched_at like resolveMenu / MenuScanApplier so manual
            // content is publicly visible (PublicMenuController gates on it).
            $menu->forceFill(['last_fetched_at' => now()])->save();
        } else {
            $menu = $this->resolveMenu($user);
            $category = $this->manualDefaultCategory($menu);
        }

        MenuItem::create([
            'menu_id' => $menu->id,
            'category_id' => $category->id,
            'position' => $this->nextPosition(MenuItem::query()->where('category_id', $category->id)),
            'name' => trim($data['name']),
            'description' => $this->cleanString($data['description'] ?? null),
            'base_price' => $data['price'] ?? null,
            'image_url' => $image['image_url'],
            'images' => $image['images'],
            'is_manual' => true,
        ]);

        return $this->touchAndRespond($user, 'menu-manual-item-create');
    }

    // PATCH /api/platforms/menu/items/{item} — edit any dish. Editing a scraped
    // dish detaches it from platform sync (is_manual flips true).
    public function updateItem(UpdateMenuItemRequest $request, string $item): JsonResponse
    {
        $user = $this->currentUser($request);
        if ($denied = $this->denyWithoutCapability($user)) {
            return $denied;
        }

        $model = $this->findItem($user, $item);
        if ($model === null) {
            return $this->error('Not found.', 404);
        }

        $data = $request->validated();
        $changes = [];

        if (array_key_exists('name', $data)) {
            $changes['name'] = trim($data['name']);
        }
        if (array_key_exists('description', $data)) {
            $changes['description'] = $this->cleanString($data['description']);
        }
        if (array_key_exists('price', $data)) {
            $changes['base_price'] = $data['price'];
        }
        // Move to another of the caller's categories (any source). A null value is
        // treated as "no change" — a dish is never orphaned out of its category.
        if (! empty($data['category_id'])) {
            $category = MenuCategory::query()->where('menu_id', $model->menu_id)->find($data['category_id']);
            if ($category === null) {
                return $this->error('Category not found.', 404);
            }
            if ((string) $category->id !== (string) $model->category_id) {
                $changes['category_id'] = $category->id;
                $changes['position'] = $this->nextPosition(MenuItem::query()->where('category_id', $category->id));
            }
        }
        // Image: remove_image wins; otherwise a supplied image_media_id sets it.
        if (! empty($data['remove_image'])) {
            $changes['image_url'] = null;
            $changes['images'] = null;
        } elseif (! empty($data['image_media_id'])) {
            $media = $this->ownedMedia($user, $data['image_media_id']);
            if ($media === null) {
                return $this->error('Image not found.', 404);
            }
            $image = $this->imageFromMedia($media);
            $changes['image_url'] = $image['image_url'];
            $changes['images'] = $image['images'];
        }

        if ($changes !== []) {
            // Editing a SCRAPED dish (is_manual=false in a scraper-owned category)
            // detaches it from platform sync — the owner's version now wins every
            // rebuild. Scan/manual dishes just update.
            if (! $model->is_manual && $this->isScrapedCategory($model->category)) {
                $changes['is_manual'] = true;
            }
            $model->update($changes);
        }

        return $this->touchAndRespond($user, 'menu-manual-item-update');
    }

    // DELETE /api/platforms/menu/items/{item} — remove a dish. A scraped dish is
    // also suppressed so the next scrape doesn't resurrect it.
    public function deleteItem(Request $request, string $item): JsonResponse
    {
        $user = $this->currentUser($request);
        if ($denied = $this->denyWithoutCapability($user)) {
            return $denied;
        }

        $menu = Menu::query()->where('user_id', $user->id)->first();
        $model = $menu !== null
            ? MenuItem::query()->where('menu_id', $menu->id)->with('category')->find($item)
            : null;
        if ($model === null) {
            return $this->error('Not found.', 404);
        }

        DB::connection('pgsql')->transaction(function () use ($menu, $model) {
            // A scraped dish (not manual, in a scraper-owned category) is recorded
            // as suppressed so the wholesale rebuild won't bring it back.
            if (! $model->is_manual && $this->isScrapedCategory($model->category)) {
                $this->suppressItem($menu, (string) $model->category?->name, (string) $model->name);
            }
            MenuItemPlatform::query()->where('menu_item_id', $model->id)->delete();
            $model->delete();
        });

        return $this->touchAndRespond($user, 'menu-manual-item-delete');
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

    /** The caller's category by id, scoped to their own menu (null = not found / not theirs). */
    private function findCategory(User $user, string $categoryId): ?MenuCategory
    {
        $menu = Menu::query()->where('user_id', $user->id)->first();

        return $menu !== null
            ? MenuCategory::query()->where('menu_id', $menu->id)->find($categoryId)
            : null;
    }

    /** The caller's item by id (with its category), scoped to their own menu. */
    private function findItem(User $user, string $itemId): ?MenuItem
    {
        $menu = Menu::query()->where('user_id', $user->id)->first();

        return $menu !== null
            ? MenuItem::query()->where('menu_id', $menu->id)->with('category')->find($itemId)
            : null;
    }

    /**
     * The user's menu row, creating a manual-sourced one when none exists yet
     * (mirrors MenuScanApplier::resolveMenu). last_fetched_at is stamped on every
     * resolve so PublicMenuController/SitepageDataResolverService — both gate menu
     * visibility on it — surface owner-authored content that never gets a scrape.
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

    /** The menu's manual 'Menu' category, creating it when absent (default bucket for uncategorised dishes). */
    private function manualDefaultCategory(Menu $menu): MenuCategory
    {
        $category = MenuCategory::query()
            ->where('menu_id', $menu->id)
            ->where('source_platform', 'manual')
            ->get()
            ->first(fn (MenuCategory $c) => mb_strtolower(trim((string) $c->name)) === mb_strtolower(self::DEFAULT_CATEGORY_NAME));

        return $category ?? MenuCategory::create([
            'menu_id' => $menu->id,
            'name' => self::DEFAULT_CATEGORY_NAME,
            'position' => $this->nextPosition(MenuCategory::query()->where('menu_id', $menu->id)),
            'source_platform' => 'manual',
        ]);
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

    /* ------------------------------------------------------------------ */
    /*  Small helpers */
    /* ------------------------------------------------------------------ */

    /** Whether a category is scraper-owned (its edits detach / its deletes suppress). */
    private function isScrapedCategory(?MenuCategory $category): bool
    {
        return $category === null || ! in_array($category->source_platform, self::EDITABLE_SOURCES, true);
    }

    /** True when the menu already has a category whose name matches (case-insensitive). */
    private function categoryNameExists(Menu $menu, string $name): bool
    {
        $target = mb_strtolower(trim($name));

        return MenuCategory::query()->where('menu_id', $menu->id)->get(['name'])
            ->contains(fn (MenuCategory $c) => mb_strtolower(trim((string) $c->name)) === $target);
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

    /** One past the current max `position` in $query (0 when empty). */
    private function nextPosition(Builder $query): int
    {
        return 1 + (int) ($query->max('position') ?? -1);
    }

    /** Trim to null — an empty/whitespace string clears the field. */
    private function cleanString(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * lowercase, non-alphanumerics → single spaces, trimmed — IDENTICAL to
     * MenuFetchJob::normalizeName so a suppressed dish matches at rebuild time.
     * (Kept local — duplication over a shared dependency, matching the menu
     * code's own convention.)
     */
    private function normalizeName(string $s): string
    {
        $s = mb_strtolower($s);
        $s = preg_replace('~[^a-z0-9]+~', ' ', $s) ?? '';

        return trim((string) preg_replace('~\s+~', ' ', $s));
    }

    /** Bust the public sitepage cache for a menu content change, then echo the fresh menu. */
    private function touchAndRespond(User $user, string $reason): JsonResponse
    {
        // Direct model writes bypass the SiteObserver menu-cache path, so dispatch
        // the invalidation explicitly (via touchSite → SiteObserver, not a raw
        // purge job) — the same route MenuScanApplier uses.
        $this->invalidator->touchSite(fn () => $user->site, $reason, ['user_id' => $user->id]);

        return $this->success($this->composer->dashboardPayload($user));
    }
}
