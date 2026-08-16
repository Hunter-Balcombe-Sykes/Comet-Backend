<?php

namespace App\Services\Platforms;

use App\Models\Core\Site\Menu;
use App\Models\Core\User\User;
use App\Services\Cache\SiteCacheInvalidator;
use App\Services\Content\ManualMenuItems;
use App\Services\Content\ManualMenuWriter;
use Illuminate\Support\Facades\DB;

// Applies a batch of AI-extracted menu items (a user-uploaded menu photo/PDF,
// scanned by the frontend) onto a user's menu. Distinct from
// MenuFetchJob/MenuMerger: those wholesale-rebuild menu content from a live
// Uber Eats/DoorDash scrape; this ADDS TO / PATCHES existing content by
// matching on name.
//
// Slice 7 Task 8 moved every write here off site.menu_items /
// site.menu_categories / site.menu_item_categories onto content.* through
// ManualMenuWriter. site.menus itself survives (scan_items, suppressed_items,
// content_source, last_fetched_at) and is still the coord's namespace.
//
// Matching: one normalized name = one dish, menu-wide — now enforced by the
// COORD rather than by a lookup. MenuProjectionMapper::coordFor($menuId, $name)
// hashes the normalised name, and ProjectionWriter::writeManualItem() is
// idempotent on the coord, so "same name = same row" is a property of the
// address rather than of a scan of existing rows. (The normaliser widened
// slightly as a result: coordFor() uses normalizeName(), which strips
// punctuation, where this class's own match was lowercase+trim only. "Coke" and
// "Coke!" are now one dish — the same rule MenuFetchJob's identity reuse has
// always applied.)
//
// A dish the scan lists under SEVERAL categories ("Garlic Bread" in Lunch AND
// Dinner) stays ONE item whose collection MEMBERSHIPS grow. That is the reason
// every write below re-projects the WHOLE dish rather than the fields the scan
// supplied: ProjectionWriter::replaceCollections() replaces media / offers /
// tags / collection_items wholesale per (item, source), and all manual writes
// share one source, so a partial projection would DELETE the dish's other
// categories, its ordering-platform memberships, its images and its prices.
// The full projection is composed from ManualMenuItems (the mapper run
// backwards) merged with what the scan supplied, which is what keeps "a field
// the scan omitted never nulls out existing content" true.
//
// No match creates a new item under a scan-owned category — see
// categoryRefFor(): scan categories live in their OWN external_ref namespace so
// they can never be confused for, or folded into, a scraped one.
class MenuScanApplier
{
    use CleansScrapedStrings;

    private const DEFAULT_CATEGORY_NAME = 'Menu';

    private const SOURCE = 'scan';

    /**
     * The sources whose menu categories are OWNER content rather than scraper
     * output — the content.* successor to `menu_categories.source_platform`.
     *
     * content.collections has no source column (and gaining one would be a
     * schema change for a fact the natural key can already carry), so the source
     * lives in the external_ref instead. See categoryRefFor().
     */
    public const OWNER_CATEGORY_SOURCES = ['manual', 'scan', 'website-scan'];

    public function __construct(
        private readonly SiteCacheInvalidator $invalidator,
        private readonly ManualMenuWriter $writer,
        private readonly ManualMenuItems $items,
    ) {}

    /**
     * The `content.collections.external_ref` for an OWNER-owned menu category —
     * MenuProjectionMapper::categoryRef()'s ref, namespaced by the source that
     * created it: `menu:<source>:<slug>`.
     *
     * This is slice 7's replacement for `menu_categories.source_platform`, and
     * it is deliberately part of the KEY rather than a column beside it:
     *
     * - A scan category can never collide with a scraped one. Str::slug() emits
     *   `[a-z0-9-]` only, so `menu:scan:pizza` is unreachable from
     *   categoryRef('…') no matter what a vendor calls a category. That is the
     *   "never reuse a scraped category" rule made structural — under the legacy
     *   tables reusing one meant the next scrape rebuild deleted the scan's dish
     *   along with the category.
     * - Two SCAN sources stay apart for the same reason: a 'website-scan' Pizza
     *   and a manual-scan Pizza are `menu:website-scan:pizza` and
     *   `menu:scan:pizza`, exactly as they were two `menu_categories` rows.
     * - Matching by trimmed, case-insensitive name comes free: Str::slug()
     *   already lowercases and trims, so the ref IS the match key and no lookup
     *   index is needed.
     *
     * Task 6 (MenuContentController) owns the owner's hand-created categories
     * and MUST mint them through this same helper with source `'manual'`.
     * A menu_category collection carrying a NULL external_ref cannot be
     * addressed by the projection at all, so this class treats a dish holding
     * one as untouchable rather than silently dropping the membership (see
     * categoryEntries()).
     */
    public static function categoryRefFor(string $source, string $label): string
    {
        // Derived FROM the mapper's own ref rather than re-slugified, so the
        // slug (and its all-punctuation hash fallback) can only ever have one
        // definition.
        return 'menu:'.$source.':'.substr(MenuProjectionMapper::categoryRef($label), strlen('menu:'));
    }

    /** Whether a menu_category ref belongs to the owner rather than to a scraper. */
    public static function isOwnerCategoryRef(?string $ref): bool
    {
        foreach (self::OWNER_CATEGORY_SOURCES as $source) {
            if (is_string($ref) && str_starts_with($ref, 'menu:'.$source.':')) {
                return true;
            }
        }

        return false;
    }

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
     * $source overrides the category namespace / menu.content_source tag
     * written by this apply (default self::SOURCE = 'scan') — used by the
     * website-scan pipeline to tag its own menu writes 'website-scan' instead,
     * so they're independently protected from MenuFetchJob's rebuild wipe
     * without being confused for a user's own manual/Google-photo scan.
     *
     * @param  list<array{name:string, description:?string, price:?float, category:?string, dietary?:?list<string>}>  $items
     * @return array{updated:int, added:int}
     */
    public function apply(User $user, array $items, bool $enrichOnly = false, string $source = self::SOURCE): array
    {
        $userId = (string) $user->id;

        // NO surrounding transaction: writeManualItem() manages its own
        // boundaries and ProjectionWriter's docblock forbids nesting it.
        $menu = $this->resolveMenu($user, $source);

        // includeRemoved, deliberately: an owner-deleted dish keeps its coord,
        // so a re-listed one must MERGE onto the existing row (leaving
        // items.removed_at alone — the one-way delete) rather than read as a
        // no-match and re-project a bare copy over its stored facets.
        $byCoord = [];
        foreach ($this->items->rows($userId, includeRemoved: true) as $row) {
            $byCoord[(string) $row->coord] ??= $row;
        }

        $categoriesById = [];
        $positionByRef = [];
        $nextCategoryPosition = 0;
        foreach ($this->items->categories($userId) as $category) {
            $categoriesById[(string) $category->id] = $category;
            $positionByRef[(string) $category->external_ref] = (int) $category->position;
            $nextCategoryPosition = max($nextCategoryPosition, ((int) $category->position) + 1);
        }

        $locked = $this->lockedItemIds($userId);
        $entriesByCoord = [];

        $updated = 0;
        $added = 0;

        foreach ($items as $item) {
            $name = trim((string) $item['name']);
            // A blank name hashes to a coord shared by every other blank one,
            // so it would fold unrelated dishes together. HTTP 422s these and
            // the extractor drops them; this is the belt for the scan_items
            // round trip, which no validator guards.
            if ($name === '') {
                continue;
            }

            $coord = $this->writer->coordFor((string) $menu->id, $name);
            $existing = $byCoord[$coord] ?? null;
            $scanCategory = $this->cleanString($item['category'] ?? null);

            if ($existing === null) {
                $entries = [$this->categoryEntry(
                    $scanCategory ?? self::DEFAULT_CATEGORY_NAME, $source, $positionByRef, $nextCategoryPosition,
                )];
                $dish = $this->newDish($name, $item);
                $platformRows = [];
                $added++;
            } else {
                // An owner-authored dish outranks scan enrichment — leave it
                // untouched, and don't create a scan duplicate either (the
                // owner's row already represents this dish).
                if (isset($locked[(string) $existing->id])) {
                    continue;
                }

                $entries = $entriesByCoord[$coord] ?? $this->categoryEntries($existing, $categoriesById);
                if ($entries === null) {
                    continue;
                }

                // Multi-category attach: the scan listing a KNOWN dish under a
                // category it isn't in yet grows its memberships. Skipped when
                // the scan named no category, and satisfied by ANY same-named
                // existing membership (a scraped "Sides" already covers a scan's
                // "sides" — never shadow a scraped category with a scan-owned
                // duplicate).
                if ($scanCategory !== null
                    && $this->shouldAttachOnMatch($entries, $enrichOnly)
                    && ! $this->alreadyListed($entries, $scanCategory)) {
                    $entries[] = $this->categoryEntry($scanCategory, $source, $positionByRef, $nextCategoryPosition);
                }

                $dish = $this->mergedDish($existing, $item, $enrichOnly);
                $platformRows = $existing->platforms;
                $updated++;
            }

            $projection = $this->writer->projectionFor($dish, [], $platformRows, $menu);
            // Categories first, then the mapper's own order_platform entries —
            // the legacy projection's order, and collection_items.position is
            // assigned from this list's indices.
            $projection['collections'] = [...$entries, ...$projection['collections']];

            $itemId = $this->writer->write($userId, $coord, $projection);

            // The row this apply just wrote joins the lookup pool, so a LATER
            // entry in this SAME batch sharing the name attaches its category to
            // THIS dish instead of minting a second one — the multi-category
            // dedupe this applier exists for.
            $byCoord[$coord] = $this->writtenRow($itemId, $coord, $dish, $platformRows);
            $entriesByCoord[$coord] = $entries;
        }

        // Scan-applied items/prices show on the public menu page — bust the edge
        // cache when the scan actually changed something. Skip a no-op scan.
        //
        // BOTH calls, on purpose: touchSite() reaches SiteObserver, which is the
        // only path that forgets the Redis public-site payload and re-warms it;
        // invalidate() fires the three raw-write lanes (parent spec §4), of
        // which BuildState::bump is the one no observer does. They overlap on
        // the Cloudflare purge, so a scan dispatches that job twice — accepted
        // rather than dropping either half, since the purge is idempotent and a
        // scan apply is a rare owner-initiated action.
        if ($updated > 0 || $added > 0) {
            $this->invalidator->touchSite(fn () => $user->site, 'menu-scan-apply', ['user_id' => $userId]);

            $siteId = DB::connection('pgsql')->table('site.sites')->where('user_id', $userId)->value('id');
            if ($siteId !== null) {
                $this->writer->invalidate([(string) $siteId]);
            }
        }

        return ['updated' => $updated, 'added' => $added];
    }

    /**
     * Dishes the scan must not touch — the content.* successor to
     * `menu_items.is_manual`.
     *
     * `content.manual_overrides` is this codebase's declared replacement for
     * that flag (see App\Models\Content\ManualOverride): editing a field freezes
     * THAT field. A dish carrying any override is one the owner has edited by
     * hand, which is exactly what the legacy `is_manual` skip protected. Today
     * nothing writes menu overrides yet, so this correctly locks nothing —
     * Task 6 is what starts feeding it.
     *
     * @return array<string, true> item id => locked
     */
    private function lockedItemIds(string $userId): array
    {
        $ids = DB::connection('pgsql')->table('content.manual_overrides as mo')
            ->join('content.items as i', 'i.id', '=', 'mo.item_id')
            ->where('i.user_id', $userId)
            ->where('i.kind', 'menu_item')
            ->distinct()
            ->pluck('mo.item_id');

        $out = [];
        foreach ($ids as $id) {
            $out[(string) $id] = true;
        }

        return $out;
    }

    /**
     * A matched dish's EXISTING category memberships, as projection entries —
     * carrying each collection's own external_ref so re-projecting re-addresses
     * the same rows instead of minting parallel ones.
     *
     * Returns null when a membership's collection has no external_ref: the
     * projection addresses collections by ref only, so such a membership cannot
     * be re-expressed and would be silently deleted by replaceCollections().
     * A null here makes the caller skip the dish entirely — losing an
     * enrichment is recoverable, losing the owner's category is not. No such
     * row exists today (all 50 dev menu_category collections carry a ref);
     * Task 6 keeps it that way by minting through categoryRefFor().
     *
     * @param  array<string, \stdClass>  $categoriesById
     * @return list<array<string, mixed>>|null
     */
    private function categoryEntries(object $row, array $categoriesById): ?array
    {
        $entries = [];

        foreach ($row->category_ids as $id) {
            $category = $categoriesById[(string) $id] ?? null;
            if ($category === null) {
                continue;
            }
            $ref = $this->cleanString($category->external_ref);
            if ($ref === null) {
                return null;
            }
            $entries[] = [
                'kind' => 'menu_category',
                'external_ref' => $ref,
                'label' => (string) $category->label,
                'position' => (int) $category->position,
            ];
        }

        return $entries;
    }

    /**
     * The scan-owned category entry for a label. `position` is INSERT-ONLY on a
     * collection, so an existing one keeps the position it was seeded with and
     * only a genuinely new category consumes the next slot.
     *
     * @param  array<string, int>  $positionByRef
     * @return array<string, mixed>
     */
    private function categoryEntry(string $label, string $source, array &$positionByRef, int &$nextPosition): array
    {
        $ref = self::categoryRefFor($source, $label);
        $positionByRef[$ref] ??= $nextPosition++;

        return [
            'kind' => 'menu_category',
            'external_ref' => $ref,
            'label' => $label,
            'position' => $positionByRef[$ref],
        ];
    }

    /**
     * Whether a MATCHED item should also gain the scan's category membership.
     * Manual scans always attach (the user deliberately scanned this menu's
     * structure). Enrich-only (automatic) scans attach only to owner-created
     * dishes — a dish with any scraped membership keeps its scraped structure.
     *
     * @param  list<array<string, mixed>>  $entries
     */
    private function shouldAttachOnMatch(array $entries, bool $enrichOnly): bool
    {
        if (! $enrichOnly) {
            return true;
        }

        foreach ($entries as $entry) {
            if (! self::isOwnerCategoryRef((string) $entry['external_ref'])) {
                return false;
            }
        }

        return true;
    }

    /** @param  list<array<string, mixed>>  $entries */
    private function alreadyListed(array $entries, string $label): bool
    {
        $key = $this->normalize($label);

        foreach ($entries as $entry) {
            if ($this->normalize((string) $entry['label']) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Matched-dish merge. Manual mode (default) applies what the scan says
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
     * Every OTHER column is carried through unchanged from the stored row. That
     * is not defensive copying — the projection replaces this dish's offers,
     * media and tags wholesale, so anything omitted here is deleted.
     *
     * The headline is the STORED one, never the scan's: two spellings of one
     * normalised name are the same dish, and the scan does not get to rename it.
     *
     * @param  array{name:string, description:?string, price:?float, category:?string, dietary?:?list<string>}  $item
     */
    private function mergedDish(object $row, array $item, bool $enrichOnly): object
    {
        $description = $this->cleanString($item['description'] ?? null);
        $descriptionSaysMore = $description !== null
            && mb_strlen($description) > mb_strlen((string) $row->description);
        $takeDescription = $description !== null && ($enrichOnly ? $descriptionSaysMore : true);

        $price = $item['price'] ?? null;
        $takePrice = $price !== null && (! $enrichOnly || $row->base_price === null);

        return (object) [
            'name' => (string) $row->headline,
            'description' => $takeDescription ? $description : $row->description,
            'base_price' => $takePrice ? $price : $row->base_price,
            'pickup_price' => $row->pickup_price,
            'delivery_price' => $row->delivery_price,
            'currency' => $row->currency,
            'image_url' => $row->image_url,
            'images' => $row->images,
            'rating' => $row->rating,
            'rating_count' => $row->rating_count,
            'badges' => $this->mergeDietaryBadges($row->badges, $item['dietary'] ?? null) ?? $row->badges,
        ];
    }

    /**
     * @param  array{name:string, description:?string, price:?float, category:?string, dietary?:?list<string>}  $item
     */
    private function newDish(string $name, array $item): object
    {
        return (object) [
            'name' => $name,
            'description' => $this->cleanString($item['description'] ?? null),
            'base_price' => $item['price'] ?? null,
            'pickup_price' => null,
            'delivery_price' => null,
            // Left null so the mapper falls back to the menu's currency rather
            // than stamping a guess onto the offers.
            'currency' => null,
            'image_url' => null,
            'images' => [],
            'rating' => null,
            'rating_count' => null,
            // Scan-found dietary markers badge new items too.
            'badges' => $this->mergeDietaryBadges(null, $item['dietary'] ?? null) ?? [],
        ];
    }

    /**
     * The just-written dish in ManualMenuItems' own row shape, so a later entry
     * in the same batch reads it exactly as it would have read a stored one.
     * Built in memory rather than re-read: the write is authoritative and a
     * round trip per dish would cost a query per facet table.
     *
     * @param  list<\stdClass>  $platformRows
     */
    private function writtenRow(string $itemId, string $coord, object $dish, array $platformRows): \stdClass
    {
        return (object) [
            'id' => $itemId,
            'coord' => $coord,
            'headline' => $dish->name,
            'description' => $dish->description,
            'base_price' => $dish->base_price,
            'pickup_price' => $dish->pickup_price,
            'delivery_price' => $dish->delivery_price,
            'currency' => $dish->currency,
            'image_url' => $dish->image_url,
            'images' => $dish->images,
            'rating' => $dish->rating,
            'rating_count' => $dish->rating_count,
            'badges' => $dish->badges,
            'category_ids' => [],
            'platforms' => $platformRows,
        ];
    }

    /**
     * Existing badges + scanned dietary labels, or null when nothing new —
     * badge shape mirrors the menu drivers' {text, type?} rows, with scan
     * rows typed 'dietary'.
     *
     * The type half does NOT survive the projection (content.item_tags spends
     * its one classification column on tag_type='badge'), so it is written for
     * the mapper's benefit and reads back absent. See MenuProjectionMapper
     * ::badges().
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

    /**
     * The user's menu row, creating a scan-sourced one when none exists yet.
     * site.menus survives slice 7's teardown and is still the coord's
     * namespace, so this is unchanged.
     *
     * last_fetched_at is stamped on every apply (create or update) —
     * SitepageDataResolverService gates menu visibility on it being non-null,
     * and a scan-only menu never gets a real scrape to set it otherwise.
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

    /** lowercase + trim — the brief's literal category match rule. */
    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
