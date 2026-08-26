<?php

namespace App\Services\Content;

use App\Models\Core\Site\MenuCategory;
use App\Models\Core\Site\MenuItem;
use App\Models\Core\Site\MenuItemPlatform;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Slice 7 unit A, read side: MenuProjectionMapper run backwards. The mapper
 * folded one legacy `site.menu_items` row into content.items + offers + tags +
 * media + collections; this folds it back into the legacy shape the dashboard
 * still reads, so `MenuPayloadComposer` and the 10 owner verbs keep emitting
 * today's payload after the write lane moves (spec unit A, "Wire shape").
 *
 * The services twin is ManualServiceItems and this follows its query shape —
 * one builder over content.source_items → content.sources scoped to the user's
 * MANUAL source, with the facets batch-loaded per page of rows. It carries no
 * `sectionId` parameter, because unlike services the dashboard menu is grouped
 * by category rather than by the pool's curation order; pins still exist
 * (ManualMenuWriter::pin()) and are the public pool's business, not this one's.
 *
 * Three legacy columns are NOT recoverable and are documented rather than
 * guessed: `pickup_source`/`delivery_source` and `dd_external_id`
 * have no projection target (MenuProjectionMapper never wrote them). Spec unit
 * A gives `is_manual` a home on `site.menus`, which survives the teardown —
 * Task 6 wires it, and this class deliberately leaves it false rather than
 * inventing a value.
 */
class ManualMenuItems
{
    /**
     * One row per live menu_item, folded to the legacy columns.
     *
     * @return Collection<int, \stdClass> id, coord, headline, description,
     *                                    base_price, pickup_price, delivery_price, currency,
     *                                    image_url, images, rating, rating_count, badges,
     *                                    category_ids, category_positions, platforms,
     *                                    removed_at, created_at, updated_at, source_id
     */
    public function rows(string $userId, bool $includeRemoved = false): Collection
    {
        $query = $this->baseQuery($userId);
        if (! $includeRemoved) {
            $query->whereNull('i.removed_at');
        }

        return $this->hydrate($userId, $query->orderBy('i.headline_cache')->distinct()->get($this->columns()));
    }

    /** Single dish, scoped to its owner — 404 material for the controller when null. */
    public function find(string $userId, string $itemId, bool $includeRemoved = false): ?object
    {
        $query = $this->baseQuery($userId)->where('i.id', $itemId);
        if (! $includeRemoved) {
            $query->whereNull('i.removed_at');
        }

        return $this->hydrate($userId, $query->distinct()->get($this->columns()))->first();
    }

    /**
     * The owner's menu categories — content.collections kind `menu_category`.
     *
     * `position` is INSERT-ONLY on a collection (parent §19: a scheduled run
     * must never snap an owner's reorder back), so it is the seeded vendor
     * order and label is the tie-break, matching site.menu_categories' own
     * (position, name) read order.
     *
     * @return Collection<int, \stdClass> id, label, position, external_ref
     */
    public function categories(string $userId): Collection
    {
        return DB::connection('pgsql')->table('content.collections')
            ->where('user_id', $userId)
            ->where('kind', 'menu_category')
            ->whereNull('removed_at')
            ->orderBy('position')
            ->orderBy('label')
            ->get(['id', 'label', 'position', 'external_ref']);
    }

    /**
     * An UNSAVED, legacy-shaped MenuItem — the shim that keeps the dashboard's
     * 10 verbs and MenuPayloadComposer working across the cutover, exactly as
     * ManualServiceItems::toServiceModel() did for services.
     *
     * `platformLinks` and `categories` are PRE-SET, never lazily loaded: their
     * tables are dropped in Phase 5, and a lazy relation would keep passing
     * this suite and start throwing in production the day they go.
     */
    public function toMenuItemModel(object $row): MenuItem
    {
        $item = new MenuItem;
        $item->exists = false;
        $item->id = (string) $row->id;
        $item->name = (string) ($row->headline ?? '');
        $item->description = $row->description;
        $item->image_url = $row->image_url;
        $item->images = $row->images === [] ? null : $row->images;
        $item->rating = $row->rating;
        $item->rating_count = $row->rating_count;
        $item->badges = $row->badges === [] ? null : $row->badges;
        $item->base_price = $row->base_price;
        $item->pickup_price = $row->pickup_price;
        $item->delivery_price = $row->delivery_price;
        $item->currency = $row->currency;
        // Not recoverable from the projection — see the class docblock. Left at
        // the column's own default rather than guessed.
        $item->pickup_source = null;
        $item->delivery_source = null;
        $item->dd_external_id = null;
        $item->is_manual = (bool) ($row->is_manual ?? false);
        $item->created_at = $row->created_at !== null ? Carbon::parse($row->created_at) : null;
        $item->updated_at = $row->updated_at !== null ? Carbon::parse($row->updated_at) : null;

        $item->setRelation('platformLinks', collect($row->platforms)->map(function (object $entry) use ($row) {
            $link = new MenuItemPlatform;
            $link->exists = false;
            $link->menu_item_id = (string) $row->id;
            $link->platform = $entry->platform;
            $link->pickup_price = $entry->pickup_price;
            $link->delivery_price = $entry->delivery_price;
            $link->item_url = $entry->item_url;
            $link->external_ref = $entry->external_ref;
            $link->sold_out = $entry->sold_out;

            return $link;
        })->values());

        $item->setRelation('categories', collect($row->category_ids)->map(function (string $id) use ($row) {
            $category = new MenuCategory;
            $category->exists = false;
            $category->id = $id;
            $category->name = (string) ($row->category_labels[$id] ?? '');
            $category->position = (int) ($row->category_positions[$id] ?? 0);

            return $category;
        })->values());

        return $item;
    }

    /** The manual lane only — Task 7 lands scraped dishes here too (spec unit A). */
    private function baseQuery(string $userId): Builder
    {
        return DB::connection('pgsql')->table('content.items as i')
            ->join('content.source_items as si', 'si.item_id', '=', 'i.id')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->where('i.user_id', $userId)
            ->where('i.kind', 'menu_item')
            ->whereNull('si.removed_at')
            ->where('cs.kind', 'manual');
    }

    /** @return list<string> */
    private function columns(): array
    {
        return ['i.id', 'i.headline_cache', 'i.removed_at', 'i.created_at', 'i.updated_at', 'si.coord', 'cs.id as source_id'];
    }

    /**
     * Fold each item's facets onto the legacy columns. Batched: one query per
     * facet table for the whole page, not one per row.
     *
     * @param  Collection<int, \stdClass>  $items
     * @return Collection<int, \stdClass>
     */
    private function hydrate(string $userId, Collection $items): Collection
    {
        if ($items->isEmpty()) {
            return collect();
        }

        $itemIds = $items->pluck('id')->map(fn ($id) => (string) $id)->all();
        // Every row's source_id is the SAME manual source (idx_content_sources_manual
        // is unique per user) — any row's value identifies it for the facet lookups.
        $sourceId = (string) $items->first()->source_id;

        $descriptions = DB::connection('pgsql')->table('content.f_text')
            ->whereIn('item_id', $itemIds)->where('source_id', $sourceId)
            ->pluck('body', 'item_id');

        $rated = DB::connection('pgsql')->table('content.f_rated')
            ->whereIn('item_id', $itemIds)->where('source_id', $sourceId)
            ->get(['item_id', 'rating', 'ratings_count'])->keyBy('item_id');

        $offers = DB::connection('pgsql')->table('content.offers')
            ->whereIn('item_id', $itemIds)->where('source_id', $sourceId)
            ->get(['item_id', 'channel', 'amount_minor', 'currency', 'qualifier', 'url', 'platform', 'item_url', 'external_ref', 'availability'])
            ->groupBy('item_id');

        // Alphabetical because content.item_tags carries no ordinal column at
        // all — the vendor's badge order did not survive the projection, and an
        // arbitrary-but-stable order beats one that differs run to run.
        // The content-lane `is_manual` (slice 7 Task 6): a dish the owner has
        // authored at least one column of carries content.manual_overrides
        // rows. NOT source-scoped — an override is the owner's, not a
        // projection's, and content.manual_overrides has no source_id.
        $ownerEdited = DB::connection('pgsql')->table('content.manual_overrides')
            ->whereIn('item_id', $itemIds)
            ->distinct()
            ->pluck('item_id')
            ->map(fn ($id) => (string) $id)
            ->flip();

        $badges = DB::connection('pgsql')->table('content.item_tags')
            ->whereIn('item_id', $itemIds)->where('source_id', $sourceId)
            ->where('tag_type', 'badge')
            ->orderBy('tag')
            ->get(['item_id', 'tag'])->groupBy('item_id');

        // source_url is the raw scraped URL the mapper handed ProjectionWriter,
        // which is exactly what menu_items.image_url / images held. The public
        // pool resolves the mirrored variant instead (PoolResolver); the
        // dashboard shim keeps the legacy value.
        $media = DB::connection('pgsql')->table('content.item_media as im')
            ->join('content.media_assets as ma', 'ma.id', '=', 'im.asset_id')
            ->whereIn('im.item_id', $itemIds)->where('im.source_id', $sourceId)
            ->whereIn('im.role', ['cover', 'gallery'])
            ->orderBy('im.position')
            ->get(['im.item_id', 'im.role', 'ma.source_url'])->groupBy('item_id');

        $memberships = $this->memberships($userId, $itemIds);
        $platformUrls = $this->storefrontHosts($userId);

        return $items->map(function (object $item) use ($descriptions, $rated, $offers, $badges, $media, $memberships, $platformUrls, $ownerEdited): \stdClass {
            $id = (string) $item->id;
            $itemOffers = $offers->get($id, collect());
            $categories = $memberships['menu_category'][$id] ?? [];
            $images = $this->imageUrls($media->get($id, collect()));
            $rating = $rated->get($id);

            return $this->row([
                'id' => $id,
                'coord' => (string) $item->coord,
                'source_id' => (string) $item->source_id,
                'is_manual' => $ownerEdited->has($id),
                'headline' => (string) ($item->headline_cache ?? ''),
                'description' => $descriptions[$id] ?? null,
                'base_price' => $this->priceFor($itemOffers, 'base'),
                'pickup_price' => $this->priceFor($itemOffers, 'pickup'),
                'delivery_price' => $this->priceFor($itemOffers, 'delivery'),
                'currency' => $this->currencyFor($itemOffers),
                'image_url' => $images[0] ?? null,
                'images' => $images,
                'rating' => ($rating->rating ?? null) === null ? null : (float) $rating->rating,
                'rating_count' => ($rating->ratings_count ?? null) === null ? null : (int) $rating->ratings_count,
                // The legacy column is a list of {text, type?} maps; the
                // projection keeps only the text (item_tags.tag_type is the
                // constant 'badge', not the badge's own type), so the type half
                // is genuinely gone rather than defaulted to something wrong.
                'badges' => $badges->get($id, collect())->map(fn ($row) => ['text' => (string) $row->tag])->values()->all(),
                'category_ids' => array_map(fn (object $c) => (string) $c->collection_id, $categories),
                'category_labels' => $this->labelsBy($categories),
                'category_positions' => $this->positionsBy($categories),
                'platforms' => $this->platforms($itemOffers, $memberships['order_platform'][$id] ?? [], $platformUrls),
                'removed_at' => $item->removed_at,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ]);
        })->values();
    }

    /**
     * One folded row. A named factory rather than an inline cast so the rows
     * stay plain \stdClass — every consumer reads them dynamically, and the
     * anonymous object shape an inline cast infers is not a Collection<\stdClass>.
     *
     * @param  array<string, mixed>  $data
     */
    private function row(array $data): \stdClass
    {
        return (object) $data;
    }

    /**
     * Each item's LIVE collection memberships, split by kind and in position
     * order. Both menu categories and ordering platforms are collections — only
     * `kind` keeps them apart, and an unscoped read would hand the owner a
     * "DoorDash" menu category.
     *
     * @param  list<string>  $itemIds
     * @return array<string, array<string, list<\stdClass>>> kind => item_id => memberships
     */
    private function memberships(string $userId, array $itemIds): array
    {
        $rows = DB::connection('pgsql')->table('content.collection_items as ci')
            ->join('content.collections as c', 'c.id', '=', 'ci.collection_id')
            ->whereIn('ci.item_id', $itemIds)
            ->where('c.user_id', $userId)
            ->whereIn('c.kind', ['menu_category', 'order_platform'])
            ->whereNull('c.removed_at')
            ->orderBy('ci.position')
            ->orderBy('c.label')
            ->get(['ci.item_id', 'ci.collection_id', 'ci.position', 'c.kind', 'c.label', 'c.external_ref']);

        $out = ['menu_category' => [], 'order_platform' => []];
        foreach ($rows as $row) {
            $out[(string) $row->kind][(string) $row->item_id][] = $row;
        }

        return $out;
    }

    /**
     * Ordering-platform slug => the store URL's host, from the `order_platform`
     * collections' storefront sidecars.
     *
     * This is what re-pairs a url-bearing offer with its platform: the
     * projection keeps the deep link but drops the platform label (see
     * platforms() below), and the item's deep link is derived from the store
     * link, so they share a host.
     *
     * @return array<string, string> platform slug => host
     */
    private function storefrontHosts(string $userId): array
    {
        $rows = DB::connection('pgsql')->table('content.collections as c')
            ->join('content.storefronts as sf', 'sf.collection_id', '=', 'c.id')
            ->where('c.user_id', $userId)
            ->where('c.kind', 'order_platform')
            ->whereNotNull('sf.url')
            ->get(['c.external_ref', 'sf.url']);

        $out = [];
        foreach ($rows as $row) {
            $host = $this->hostOf((string) $row->url);
            $ref = (string) $row->external_ref;
            if ($host !== null && str_starts_with($ref, 'order:')) {
                $out[substr($ref, 6)] = $host;
            }
        }

        return $out;
    }

    /**
     * Rebuild the legacy `site.menu_item_platforms` list: one entry per
     * ordering platform the dish is sold on, with that platform's pickup and
     * delivery price + url.
     *
     * The platform SET is exact — it is the dish's `order_platform` memberships,
     * which MenuProjectionMapper::platformCollections() wrote from those very
     * rows. Which url belongs to which platform is the lossy half: the mapper
     * carries the url onto the offer but not the platform label, so it is
     * recovered by host, against the store URL each platform's storefront
     * sidecar holds. A dish on ONE platform needs no such match, which is every
     * dish on four of the five live menus.
     *
     * When a dish sells on two platforms and neither storefront is present, the
     * urls cannot be attributed and the entries keep their platform but carry
     * no link — deliberately, because guessing would point a DoorDash button at
     * Uber Eats.
     *
     * @param  Collection<int, \stdClass>  $offers
     * @param  list<\stdClass>  $platformMemberships
     * @param  array<string, string>  $hosts
     * @return list<\stdClass>
     */
    private function platforms(Collection $offers, array $platformMemberships, array $hosts): array
    {
        $slugs = [];
        foreach ($platformMemberships as $membership) {
            $ref = (string) ($membership->external_ref ?? '');
            if (str_starts_with($ref, 'order:')) {
                $slugs[] = substr($ref, 6);
            }
        }
        if ($slugs === []) {
            return [];
        }

        // A per-platform offer carries `platform` (post-2026-08-26 writes) or
        // a url (pre-migration rows, host-attributed below until the next
        // wholesale scrape rebuild replaces them).
        $linked = $offers->filter(fn (object $offer) => $this->text($offer->platform ?? null) !== null
            || $this->text($offer->url ?? null) !== null);

        $out = [];
        foreach ($slugs as $slug) {
            $entry = (object) [
                'platform' => $slug,
                'pickup_price' => null,
                'delivery_price' => null,
                'item_url' => null,
                'external_ref' => null,
                'sold_out' => null,
            ];

            foreach ($linked as $offer) {
                if (! $this->offerBelongsTo($offer, $slug, $slugs, $hosts)) {
                    continue;
                }

                $channel = (string) ($offer->channel ?? '');
                if ($channel === 'pickup' || $channel === 'delivery') {
                    $entry->{$channel.'_price'} = $this->amount($offer);
                }
                $entry->item_url ??= $this->text($offer->item_url ?? null);
                $entry->external_ref ??= $this->text($offer->external_ref ?? null);
                $entry->sold_out ??= match ($offer->availability ?? null) {
                    'out_of_stock' => true,
                    'in_stock' => false,
                    default => null,
                };
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Host match first; a lone platform takes every url as a fallback, since
     * there is nothing to confuse it with.
     *
     * @param  list<string>  $slugs
     * @param  array<string, string>  $hosts
     */
    private function offerBelongsTo(object $offer, string $slug, array $slugs, array $hosts): bool
    {
        // Stored attribution wins outright — no heuristic when the projection
        // kept the label (every write after 2026-08-26).
        $stored = $this->text($offer->platform ?? null);
        if ($stored !== null) {
            return $stored === $slug;
        }

        $host = $this->hostOf((string) $offer->url);
        if ($host !== null && isset($hosts[$slug]) && $hosts[$slug] === $host) {
            return true;
        }

        // Some other platform's storefront claims this host — never fall
        // through to the single-platform arm, which cannot fire here anyway.
        if ($host !== null && in_array($host, array_intersect_key($hosts, array_flip($slugs)), true)) {
            return false;
        }

        return count($slugs) === 1;
    }

    /**
     * The aggregate price for one channel, in legacy dollars. A `free`
     * qualifier is 0.0 and a channel with no offer stays NULL — the legacy
     * columns were independently nullable and a 0 there would advertise a free
     * pickup the store does not offer.
     *
     * @param  Collection<int, \stdClass>  $offers
     */
    private function priceFor(Collection $offers, string $channel): ?float
    {
        // The aggregate three carry neither a platform label nor a url; the
        // per-platform ones carry `platform` (post-2026-08-26 writes) or a
        // url (pre-migration rows). Same channel string, so those two fields
        // are what separate them (see the mapper's offers()).
        $offer = $offers->first(fn (object $row) => (string) ($row->channel ?? '') === $channel
            && $this->text($row->platform ?? null) === null
            && $this->text($row->url ?? null) === null);

        return $offer === null ? null : $this->amount($offer);
    }

    private function amount(object $offer): ?float
    {
        if (($offer->qualifier ?? null) === 'free') {
            return 0.0;
        }

        return ($offer->amount_minor ?? null) === null ? null : ((int) $offer->amount_minor) / 100;
    }

    /**
     * One menu is one vendor in one country, so every offer shares a currency;
     * the first non-null is the honest answer and null stays null (93 dishes
     * carry none — see MenuProjectionMapper::project()).
     *
     * @param  Collection<int, \stdClass>  $offers
     */
    private function currencyFor(Collection $offers): ?string
    {
        $offer = $offers->first(fn (object $row) => $this->text($row->currency ?? null) !== null);

        return $offer === null ? null : $this->text($offer->currency);
    }

    /**
     * Hero first, then the gallery — the mapper's own ordering (cover at
     * position 0), which is what `menu_items.images` held.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return list<string>
     */
    private function imageUrls(Collection $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $url = $this->text($row->source_url ?? null);
            if ($url !== null && ! in_array($url, $out, true)) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /**
     * @param  list<\stdClass>  $categories
     * @return array<string, string>
     */
    private function labelsBy(array $categories): array
    {
        $out = [];
        foreach ($categories as $category) {
            $out[(string) $category->collection_id] = (string) $category->label;
        }

        return $out;
    }

    /**
     * The item's ordinal within its OWN collection list — NOT a display
     * position, and specifically NOT `site.menu_item_categories.position`.
     *
     * Corrected slice 7 Task 5, verified on dev: the legacy column spans 0..32
     * (33 distinct values over 402 rows) while `content.collection_items`
     * carries only 0/1/2 for `menu_category`, 348 of 402 at 0. ProjectionWriter
     * ::projectStream() writes it as a counter over the projection's own
     * collection array, so a dish in one category is always 0 and the vendor's
     * rank within a category is not in this table at all.
     *
     * Dish ORDER lives in the `pool:menus` pins (site.section_items.sort_key),
     * which ProvisionMenuPinsCommand seeded from the legacy order for exactly
     * this reason; MenuPayloadComposer::pinOrder() is the reader.
     *
     * @param  list<\stdClass>  $categories
     * @return array<string, int>
     */
    private function positionsBy(array $categories): array
    {
        $out = [];
        foreach ($categories as $category) {
            $out[(string) $category->collection_id] = (int) $category->position;
        }

        return $out;
    }

    private function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value) === '' ? null : trim($value);
    }
}
