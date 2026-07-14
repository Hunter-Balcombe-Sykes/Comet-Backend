<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\User\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

// Applies a batch of AI-extracted menu items (a user-uploaded menu photo/PDF,
// scanned by the frontend) onto a user's menu. Distinct from
// MenuFetchJob/MenuMerger: those wholesale-rebuild menu content from a live
// Uber Eats/DoorDash scrape; this ADDS TO / PATCHES existing content by
// matching on name, and every row it writes is tagged
// menu_categories.source_platform = 'scan' — the marker MenuFetchJob's
// wholesale rebuild treats as off-limits, so scanned content survives a later
// scrape refresh (see MenuFetchJob::rebuildableCategoryIds()).
//
// Matching: an item whose trimmed, case-insensitive name matches an EXISTING
// menu_items row — scraped or previously scanned, any source — is UPDATED in
// place (description/base_price only, and only the fields the scan actually
// supplied; a field the scan omitted never null's out existing content).
// Same-named items across DIFFERENT categories (e.g. "Garlic Bread" as both a
// Starter and a Side) are a real, disambiguation-worthy collision: when the
// scan supplies a category, the (name, category) match wins; otherwise (or
// when that scoped match misses) the plain name-only match is used ONLY when
// it's unambiguous (exactly one candidate) — with 2+ same-name candidates and
// nothing to disambiguate them, a new item is created rather than guessed.
// No match (or an unresolved ambiguity) creates a new item under a scan-owned
// category, matched case-insensitively by trimmed name among the menu's OWN
// 'scan' categories ONLY — never reusing a scraped category, or the new item
// would be deleted right along with it on the next scrape refresh.
class MenuScanApplier
{
    private const DEFAULT_CATEGORY_NAME = 'Menu';

    private const SOURCE = 'scan';

    /**
     * @param  list<array{name:string, description:?string, price:?float, category:?string}>  $items
     * @return array{updated:int, added:int}
     */
    public function apply(User $user, array $items): array
    {
        return DB::connection('pgsql')->transaction(function () use ($user, $items) {
            $menu = $this->resolveMenu($user);

            // orderBy gives a deterministic base for "first occurrence wins"
            // below — without it, two same-name rows on Postgres could resolve
            // in an arbitrary order across requests.
            $existingItems = MenuItem::query()
                ->where('menu_id', $menu->id)
                ->with('category')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            $itemsByName = $this->groupByNormalizedName($existingItems, fn (MenuItem $item) => $item->name);

            // (name, category) scoped index for disambiguating a same-name
            // collision across sections — first occurrence per pair wins.
            $itemsByNameAndCategory = [];
            foreach ($existingItems as $existingItem) {
                $categoryName = $existingItem->category?->name;
                if ($categoryName === null) {
                    continue;
                }
                $itemsByNameAndCategory[$this->normalize($existingItem->name)][$this->normalize($categoryName)] ??= $existingItem;
            }

            $scanCategoriesByName = $this->indexByNormalizedName(
                MenuCategory::query()->where('menu_id', $menu->id)->where('source_platform', self::SOURCE)->get(),
                fn (MenuCategory $category) => $category->name,
            );
            $nextCategoryPosition = $this->nextPosition(MenuCategory::query()->where('menu_id', $menu->id));
            $nextItemPositionByCategory = [];

            $updated = 0;
            $added = 0;

            foreach ($items as $item) {
                $name = trim((string) $item['name']);
                $nameKey = $this->normalize($name);
                $scanCategoryName = $this->cleanString($item['category'] ?? null);

                $match = $this->resolveMatch($nameKey, $scanCategoryName, $itemsByNameAndCategory, $itemsByName);
                if ($match !== null) {
                    $this->updateItem($match, $item);
                    $updated++;

                    continue;
                }

                $categoryName = $this->cleanCategoryName($item['category'] ?? null);
                $categoryKey = $this->normalize($categoryName);
                $category = $scanCategoriesByName[$categoryKey] ?? null;
                if ($category === null) {
                    $category = MenuCategory::create([
                        'menu_id' => $menu->id,
                        'name' => $categoryName,
                        'position' => $nextCategoryPosition++,
                        'source_platform' => self::SOURCE,
                    ]);
                    $scanCategoriesByName[$categoryKey] = $category;
                }

                $nextItemPositionByCategory[$category->id] ??= $this->nextPosition(
                    MenuItem::query()->where('category_id', $category->id)
                );

                $newItem = MenuItem::create([
                    'menu_id' => $menu->id,
                    'category_id' => $category->id,
                    'position' => $nextItemPositionByCategory[$category->id]++,
                    'name' => $name,
                    'description' => $this->cleanString($item['description'] ?? null),
                    'base_price' => $item['price'] ?? null,
                ]);
                // The new item joins both lookup pools too, so a LATER scan
                // item in this SAME batch sharing this name can still find it
                // (mirrors the prior single-map "first occurrence wins" reuse).
                $itemsByName[$nameKey][] = $newItem;
                $itemsByNameAndCategory[$nameKey][$categoryKey] ??= $newItem;
                $added++;
            }

            return ['updated' => $updated, 'added' => $added];
        });
    }

    /**
     * The existing item a scan item should update, or null when no
     * unambiguous match exists. A scan-supplied category wins first
     * (case-insensitive category-name match); otherwise — or when that
     * scoped lookup misses — fall back to the name-only match, but ONLY when
     * it's unambiguous (exactly one candidate). A same-name collision across
     * categories with nothing to disambiguate it is a "don't guess" case,
     * left to the caller to create a new item instead.
     *
     * @param  array<string, array<string, MenuItem>>  $itemsByNameAndCategory
     * @param  array<string, list<MenuItem>>  $itemsByName
     */
    private function resolveMatch(string $nameKey, ?string $scanCategoryName, array $itemsByNameAndCategory, array $itemsByName): ?MenuItem
    {
        if ($scanCategoryName !== null) {
            $scoped = $itemsByNameAndCategory[$nameKey][$this->normalize($scanCategoryName)] ?? null;
            if ($scoped !== null) {
                return $scoped;
            }
        }

        $candidates = $itemsByName[$nameKey] ?? [];

        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * The user's menu row, creating a scan-sourced one when none exists yet.
     * last_fetched_at is stamped on every apply (create or update) — both
     * PublicMenuController and SitepageDataResolverService gate menu
     * visibility on it being non-null, and a scan-only menu never gets a real
     * scrape to set it otherwise.
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
            'content_source' => self::SOURCE,
            'currency' => 'AUD',
            'fetch_status' => 'ok',
            'last_fetched_at' => now(),
        ]);
    }

    /**
     * @template TModel of MenuItem|MenuCategory
     *
     * @param  iterable<TModel>  $models
     * @param  callable(TModel): string  $nameOf
     * @return array<string, TModel> normalized name => model, first occurrence wins
     */
    private function indexByNormalizedName(iterable $models, callable $nameOf): array
    {
        $index = [];
        foreach ($models as $model) {
            $index[$this->normalize($nameOf($model))] ??= $model;
        }

        return $index;
    }

    /**
     * @param  iterable<MenuItem>  $models
     * @param  callable(MenuItem): string  $nameOf
     * @return array<string, list<MenuItem>> normalized name => every matching model, insertion order preserved
     */
    private function groupByNormalizedName(iterable $models, callable $nameOf): array
    {
        $index = [];
        foreach ($models as $model) {
            $index[$this->normalize($nameOf($model))][] = $model;
        }

        return $index;
    }

    /**
     * @param  array{name:string, description:?string, price:?float, category:?string}  $item
     */
    private function updateItem(MenuItem $existing, array $item): void
    {
        $changes = [];

        $description = $this->cleanString($item['description'] ?? null);
        if ($description !== null) {
            $changes['description'] = $description;
        }

        if (($item['price'] ?? null) !== null) {
            $changes['base_price'] = $item['price'];
        }

        if ($changes !== []) {
            $existing->forceFill($changes)->save();
        }
    }

    /** One past the current max `position` in $query (0 when the table's empty). */
    private function nextPosition(Builder $query): int
    {
        return 1 + (int) ($query->max('position') ?? -1);
    }

    private function cleanCategoryName(?string $category): string
    {
        $trimmed = trim((string) $category);

        return $trimmed !== '' ? $trimmed : self::DEFAULT_CATEGORY_NAME;
    }

    private function cleanString(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /** lowercase + trim — the brief's literal match rule (no punctuation stripping, unlike MenuMerger::norm()). */
    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
