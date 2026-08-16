<?php

namespace App\Services\Platforms;

use Illuminate\Support\Str;

/**
 * One legacy site.menu_items row → the projection shape ProjectionWriter
 * accepts. Pure: no database, no side effects, so slice 4's whole mapping
 * table is unit-testable without a schema.
 *
 * The coord is NOT manual:{legacy_uuid}, and that is deliberate. Parent §8.1
 * prescribes the uuid only "where the legacy id is stable"; MenuFetchJob
 * deletes and re-inserts every dish each scrape, then reuses the old uuid
 * through takeReusedId() keyed on the NORMALISED NAME. So the uuid survives an
 * unchanged dish and churns when the vendor renames one — a uuid coord would
 * mint a second content item on every rename and strand the first. The
 * normalised name IS the legacy writer's own identity key, and it matches
 * MenuRecords::flatten()'s sha1(category|name) fallback that the real
 * connectors emit, so a backfilled row and a scraped row describe identity the
 * same way.
 */
class MenuProjectionMapper
{
    use NormalizesMenuItemNames;

    /** Menu-scoped so two vendors' "Garlic Bread" stay distinct items. */
    public static function coordFor(string $menuId, string $name): string
    {
        return 'manual:menu:'.$menuId.':'.sha1((new self)->normalizeName($name));
    }

    /**
     * The `content.collections.external_ref` for a menu category — derived from
     * the LABEL, and shared with MenuItemProjector so the backfilled menu and
     * the scraped one describe the same category the same way. The natural key
     * is (user_id, kind, external_ref); two derivations would hand the owner
     * their categories twice, once per lane.
     *
     * Not the legacy category uuid, despite parent §8.1's usual preference:
     * MenuFetchJob rebuilds categories the same way it rebuilds dishes and
     * reuses their ids by NORMALISED NAME (idsByNormalizedName), so a renamed
     * category already gets a fresh uuid. The uuid is therefore no more
     * rename-stable than the label, and only the label is available on the
     * connector side — MenuRecords carries no category id at all.
     *
     * Cost, stated: a vendor rename mints a new collection rather than being
     * followed by the upsert's `label` update. That is the same behaviour the
     * legacy lane has today, and the alternative (a null ref) is what
     * FreshaServiceProjector rejected — it would insert a fresh row every run.
     *
     * A label that slugifies to nothing (all emoji, all punctuation) falls back
     * to a hash, because a shared `menu:` would fold every such category into
     * one.
     */
    public static function categoryRef(string $label): string
    {
        $slug = Str::slug($label);

        return 'menu:'.($slug !== '' ? $slug : sha1((new self)->normalizeName($label)));
    }

    /**
     * The `content.collections.external_ref` for an ordering platform (owner
     * ruling 2026-08-15, Unit 6). One collection per platform, carrying a
     * `content.storefronts` sidecar with the store URL — reusing slice 5b's
     * store-card shape rather than inventing a second grouping mechanism.
     *
     * The platform slug is already the vendor's stable identifier, so unlike a
     * category this ref never drifts.
     */
    public static function orderPlatformRef(string $platform): string
    {
        return 'order:'.$platform;
    }

    /**
     * @param  list<array{id: string, name: string, position: int}>  $categories
     * @param  list<object>  $platformRows  site.menu_item_platforms rows
     * @return array<string, mixed>
     */
    public function project(object $dish, array $categories, array $platformRows, object $menu): array
    {
        // A menu is one vendor in one country, so its currency is the honest
        // default for the 93 dishes carrying none. Null stays null — the column
        // permits it, and inventing AUD would be a guess about where they trade.
        $currency = $this->text($dish->currency ?? null) ?? $this->text($menu->currency ?? null);

        return [
            'kind' => 'menu_item',
            'headline' => (string) $dish->name,
            'facets' => $this->facets($dish),
            'offers' => $this->offers($dish, $platformRows, $currency),
            'tags' => $this->badges($dish),
            'media' => $this->media($dish),
            // Categories AND the ordering platforms this dish is sold on. Both
            // are collections; content.collections.kind is what keeps them
            // apart, and the natural key is (user_id, kind, external_ref), so
            // a category and a platform sharing a ref string cannot collide.
            'collections' => [
                ...$this->collections($categories),
                ...$this->platformCollections($platformRows),
            ],
        ];
    }

    /**
     * One order_platform collection per platform the dish carries a row for.
     * This is what makes a dish a MEMBER of its store card — the same edge
     * site.menu_item_platforms expresses.
     *
     * position 0 for every entry: ordering platforms have no vendor-supplied
     * order, and position is insert-only anyway, so the owner owns it from the
     * first run.
     *
     * @param  list<object>  $platformRows
     * @return list<array<string, mixed>>
     */
    private function platformCollections(array $platformRows): array
    {
        $out = [];
        $seen = [];

        foreach ($platformRows as $row) {
            $platform = $this->text($row->platform ?? null);
            if ($platform === null || isset($seen[$platform])) {
                continue;
            }
            $seen[$platform] = true;

            $out[] = [
                'kind' => 'order_platform',
                'external_ref' => self::orderPlatformRef($platform),
                // The label is the platform slug until the sidecar write gives
                // it a display name; upsertCollections() skips a blank label
                // entirely, so it cannot be left empty here.
                'label' => $platform,
                'position' => 0,
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function facets(object $dish): array
    {
        $description = $this->text($dish->description ?? null);

        // f_rated's two columns are independently nullable: DoorDash supplies a
        // thumbs-up count for dishes it carries no star average for, so a facet
        // holding only one of the pair is real data, not a half-written row.
        $rated = array_filter([
            'rating' => ($dish->rating ?? null) === null ? null : (float) $dish->rating,
            'ratings_count' => ($dish->rating_count ?? null) === null ? null : (int) $dish->rating_count,
        ], static fn ($v) => $v !== null);

        return array_filter([
            'f_text' => $description === null ? null : ['body' => $description],
            'f_rated' => $rated === [] ? null : $rated,
        ], static fn ($v) => $v !== null);
    }

    /**
     * The aggregate three (base / pickup / delivery, from the merged row) plus
     * one per platform per priced mode. content.offers is a SET and is never
     * resolved to a winner — two platforms at different prices are both true,
     * and hiding one would be a lie about where the visitor can buy.
     *
     * @param  list<object>  $platformRows
     * @return list<array<string, mixed>>
     */
    private function offers(object $dish, array $platformRows, ?string $currency): array
    {
        $offers = [];

        foreach (['base' => 'base_price', 'pickup' => 'pickup_price', 'delivery' => 'delivery_price'] as $channel => $column) {
            $amount = $dish->$column ?? null;
            if ($amount === null) {
                continue;
            }
            $offers[] = $this->offer($channel, (float) $amount, $currency, null);
        }

        foreach ($platformRows as $row) {
            foreach (['pickup' => ['pickup_price', 'pickup_url'], 'delivery' => ['delivery_price', 'delivery_url']] as $channel => [$priceColumn, $urlColumn]) {
                $amount = $row->$priceColumn ?? null;
                if ($amount === null) {
                    continue;
                }
                $offers[] = $this->offer($channel, (float) $amount, $currency, $this->text($row->$urlColumn ?? null));
            }
        }

        return $offers;
    }

    /** @return array<string, mixed> */
    private function offer(string $channel, float $amount, ?string $currency, ?string $url): array
    {
        return [
            'channel' => $channel,
            'amount_minor' => (int) round($amount * 100),
            'currency' => $currency,
            // offers_qualifier_check: exact|from|upto|range|free|variable|on_request.
            'qualifier' => $amount === 0.0 ? 'free' : 'exact',
            'url' => $url,
            // Menus carry no stock signal, so availability stays NULL rather
            // than minting a second spelling beside slice 5a's in_stock pair.
            'availability' => null,
        ];
    }

    /**
     * external_ref is the natural key (collections_user_kind_external_ref_uq);
     * a label is never a key, because vendors rename. A blank label is dropped
     * rather than written: offering_name_in_category derives from these
     * entries, and an empty one silently stops short dish names merging.
     *
     * @param  list<array{id: string, name: string, position: int}>  $categories
     * @return list<array<string, mixed>>
     */
    private function collections(array $categories): array
    {
        $out = [];

        foreach ($categories as $category) {
            $label = trim($category['name']);
            if ($label === '') {
                continue;
            }
            $out[] = [
                'kind' => 'menu_category',
                'external_ref' => self::categoryRef($label),
                'label' => $label,
                'position' => $category['position'],
            ];
        }

        return $out;
    }

    /**
     * A badge is EITHER a plain string or a `{text, type?}` map — the two shapes
     * `DoorDashMenuDriver::badges()` normalises to, and `MenuScanApplier`'s
     * dietary markers alongside them.
     *
     * Fixed 2026-08-16 (slice 7). This read `$this->text($badge)` alone, which
     * returns null for anything non-string, so every MAP-shaped badge was
     * silently dropped — and maps are what the scrapers actually emit. The 318
     * dishes slice 4 backfilled landed **zero** `tag_type = 'badge'` rows on dev
     * as a result. The unit tests missed it because they only ever passed the
     * string shape, which works.
     *
     * The vendor's `type` code (`most_liked_1`) is NOT carried: `item_tags` has
     * one classification column and `tag_type` already spends it distinguishing
     * badge from category/genre. `type` is optional styling metadata — nothing
     * backend-side reads it — so the display text is the half worth keeping.
     * Recorded in the wire manifest, because the dashboard's badge entries lose
     * that optional key.
     *
     * @return list<array<string, mixed>>
     */
    private function badges(object $dish): array
    {
        $out = [];

        foreach ($this->jsonList($dish->badges ?? null) as $badge) {
            $tag = is_array($badge)
                ? ($this->text($badge['text'] ?? null)
                    ?? $this->text($badge['name'] ?? null)
                    ?? $this->text($badge['label'] ?? null))
                : $this->text($badge);

            if ($tag !== null) {
                $out[] = ['tag' => $tag, 'tag_type' => 'badge'];
            }
        }

        return $out;
    }

    /**
     * Hero first, then the rest. image_url is the hero and the legacy writer
     * also puts it at the head of `images`, so the two overlap on nearly every
     * row — deduped by url rather than by position, since a drifted scrape can
     * order them either way.
     *
     * @return list<array<string, mixed>>
     */
    private function media(object $dish): array
    {
        $urls = [];

        $hero = $this->text($dish->image_url ?? null);
        if ($hero !== null) {
            $urls[] = $hero;
        }

        foreach ($this->jsonList($dish->images ?? null) as $entry) {
            $url = $this->text($entry);
            if ($url !== null && ! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        $out = [];
        foreach ($urls as $position => $url) {
            $out[] = ['role' => $position === 0 ? 'cover' : 'gallery', 'url' => $url, 'position' => $position];
        }

        return $out;
    }

    /**
     * The legacy columns are jsonb, and the bulk insert path writes them as
     * pre-encoded strings while the Eloquent path hands back arrays — so both
     * shapes reach here from the same table.
     *
     * @return list<mixed>
     */
    private function jsonList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value) === '' ? null : trim($value);
    }
}
