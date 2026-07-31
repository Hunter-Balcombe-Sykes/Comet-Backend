<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\Menu;
use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheInvalidator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
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
// Matching: one normalized name = one dish, menu-wide. An item whose trimmed,
// case-insensitive name matches an EXISTING menu_items row — scraped or
// previously scanned, any source — is UPDATED in place (description/base_price
// only, and only the fields the scan actually supplied; a field the scan
// omitted never null's out existing content). A dish the scan lists under
// SEVERAL categories ("Garlic Bread" in Lunch AND Dinner) stays ONE row whose
// category MEMBERSHIPS grow — the scan's category is attached via the
// site.menu_item_categories pivot instead of creating a duplicate row.
// No match creates a new item under a scan-owned category, matched
// case-insensitively by trimmed name among the menu's OWN 'scan' categories
// ONLY — never reusing a scraped category, or the new item would be deleted
// right along with it on the next scrape refresh.
class MenuScanApplier
{
    use AllocatesMenuPositions, CleansScrapedStrings;

    private const DEFAULT_CATEGORY_NAME = 'Menu';

    private const SOURCE = 'scan';

    public function __construct(
        private readonly SiteCacheInvalidator $invalidator,
    ) {}

    /**
     * $enrichOnly switches the matched-item update rules (owner 2026-07-17):
     * the MANUAL dashboard scan (default) applies what the user's photo says
     * — descriptions and prices update when supplied; the AUTOMATIC Google-
     * photos scan passes true and only ADDS — longer descriptions win,
     * prices fill gaps, never overwrite. Dietary badges merge in both modes.
     * Category ATTACH on a matched item follows the same conservatism: the
     * manual scan always attaches the scan's category; enrich-only attaches
     * ONLY when the matched item carries no scraped membership (i.e. it's a
     * scan-created dish whose multi-category memberships this scan completes)
     * — an automatic scan never restructures a scraped dish's categories.
     *
     * $source overrides the menu_categories.source_platform / menu.content_source
     * tag written by this apply (default self::SOURCE = 'scan') — used by the
     * website-scan pipeline to tag its own menu writes 'website-scan' instead,
     * so they're independently protected from MenuFetchJob's rebuild wipe (see
     * MenuFetchJob::rebuildableCategoryIds()) without being confused for a
     * user's own manual/Google-photo scan.
     *
     * @param  list<array{name:string, description:?string, price:?float, category:?string, dietary?:?list<string>}>  $items
     * @return array{updated:int, added:int}
     */
    public function apply(User $user, array $items, bool $enrichOnly = false, string $source = self::SOURCE): array
    {
        $result = DB::connection('pgsql')->transaction(function () use ($user, $items, $enrichOnly, $source) {
            $menu = $this->resolveMenu($user, $source);

            // orderBy gives a deterministic base for "first occurrence wins"
            // below — without it, two same-name rows on Postgres could resolve
            // in an arbitrary order across requests.
            $existingItems = MenuItem::query()
                ->where('menu_id', $menu->id)
                ->with('categories')
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            $itemsByName = $this->groupByNormalizedName($existingItems, fn (MenuItem $item) => $item->name);

            $scanCategoriesByName = $this->indexByNormalizedName(
                MenuCategory::query()->where('menu_id', $menu->id)->where('source_platform', $source)->get(),
                fn (MenuCategory $category) => $category->name,
            );
            $nextCategoryPosition = $this->nextPosition(MenuCategory::query()->where('menu_id', $menu->id));
            $nextItemPositionByCategory = [];

            $updated = 0;
            $added = 0;

            foreach ($items as $item) {
                $name = trim((string) $item['name']);
                $nameKey = $this->normalize($name);
                $candidates = $itemsByName[$nameKey] ?? [];

                // A manual dish (owner-authored via the dashboard) outranks scan
                // enrichment — leave it untouched, and don't create a scan
                // duplicate either (the manual row already represents this dish).
                $hasManual = false;
                foreach ($candidates as $candidate) {
                    if ($candidate->is_manual) {
                        $hasManual = true;
                        break;
                    }
                }
                if ($hasManual) {
                    continue;
                }

                $match = $candidates[0] ?? null;
                if ($match !== null) {
                    $this->updateItem($match, $item, $enrichOnly);
                    // Multi-category attach: the scan listing a KNOWN dish under a
                    // category it isn't in yet grows its memberships ("Garlic
                    // Bread" scanned under Lunch AND Dinner = one row, two
                    // memberships). Skipped when the scan named no category, and
                    // satisfied by ANY same-named existing membership (scraped
                    // "Sides" already covers a scan's "sides" — never shadow a
                    // scraped category with a scan-owned duplicate).
                    $scanCategoryLabel = $this->cleanString($item['category'] ?? null);
                    if ($scanCategoryLabel !== null && $this->shouldAttachOnMatch($match, $enrichOnly)) {
                        $labelKey = $this->normalize($scanCategoryLabel);
                        $alreadyListed = $match->categories->contains(
                            fn (MenuCategory $c) => $this->normalize((string) $c->name) === $labelKey
                        );
                        if (! $alreadyListed) {
                            $category = $this->resolveScanCategory(
                                $menu, $scanCategoryLabel, $source,
                                $scanCategoriesByName, $nextCategoryPosition,
                            );
                            $this->ensureMembership($match, $category, $nextItemPositionByCategory);
                        }
                    }
                    $updated++;

                    continue;
                }

                $category = $this->resolveScanCategory(
                    $menu, $item['category'] ?? null, $source,
                    $scanCategoriesByName, $nextCategoryPosition,
                );

                $newItem = MenuItem::create([
                    'menu_id' => $menu->id,
                    'name' => $name,
                    'description' => $this->cleanString($item['description'] ?? null),
                    'base_price' => $item['price'] ?? null,
                    // Scan-found dietary markers badge new items too (same
                    // {text, type} rows the platform drivers write).
                    'badges' => $this->mergeDietaryBadges(null, $item['dietary'] ?? null),
                ]);
                $newItem->setRelation('categories', new EloquentCollection);
                $this->ensureMembership($newItem, $category, $nextItemPositionByCategory);
                // The new item joins the lookup pool too, so a LATER scan item
                // in this SAME batch sharing this name attaches its category to
                // THIS row instead of creating a duplicate — the multi-category
                // dedupe this applier exists for.
                $itemsByName[$nameKey][] = $newItem;
                $added++;
            }

            return ['updated' => $updated, 'added' => $added];
        });

        // Scan-applied items/prices show on the public menu page — bust the edge
        // cache when the scan actually changed something. These are direct model
        // writes inside a transaction (create/update), but no observer busts the
        // SITE cache, so dispatch it explicitly (via touchSite → SiteObserver, not
        // a raw purge job). Skip a no-op scan that matched nothing new.
        if ($result['updated'] > 0 || $result['added'] > 0) {
            $this->invalidator->touchSite(
                fn () => $user->site,
                'menu-scan-apply',
                ['user_id' => $user->id],
            );
        }

        return $result;
    }

    /**
     * Whether a MATCHED item should also gain the scan's category membership.
     * Manual scans always attach (the user deliberately scanned this menu's
     * structure). Enrich-only (automatic) scans attach only to scan-created
     * dishes — a dish with any scraped membership keeps its scraped structure.
     */
    private function shouldAttachOnMatch(MenuItem $match, bool $enrichOnly): bool
    {
        if (! $enrichOnly) {
            return true;
        }

        return ! $match->categories->contains(
            fn (MenuCategory $c) => ! in_array($c->source_platform, ['scan', 'manual', 'website-scan'], true)
        );
    }

    /**
     * The scan-owned category for a scan item's (possibly absent) category
     * label — matched case-insensitively among the menu's OWN $source
     * categories, created (appended) when missing. Never reuses a scraped
     * category: the next scrape rebuild would delete the membership with it.
     *
     * @param  array<string, MenuCategory>  $scanCategoriesByName
     */
    private function resolveScanCategory(
        Menu $menu,
        mixed $rawCategoryName,
        string $source,
        array &$scanCategoriesByName,
        int &$nextCategoryPosition,
    ): MenuCategory {
        $categoryName = $this->cleanCategoryName(is_string($rawCategoryName) ? $rawCategoryName : null);
        $categoryKey = $this->normalize($categoryName);

        $category = $scanCategoriesByName[$categoryKey] ?? null;
        if ($category === null) {
            $category = MenuCategory::create([
                'menu_id' => $menu->id,
                'name' => $categoryName,
                'position' => $nextCategoryPosition++,
                'source_platform' => $source,
            ]);
            $scanCategoriesByName[$categoryKey] = $category;
        }

        return $category;
    }

    /**
     * Attach $item to $category (appended after the category's current last
     * slot) unless it's already a member. Keeps the in-memory `categories`
     * relation in sync so later batch entries see the new membership.
     *
     * @param  array<string, int>  $nextItemPositionByCategory
     */
    private function ensureMembership(MenuItem $item, MenuCategory $category, array &$nextItemPositionByCategory): void
    {
        if ($item->categories->contains(fn (MenuCategory $c) => (string) $c->id === (string) $category->id)) {
            return;
        }

        $nextItemPositionByCategory[$category->id] ??= 1 + (int) (DB::connection('pgsql')
            ->table('site.menu_item_categories')
            ->where('menu_category_id', $category->id)
            ->max('position') ?? -1);

        $item->categories()->attach($category->id, ['position' => $nextItemPositionByCategory[$category->id]++]);
        $item->setRelation('categories', $item->categories->push($category));
    }

    /**
     * The user's menu row, creating a scan-sourced one when none exists yet.
     * last_fetched_at is stamped on every apply (create or update) — both
     * PublicMenuController and SitepageDataResolverService gate menu
     * visibility on it being non-null, and a scan-only menu never gets a real
     * scrape to set it otherwise.
     */
    private function resolveMenu(User $user, string $source): Menu
    {
        $menu = Menu::query()->where('user_id', $user->id)->first();
        if ($menu !== null) {
            $menu->forceFill(['last_fetched_at' => now()])->save();

            return $menu;
        }

        return Menu::create([
            'user_id' => $user->id,
            'content_source' => $source,
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
     * Matched-item update. Manual mode (default) applies what the scan says
     * — description/price update whenever supplied (the user deliberately
     * scanned this photo). Enrich-only mode (the automatic Google-photos
     * scan) only ADDS to what a platform scrape already knows:
     *   - description: scanned text wins only when it says MORE (strictly
     *     longer than what's stored; also fills an empty one).
     *   - price: fills a missing price only — an OCR misread must never
     *     clobber a platform-scraped price.
     * Dietary markers (GF / V / …) merge into badges in BOTH modes, deduped
     * case-insensitively against whatever the platforms already carry.
     *
     * @param  array{name:string, description:?string, price:?float, category:?string, dietary?:?list<string>}  $item
     */
    private function updateItem(MenuItem $existing, array $item, bool $enrichOnly): void
    {
        $changes = [];

        $description = $this->cleanString($item['description'] ?? null);
        $descriptionSaysMore = $description !== null
            && mb_strlen($description) > mb_strlen((string) $existing->description);
        if ($description !== null && ($enrichOnly ? $descriptionSaysMore : true)) {
            $changes['description'] = $description;
        }

        if (($item['price'] ?? null) !== null && (! $enrichOnly || $existing->base_price === null)) {
            $changes['base_price'] = $item['price'];
        }

        $mergedBadges = $this->mergeDietaryBadges($existing->badges, $item['dietary'] ?? null);
        if ($mergedBadges !== null) {
            $changes['badges'] = $mergedBadges;
        }

        if ($changes !== []) {
            $existing->forceFill($changes)->save();
        }
    }

    /**
     * Existing badges + scanned dietary labels, or null when nothing new —
     * badge shape mirrors the menu drivers' {text, type?} rows, with scan
     * rows typed 'dietary'.
     *
     * @param  list<string>|null  $dietary
     * @return list<array{text:string, type?:string}>|null
     */
    private function mergeDietaryBadges(mixed $existingBadges, ?array $dietary): ?array
    {
        if ($dietary === null || $dietary === []) {
            return null;
        }

        $badges = is_array($existingBadges) ? array_values(array_filter($existingBadges, 'is_array')) : [];
        $seen = [];
        foreach ($badges as $badge) {
            if (is_string($badge['text'] ?? null)) {
                $seen[strtolower(trim($badge['text']))] = true;
            }
        }

        $addedAny = false;
        foreach ($dietary as $label) {
            if (! is_string($label) || trim($label) === '') {
                continue;
            }
            $key = strtolower(trim($label));
            if ($seen[$key] ?? false) {
                continue;
            }
            $badges[] = ['text' => trim($label), 'type' => 'dietary'];
            $seen[$key] = true;
            $addedAny = true;
        }

        return $addedAny ? $badges : null;
    }

    private function cleanCategoryName(?string $category): string
    {
        $trimmed = trim((string) $category);

        return $trimmed !== '' ? $trimmed : self::DEFAULT_CATEGORY_NAME;
    }

    /** lowercase + trim — the brief's literal match rule (no punctuation stripping, unlike normalizeName()). */
    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
