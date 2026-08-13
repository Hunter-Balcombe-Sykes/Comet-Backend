<?php

namespace App\Site\Pools;

use App\Models\Core\Site\Site;
use App\Services\Analytics\ContentPopularityReader;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Content\ContentItemSlugAllocator;
use App\Services\Media\MediaUrlResolver;
use App\Services\Platforms\DisplaySettingsFilter;
use App\Site\Sections\SectionCandidates;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The ONE pool read — live, no document cache (owner chose Option B:
 * "always as live as is possible"). The dashboard's pool page and the
 * public payload both call this, so what the owner curates and what a
 * visitor sees cannot be two different resolutions.
 *
 * Selection semantics (the pool contract, 2026-08-05):
 *   pins (hand-picks, drag order)  +  rule candidates (each auto-source's
 *   newest item)  −  excludes (removals). An excluded current-latest yields
 *   NOTHING from that source until something newer lands.
 *
 * Item payloads are render-ready: headline (manual override wins), primary
 * link + platform, creator, published, duration, cover thumbnail, the dated /
 * located / priced facets (null off events), the public URL slug + its 301
 * aliases, and the full per-platform link set (synced source links +
 * hand-saved item_links).
 * popularityRank carries a real rank for products (analytics.content_popularity_scores,
 * content_type='shop_product', keyed by f_catalog.handle — slice 5b, inherited
 * from the retiring /integrations wire); on every other kind it is null until
 * watch_item/listen_item beacons compute. The wire carries the field either
 * way so the shape doesn't change under the FE.
 */
class PoolResolver
{
    /**
     * THE public wire contract for a pool item — the #API-1 enforcement point
     * for this lane (spec §3.7).
     *
     * The legacy SHOP_PRODUCT_ALLOWLIST filtered a raw scraper blob with
     * array_intersect_key. That mechanism does not transfer: pool payloads are
     * built key-by-key from typed columns, so there is no blob to subtract from.
     * The equivalent guarantee comes from two halves — explicit construction in
     * itemPayloads(), and PoolWireShapeTest asserting this list is exactly what
     * ships. That fails on ADDITIONS too, which the legacy list never could.
     *
     * `selected` is stripped by buildPools() before the public wire; it is
     * listed here because this const describes the resolver's output, which the
     * dashboard also reads.
     *
     * @var list<string>
     */
    public const ITEM_KEYS = [
        'id', 'kind', 'slug', 'aliases', 'headline', 'headlineEdited', 'url',
        'platform', 'creator', 'publishedAt', 'firstSeenAt', 'durationSeconds',
        'thumbnail', 'frames', 'startsAt', 'startsAtLocal', 'endsAtLocal',
        'timezone', 'venue', 'locality', 'price', 'availability', 'links',
        'popularityRank', 'description', 'vendor', 'variants', 'collectionIds',
        'review', 'selected', 'origin',
    ];

    /** Public fields of one store card in a pool's `collections` map. */
    public const STORE_KEYS = [
        'externalRef', 'provider', 'url', 'name', 'currency',
        'favicon', 'logo', 'discountCode', 'position',
    ];

    /** Public fields of one product variant. */
    public const VARIANT_KEYS = ['label', 'sku', 'imageUrl', 'availability', 'price'];

    private const LIBRARY_LIMIT = 500;

    // Mirrors PublicMenuController::POPULARITY_CACHE_TTL_SECONDS verbatim
    // (CCG-102): the two read the SAME cache key, so a divergent TTL here
    // would halve the value of a single-flight cache that exists because this
    // read used to hit Postgres on every public request. Both track the
    // analytics:compute-popularity cadence (routes/console.php, 15 minutes).
    // PublicIntegrationController was the third holder of this constant until
    // slice 5b Task 8 retired its shop block along with the read.
    private const POPULARITY_CACHE_TTL_SECONDS = 900;

    public function __construct(
        private readonly PoolSectionProvisioner $provisioner,
        private readonly SectionCandidates $candidates,
        private readonly ContentItemSlugAllocator $slugs,
        private readonly MediaUrlResolver $mediaUrls,
        private readonly ContentPopularityReader $popularity,
        private readonly CacheLockService $cache,
    ) {}

    /**
     * Whether this pool's resolved selection has at least one item — the
     * page-presence probe (presence-via-pools, 2026-08-06). Same pins →
     * rule-candidates → excludes arithmetic as resolve(), without hydrating
     * a single payload, so the payload builder's presence gate can afford
     * to ask per pool.
     */
    public function hasSelection(Site $site, string $pool): bool
    {
        $section = $this->provisioner->ensure($site, $pool);

        $curation = DB::connection('pgsql')->table('site.section_items')
            ->where('section_id', $section->id)
            ->get();

        $excluded = $curation->where('state', 'excluded')
            ->pluck('item_id')->flip()->all();
        $pinned = $curation->where('state', 'pinned')
            ->sortBy('sort_key')->pluck('item_id')->values()->all();

        // The early return on a pin is LOAD-BEARING, not an optimisation: this
        // method is a presence probe (SitepageDataResolverService), and
        // answering from site.section_items alone is what lets a probe succeed
        // in an environment where content.* is absent. Collecting candidates
        // unconditionally instead makes every pool probe fault wherever the
        // services probe already does — 4 reported exceptions instead of 1,
        // which is what PresenceProbeEscalationTest and PresenceProbeLoggingTest
        // pin via pinPoolPresence(). Do not "simplify" the two branches into
        // one collect-then-filter pass.
        if (! in_array('review', PoolRegistry::kinds($pool), true)) {
            foreach ($pinned as $itemId) {
                if (! isset($excluded[$itemId])) {
                    return true;
                }
            }
            foreach ($this->candidates->ruleCandidates($section, $pinned) as $itemId) {
                if (! isset($excluded[$itemId])) {
                    return true;
                }
            }

            return false;
        }

        // Slice 6 §4.4: itemPayloads() drops review items whose connection has
        // reviews switched off, so this has to as well — this method decides
        // whether the page is ADVERTISED in nav and resolve() decides what is
        // behind it. Disagreeing means an owner who hid their reviews gets the
        // page linked with an empty pool behind it, the B2.2 pathology.
        //
        // A review pool cannot answer from pins alone: suppression is keyed to
        // the item's SOURCE, so the whole candidate set is needed before the
        // question can be answered at all. Reviews is deliberately absent from
        // the presence-probe loop above, so this branch never runs inside one.
        $candidates = [];
        foreach ([...$pinned, ...$this->candidates->ruleCandidates($section, $pinned)] as $itemId) {
            if (! isset($excluded[$itemId])) {
                $candidates[] = (string) $itemId;
            }
        }

        if ($candidates === []) {
            return false;
        }

        $suppressed = $this->reviewsSuppressedByOwner($candidates);

        foreach ($candidates as $itemId) {
            if (! isset($suppressed[$itemId])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{
     *   selection: list<array<string, mixed>>,
     *   library: list<array<string, mixed>>,
     *   latestItemId: string|null,
     *   collections: array<string, array<string, mixed>>,
     *   stats: array{ratingAvg: ?float, ratingCount: ?int, summaryText: ?string}|null,
     * }
     */
    public function resolve(Site $site, string $pool): array
    {
        $section = $this->provisioner->ensure($site, $pool);

        $curation = DB::connection('pgsql')->table('site.section_items')
            ->where('section_id', $section->id)
            ->get();

        $pinned = $curation->where('state', 'pinned')
            ->sortBy('sort_key')->pluck('item_id')->values()->all();
        $excluded = $curation->where('state', 'excluded')
            ->pluck('item_id')->flip()->all();

        $ruleIds = $this->candidates->ruleCandidates($section, $pinned);
        $autoSet = array_flip($ruleIds);

        $selectionIds = [];
        foreach ([...$pinned, ...$ruleIds] as $itemId) {
            if (! isset($excluded[$itemId])) {
                $selectionIds[] = $itemId;
            }
        }

        $libraryIds = DB::connection('pgsql')->table('content.items')
            ->where('user_id', $site->user_id)
            ->whereIn('kind', PoolRegistry::kinds($pool))
            ->whereNull('removed_at')
            ->orderByDesc('last_seen_at')
            ->limit(self::LIBRARY_LIMIT)
            ->pluck('id')
            ->all();

        // Tuple, not a private property: collectionsFor() needs the store rows
        // itemPayloads() already fetched, and stashing them on $this would make
        // resolve() order-dependent (and unsafe under a reused instance).
        [$payloads, $stores] = $this->itemPayloads(
            $site,
            array_values(array_unique([...$selectionIds, ...$libraryIds])),
        );

        $selectedSet = array_flip($selectionIds);

        $selection = [];
        foreach ($selectionIds as $itemId) {
            if (! isset($payloads[$itemId])) {
                continue;
            }
            $selection[] = [
                ...$payloads[$itemId],
                'selected' => true,
                'origin' => isset($autoSet[$itemId]) && ! in_array($itemId, $pinned, true)
                    ? 'auto'
                    : 'manual',
            ];
        }

        $library = [];
        foreach ($libraryIds as $itemId) {
            if (! isset($payloads[$itemId])) {
                continue;
            }
            $library[] = [
                ...$payloads[$itemId],
                'selected' => isset($selectedSet[$itemId]),
                'origin' => isset($autoSet[$itemId]) && isset($selectedSet[$itemId]) && ! in_array($itemId, $pinned, true)
                    ? 'auto'
                    : 'manual',
            ];
        }

        return [
            'selection' => $selection,
            'library' => $library,
            'latestItemId' => PoolRegistry::carriesLatestTag($pool)
                ? $this->latestItemId($selection)
                : null,
            'collections' => $this->collectionsFor($selection, $stores),
            'stats' => $this->statsFor($pool, $selection),
        ];
    }

    /**
     * Slice 6 §5.4: the connected place's own aggregates, for the pool that
     * renders them. These describe the SOURCE, not any one item, which is why
     * they sit beside the items rather than on one.
     *
     * Derived from the SELECTION's sources rather than from the user's, so the
     * owner's display toggle and any exclusion itemPayloads() already applied
     * govern the badge exactly as they govern the cards — an owner who
     * switched reviews off has an empty selection here and therefore no
     * rating. Serving a 4.8 for someone who hid their reviews would republish
     * the thing they hid, in summary form.
     *
     * @param  list<array<string, mixed>>  $selection
     * @return array{ratingAvg: ?float, ratingCount: ?int, summaryText: ?string}|null
     */
    private function statsFor(string $pool, array $selection): ?array
    {
        if (! PoolRegistry::carriesSourceStats($pool) || $selection === []) {
            return null;
        }

        $row = DB::connection('pgsql')->table('content.source_stats as ss')
            ->join('content.source_items as si', 'si.source_id', '=', 'ss.source_id')
            ->whereIn('si.item_id', array_column($selection, 'id'))
            // Two connected places would be unusual, but the busiest listing is
            // the defensible one to show and ordering makes that a decision
            // rather than whatever row Postgres returned first.
            ->orderByDesc('ss.rating_count')
            ->first(['ss.rating_avg', 'ss.rating_count', 'ss.summary_text']);

        if ($row === null) {
            return null;
        }

        // Null-preserving: f_review.rating's bare-cast trap applies here too —
        // (float) null is 0.0, which would publish a zero-star business.
        return [
            'ratingAvg' => $row->rating_avg === null ? null : (float) $row->rating_avg,
            'ratingCount' => $row->rating_count === null ? null : (int) $row->rating_count,
            'summaryText' => $row->summary_text,
        ];
    }

    /**
     * The single Latest tag (owner): whichever SELECTED item was most
     * recently released — published date, first-seen when nothing dated it.
     *
     * @param  list<array<string, mixed>>  $selection
     */
    private function latestItemId(array $selection): ?string
    {
        $latest = null;
        $latestAt = null;
        foreach ($selection as $item) {
            $at = $item['publishedAt'] ?? $item['firstSeenAt'] ?? null;
            if ($at !== null && ($latestAt === null || $at > $latestAt)) {
                $latestAt = $at;
                $latest = $item['id'];
            }
        }

        return $latest;
    }

    /**
     * Render-ready payloads for a set of items, owner-scoped, one query per
     * facet table — never one per item.
     *
     * Returns a tuple: the payloads keyed by item id, and the storefront rows
     * those payloads reference (keyed by collection id) so resolve() can build
     * the sibling collections map without re-querying.
     *
     * @param  list<string>  $ids
     * @return array{array<string, array<string, mixed>>, Collection<string, object>}
     */
    private function itemPayloads(Site $site, array $ids): array
    {
        if ($ids === []) {
            return [[], collect()];
        }

        $items = DB::connection('pgsql')->table('content.items')
            ->whereIn('id', $ids)
            ->where('user_id', $site->user_id)
            ->whereNull('removed_at')
            ->get()
            ->keyBy('id');

        $ids = $items->keys()->all();
        if ($ids === []) {
            return [[], collect()];
        }

        // Shop-only reads. Gated on the resolved set actually containing a
        // product, so watch / listen / media / events add no queries — this
        // sits behind the 60s payload cache on the public path.
        $hasProduct = $items->contains(fn (object $i): bool => $i->kind === 'product');

        $storesByItem = collect();
        $stores = collect();
        $catalog = collect();
        $variantsByItem = collect();
        $ranks = [];
        $linkMode = (string) ($site->shop_link_mode ?? 'checkout');

        if ($hasProduct) {
            $links = DB::connection('pgsql')->table('content.collection_items as ci')
                ->join('content.collections as c', 'c.id', '=', 'ci.collection_id')
                ->join('content.storefronts as s', 's.collection_id', '=', 'c.id')
                ->whereIn('ci.item_id', $ids)
                ->where('c.kind', 'storefront')
                // Lowest store position composes when an item sits in two;
                // external_ref breaks the tie, matching brandMap()'s ordering
                // so the dashboard and the wire agree.
                ->orderBy('c.position')->orderBy('s.external_ref')
                ->get([
                    'ci.item_id', 'c.id as collection_id', 'c.label', 'c.position',
                    's.external_ref', 's.provider', 's.url', 's.currency',
                    's.discount_code', 's.referral_query', 's.logo_url', 's.favicon_url',
                ]);

            $storesByItem = $links->groupBy('item_id');
            $stores = $links->unique('collection_id')->keyBy('collection_id');

            // Ordered for the same reason $places is: f_catalog is PK
            // (item_id, source_id), so an item carried by two sources has TWO
            // rows and keyBy keeps the LAST one. Unordered that is arbitrary
            // scan order, which flips vendor between reads AND — the sharp
            // edge — can pair store B's variant_ref with store A as
            // $primaryStore, composing storeA.com/cart/<storeB-variant>:1: a
            // dead checkout link on a CDN-cached page. Freshest wins.
            $catalog = DB::connection('pgsql')->table('content.f_catalog')
                ->whereIn('item_id', $ids)
                ->orderBy('updated_at')
                ->get(['item_id', 'vendor', 'handle', 'variant_ref'])
                ->keyBy('item_id');

            $variantsByItem = DB::connection('pgsql')->table('content.item_variants')
                ->whereIn('item_id', $ids)
                ->orderBy('position')
                ->get(['item_id', 'label', 'sku', 'image_url'])
                ->groupBy('item_id');

            // Spec §3.6: the legacy shop wire carried a real popularityRank
            // (analytics.content_popularity_scores, content_type='shop_product',
            // keyed by product HANDLE). Retiring that lane without this drops
            // live computed data to null.
            $ranks = $this->popularityRanks($site);
        }

        // Headline overrides: the user's edit beats every cache.
        $overrides = DB::connection('pgsql')->table('content.manual_overrides')
            ->whereIn('item_id', $ids)
            ->where('facet', 'f_text')
            ->where('column_name', 'headline')
            ->pluck('value', 'item_id')
            ->map(fn ($v) => is_string($v) ? json_decode($v, true) : $v);

        // Source links, each carrying its connection's platform key. Ordered
        // by source priority so ->first() per item IS the primary source.
        $sourceLinks = DB::connection('pgsql')->table('content.f_link')
            ->join('content.sources', 'content.sources.id', '=', 'content.f_link.source_id')
            ->leftJoin('site.platform_connections', 'site.platform_connections.id', '=', 'content.sources.connection_id')
            ->whereIn('content.f_link.item_id', $ids)
            ->orderByDesc('content.sources.priority')
            ->get([
                'content.f_link.item_id',
                'content.f_link.url',
                'content.sources.kind as source_kind',
                'site.platform_connections.platform as platform',
            ])
            ->groupBy('item_id');

        $manualLinks = DB::connection('pgsql')->table('content.item_links')
            ->whereIn('item_id', $ids)
            ->get(['item_id', 'platform', 'url'])
            ->groupBy('item_id');

        $published = DB::connection('pgsql')->table('content.f_published')
            ->whereIn('item_id', $ids)
            ->whereNotNull('published_from')
            ->selectRaw('item_id, MAX(published_from) as published_from')
            ->groupBy('item_id')
            ->pluck('published_from', 'item_id');

        $durations = DB::connection('pgsql')->table('content.f_duration')
            ->whereIn('item_id', $ids)
            ->whereNotNull('seconds')
            ->selectRaw('item_id, MAX(seconds) as seconds')
            ->groupBy('item_id')
            ->pluck('seconds', 'item_id');

        // Soonest occurrence per item, aggregated in SQL. A collection keyed
        // by item_id would be LAST-row-wins, which is the opposite of what
        // the section's MIN(starts_at_utc) ordering does.
        $occursAt = DB::connection('pgsql')->table('content.f_occurrence')
            ->whereIn('item_id', $ids)
            ->whereNotNull('starts_at_utc')
            ->selectRaw('item_id, MIN(starts_at_utc) as starts_at_utc')
            ->groupBy('item_id')
            ->pluck('starts_at_utc', 'item_id');

        // The local/venue detail belongs to whichever source supplied the
        // soonest time; ordering DESC and letting keyBy overwrite leaves the
        // EARLIEST row in the map.
        $occurrenceDetail = DB::connection('pgsql')->table('content.f_occurrence')
            ->whereIn('item_id', $ids)
            ->whereNotNull('starts_at_utc')
            ->orderByDesc('starts_at_utc')
            ->get(['item_id', 'starts_at_local', 'ends_at_local', 'timezone'])
            ->keyBy('item_id');

        // Ordered for the same reason the occurrence detail is: keyBy keeps
        // the LAST row, and an unordered fetch would let two sources describing
        // one venue flip the published address between reads. Freshest wins.
        $places = DB::connection('pgsql')->table('content.f_place')
            ->whereIn('item_id', $ids)
            ->orderBy('updated_at')
            ->get(['item_id', 'venue_name', 'locality'])
            ->keyBy('item_id');

        // Cheapest offer per item — the scrape sees the lowest tier and the
        // projector stamps qualifier='from' to say so. Ordered DESC so the
        // cheapest row lands LAST, which is the row ->last() returns.
        $offerRows = DB::connection('pgsql')->table('content.offers')
            ->whereIn('item_id', $ids)
            ->orderByRaw('amount_minor IS NULL DESC, amount_minor DESC')
            ->get(['item_id', 'amount_minor', 'amount_max_minor', 'currency', 'qualifier', 'availability', 'variant_label'])
            ->groupBy('item_id');

        // ONE fetch serves both readings: the cheapest offer per item (the
        // ordering writes it last, which is exactly what keyBy used to keep)
        // and the per-variant offers the variants payload needs.
        $offers = $offerRows->map(fn (Collection $rows): object => $rows->last());

        // f_text.body is generic, not shop-specific, so this fetch is
        // UNCONDITIONAL: a video or an event with a body must carry its
        // description too, and gating it on $hasProduct would null it out.
        // The one query non-shop pools pay for in this change.
        //
        // Ordered for the same reason $places is: f_text is PK (item_id,
        // source_id), so an item carried by two sources has TWO rows and keyBy
        // keeps the LAST. Unordered, the published description flips between
        // reads. Freshest wins.
        $texts = DB::connection('pgsql')->table('content.f_text')
            ->whereIn('item_id', $ids)
            ->whereNotNull('body')
            ->orderBy('updated_at')
            ->get(['item_id', 'body'])
            ->keyBy('item_id');

        // Slice 6 §4.2: the review itself. Gated on the resolved set actually
        // containing one, so watch / listen / media / events / shop add no
        // query — this sits behind the 60s payload cache on the public path.
        //
        // Ordered for the same reason $places is: f_review is PK (item_id,
        // source_id), so an item carried by two sources has TWO rows and keyBy
        // keeps the LAST. Unordered that is arbitrary scan order, which flips
        // the published attribution between reads. Freshest wins.
        $reviewIds = $items->filter(fn (object $i): bool => $i->kind === 'review')->keys()->all();

        $reviews = collect();
        $suppressedReviews = [];
        if ($reviewIds !== []) {
            $reviews = DB::connection('pgsql')->table('content.f_review')
                ->whereIn('item_id', $reviewIds)
                ->orderBy('updated_at')
                ->get(['item_id', 'author_name', 'author_photo_url', 'author_uri', 'rating', 'text', 'reviewed_at'])
                ->keyBy('item_id');

            $suppressedReviews = $this->reviewsSuppressedByOwner($reviewIds);
        }

        // Public URL slugs. The legacy events lane served these off
        // site.item_slugs onto the integrations wire; retiring it moves the
        // duty here, so a slug-less item must degrade exactly as that lane did
        // — null slug, raw id as the sole alias — rather than 404 a permalink.
        $slugMap = $this->slugs->lookupCurrent((string) $site->user_id, $ids);

        $creators = DB::connection('pgsql')->table('content.f_authored')
            ->whereIn('item_id', $ids)
            ->whereNotNull('creator')
            ->get(['item_id', 'creator'])
            ->keyBy('item_id');

        $channels = DB::connection('pgsql')->table('content.f_channel')
            ->whereIn('item_id', $ids)
            ->whereNotNull('handle')
            ->get(['item_id', 'handle'])
            ->keyBy('item_id');

        $coverRows = DB::connection('pgsql')->table('content.item_media')
            ->join('content.media_assets', 'content.media_assets.id', '=', 'content.item_media.asset_id')
            ->whereIn('content.item_media.item_id', $ids)
            ->whereIn('content.item_media.role', ['cover', 'poster', 'gallery'])
            ->orderBy('content.item_media.position')
            ->get([
                'content.item_media.item_id',
                'content.item_media.role',
                'content.item_media.alt_text',
                'content.media_assets.id as asset_id',
                'content.media_assets.source_url',
                'content.media_assets.storage_path',
                'content.media_assets.site_media_id',
                'content.media_assets.width',
                'content.media_assets.height',
            ]);

        // ONE resolver call for the page — MediaUrlResolver batches its
        // variant lookup, and this sits on the public hot path.
        $resolvedUrls = $this->mediaUrls->resolve(
            $coverRows->map(fn (object $row): object => (object) [
                'id' => $row->asset_id,
                'source_url' => $row->source_url,
                'storage_path' => $row->storage_path,
                'site_media_id' => $row->site_media_id,
                'width' => $row->width,
                'height' => $row->height,
            ])
        );

        $covers = $coverRows->groupBy('item_id');

        $out = [];
        foreach ($items as $itemId => $item) {
            // Slice 6 §4.4: the owner switched this platform's reviews off.
            // Dropped before the payload is built, so the item leaves the
            // selection AND the library — WS-B2 widened the toggles' meaning
            // from "hide on the sitepage" to "don't serve".
            if (isset($suppressedReviews[$itemId])) {
                continue;
            }

            $links = $this->linkSet(
                $sourceLinks->get($itemId, collect()),
                $manualLinks->get($itemId, collect()),
            );
            $primary = $links[0] ?? null;

            $overrideHeadline = $overrides[$itemId] ?? null;

            $itemStores = $storesByItem->get($itemId, collect());
            $primaryStore = $itemStores->first();
            $isProduct = $item->kind === 'product';
            $handle = (string) ($catalog[$itemId]->handle ?? '');

            // Composed backend-side (spec §3.7) so the affiliate suffix never
            // has to ride the public wire for the sitepage to build the href.
            $outboundUrl = $isProduct && $primary !== null
                ? ShopOutboundUrl::compose(
                    (string) $primary['url'],
                    $linkMode,
                    $primaryStore,
                    $catalog[$itemId]->variant_ref ?? null,
                )
                : ($primary['url'] ?? null);

            $out[$itemId] = [
                'id' => (string) $itemId,
                'kind' => $item->kind,
                'slug' => $slugMap[$itemId]['slug'] ?? null,
                'aliases' => $slugMap[$itemId]['aliases'] ?? [(string) $itemId],
                'headline' => is_string($overrideHeadline) && $overrideHeadline !== ''
                    ? $overrideHeadline
                    : $item->headline_cache,
                'headlineEdited' => is_string($overrideHeadline) && $overrideHeadline !== '',
                'url' => $outboundUrl,
                'platform' => $primary['platform'] ?? null,
                'creator' => $creators[$itemId]->creator ?? $channels[$itemId]->handle ?? null,
                'publishedAt' => $published[$itemId] ?? null,
                'firstSeenAt' => $item->first_seen_at,
                'durationSeconds' => isset($durations[$itemId]) ? (int) $durations[$itemId] : null,
                'thumbnail' => $this->cover($covers->get($itemId, collect()), $resolvedUrls),
                // Slice 1a §3.5: media items ship every frame (positional);
                // products joined in 5b — the legacy shop wire carried 271
                // gallery images and retiring it without this loses them.
                // Every other kind ships [] — the wire shape does not change
                // with kind, same contract startsAt/venue/price follow.
                'frames' => in_array($item->kind, ['media', 'product'], true)
                    ? $this->frames($covers->get($itemId, collect()), $resolvedUrls)
                    : [],
                // Dated / located / priced facets. Present on every pool item
                // and null off events, so the wire shape does not change with
                // kind — same contract durationSeconds already has.
                'startsAt' => $occursAt[$itemId] ?? null,
                'startsAtLocal' => $occurrenceDetail[$itemId]->starts_at_local ?? null,
                'endsAtLocal' => $occurrenceDetail[$itemId]->ends_at_local ?? null,
                'timezone' => $occurrenceDetail[$itemId]->timezone ?? null,
                'venue' => $places[$itemId]->venue_name ?? null,
                'locality' => $places[$itemId]->locality ?? null,
                'price' => isset($offers[$itemId]) ? [
                    'amountMinor' => $offers[$itemId]->amount_minor === null ? null : (int) $offers[$itemId]->amount_minor,
                    'amountMaxMinor' => $offers[$itemId]->amount_max_minor === null ? null : (int) $offers[$itemId]->amount_max_minor,
                    'currency' => $offers[$itemId]->currency,
                    'qualifier' => $offers[$itemId]->qualifier,
                ] : null,
                // Deliberately the CHEAPEST offer's availability, not the
                // event's: it qualifies the price beside it, so "from $6.61 /
                // sold_out" reads as "that tier is gone". An event-level
                // rollup would need a rank over availability values and would
                // then disagree with the price it sits next to.
                'availability' => $offers[$itemId]->availability ?? null,
                'links' => $links,
                'popularityRank' => $handle !== '' ? ($ranks[$handle] ?? null) : null,
                // Additive and nullable on EVERY item, never a kind-shaped
                // sub-object — the same contract startsAt / venue / price
                // already follow, so the wire shape does not vary with kind.
                'description' => $texts[$itemId]->body ?? null,
                'vendor' => $catalog[$itemId]->vendor ?? null,
                'variants' => $this->variants(
                    $variantsByItem->get($itemId, collect()),
                    $offerRows->get($itemId, collect()),
                ),
                // Plural because it must be: a URL-derived coord is not
                // store-scoped (5a §3.3), so one product URL listed by two of a
                // user's stores is ONE item in TWO collections.
                'collectionIds' => $itemStores->pluck('collection_id')
                    ->unique()->map(fn ($id) => (string) $id)->values()->all(),
                // Slice 6: present on every pool item and null off every kind
                // but `review`, the same contract startsAt / venue / price
                // keep. Attribution is read from f_review — the ONE copy that
                // Manifest::$redactionScopes, content:prune-orphaned-review-pii
                // and the DSAR omission all reach. Do NOT source it from
                // headline: that copy was the §2.2 defect, and the projector
                // now nulls it by contract.
                'review' => isset($reviews[$itemId]) ? [
                    'rating' => $reviews[$itemId]->rating === null ? null : (float) $reviews[$itemId]->rating,
                    'text' => $reviews[$itemId]->text,
                    'authorName' => $reviews[$itemId]->author_name,
                    'authorPhotoUrl' => $reviews[$itemId]->author_photo_url,
                    'authorUri' => $reviews[$itemId]->author_uri,
                    'reviewedAt' => $reviews[$itemId]->reviewed_at,
                ] : null,
            ];
        }

        return [$out, $stores];
    }

    /**
     * Review items every one of whose sources has reviews switched off, keyed
     * by item id.
     *
     * The toggle lives on the platform connection and DisplaySettingsFilter
     * applies it to the LEGACY payload lane only — buildPools() never passes
     * through it, so without this an owner who switched reviews off has them
     * republished by the pool. Keyed per SOURCE rather than per pool so a
     * second review platform does not go dark with the first: an item still
     * carried by an unsuppressed source stays.
     *
     * ONE query for the page (public hot path), and only when the resolved set
     * contains a review at all. Removed source items are deliberately NOT
     * filtered out — a source the owner silenced should keep counting as
     * silenced while its rows linger.
     *
     * @param  list<string>  $reviewIds
     * @return array<string, true>
     */
    private function reviewsSuppressedByOwner(array $reviewIds): array
    {
        $rows = DB::connection('pgsql')->table('content.source_items as si')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->leftJoin('site.platform_connections as pc', 'pc.id', '=', 'cs.connection_id')
            ->whereIn('si.item_id', $reviewIds)
            ->get(['si.item_id', 'pc.platform', 'pc.display_settings']);

        $suppressed = [];
        foreach ($rows->groupBy('item_id') as $itemId => $sourceRows) {
            if ($sourceRows->every(fn (object $row): bool => $this->connectionHidesReviews($row))) {
                $suppressed[(string) $itemId] = true;
            }
        }

        return $suppressed;
    }

    /** Whether one connection's display settings switch reviews off. */
    private function connectionHidesReviews(object $connection): bool
    {
        $settings = json_decode((string) ($connection->display_settings ?? '{}'), true);
        $settings = is_array($settings) ? $settings : [];

        // DisplaySettingsFilter is the single source of truth for what a
        // toggle suppresses, and `reviews` is one of the payload keys the
        // google-business `reviews` toggle removes.
        $disabled = DisplaySettingsFilter::disabledKeys(
            (string) ($connection->platform ?? ''),
            $settings,
        );

        // The second half fails CLOSED for a review platform not yet in that
        // map: a toggle literally named `reviews`, switched off, means the
        // owner switched reviews off whatever the map knows about it.
        return in_array('reviews', $disabled, true)
            || ($settings['reviews'] ?? true) === false;
    }

    /**
     * The shop-product popularity ranks for a site, keyed by product handle.
     *
     * Same key and TTL as PublicIntegrationController (CCG-102) on purpose:
     * two different keys would silently halve a single-flight cache that
     * exists because this read used to hit Postgres on every public request.
     *
     * @return array<string, int>
     */
    private function popularityRanks(Site $site): array
    {
        $ranks = $this->cache->rememberLocked(
            CacheKeyGenerator::sitePopularityRanks((string) $site->id),
            self::POPULARITY_CACHE_TTL_SECONDS,
            fn () => $this->popularity->forSite((string) $site->id),
        );

        return $ranks['shop_product'] ?? [];
    }

    /**
     * Variant objects for a product. Built key-by-key on purpose (spec §3.7):
     * this is the first nested collection the pool payload carries, and a
     * spread of the DB row is exactly how an unvetted column would reach a
     * CDN-cached public wire.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @param  Collection<int, \stdClass>  $offerRows
     * @return list<array<string, mixed>>
     */
    private function variants(Collection $rows, Collection $offerRows): array
    {
        $byLabel = $offerRows->filter(fn (object $o): bool => (string) ($o->variant_label ?? '') !== '')
            ->keyBy('variant_label');

        $out = [];
        foreach ($rows as $row) {
            $offer = $byLabel->get((string) $row->label);
            $out[] = [
                'label' => (string) $row->label,
                'sku' => $row->sku === null ? null : (string) $row->sku,
                // Unverified against real data: image_url is populated on 0 of
                // 268 dev rows, so this round-trips in tests only.
                'imageUrl' => $row->image_url === null ? null : (string) $row->image_url,
                'availability' => $offer->availability ?? null,
                'price' => $offer === null ? null : [
                    'amountMinor' => $offer->amount_minor === null ? null : (int) $offer->amount_minor,
                    'amountMaxMinor' => $offer->amount_max_minor === null ? null : (int) $offer->amount_max_minor,
                    'currency' => $offer->currency,
                    'qualifier' => $offer->qualifier,
                ],
            ];
        }

        return $out;
    }

    /**
     * The store cards the sitepage rebuilds its shop layout from. Only
     * collections a SELECTED item references — an unreferenced store card
     * would render as an empty group.
     *
     * @param  list<array<string, mixed>>  $selection
     * @param  Collection<string, object>  $stores
     * @return array<string, array<string, mixed>>
     */
    private function collectionsFor(array $selection, Collection $stores): array
    {
        $referenced = collect($selection)->flatMap(fn (array $i) => $i['collectionIds'] ?? [])->unique();

        $out = [];
        foreach ($referenced as $collectionId) {
            $row = $stores->get($collectionId);
            if ($row === null) {
                continue;
            }
            // Explicit keys only (spec §3.7). referralQuery and linkMode are
            // DELIBERATELY absent: composition is backend-side now, so the
            // affiliate suffix stops being publicly readable. sourceUrl
            // (re-scrape input) and connectStatus (dashboard-only) stay
            // private — neither is even selected above.
            // content.collections.label is NOT NULL and upsertStore() writes
            // `name ?? brand_id` into it, so "no fetched name" is stored as the
            // id itself. ShopContentReader:159 nulls that back out on the
            // dashboard read; mirroring the rule EXACTLY (=== the external ref,
            // not a looser "looks like an id" test) is what stops the wire and
            // the dashboard disagreeing about a store's name. Without it a
            // store whose name was never fetched publishes its raw brand_id —
            // "75102060779", "fearnoevil-com-au" — as its public store-card
            // name on a CDN-cached page. Reachable for any store whose label
            // equals its ref, and reachable *often* since slice 5b, because a
            // still-pending store renders and is precisely the one whose name
            // has not been fetched yet. Same narrow false positive the reader
            // accepts: a store genuinely named the same string as its own id
            // also reads back null.
            $externalRef = (string) $row->external_ref;
            $out[(string) $collectionId] = [
                'externalRef' => $externalRef,
                'provider' => (string) $row->provider,
                'url' => $row->url === null ? null : (string) $row->url,
                'name' => (string) $row->label === $externalRef ? null : (string) $row->label,
                'currency' => $row->currency === null ? null : (string) $row->currency,
                'favicon' => $row->favicon_url === null ? null : (string) $row->favicon_url,
                'logo' => $row->logo_url === null ? null : (string) $row->logo_url,
                'discountCode' => $row->discount_code === null ? null : (string) $row->discount_code,
                'position' => (int) $row->position,
            ];
        }

        return $out;
    }

    /**
     * The per-platform link set: synced source links first (priority order),
     * then the hand-saved item_links for platforms no source covers. One
     * entry per platform; a synced link always beats a manual one for the
     * same platform — the sync cannot drift, the hand-typed URL can.
     *
     * @return list<array{platform: string|null, url: string, source: string}>
     */
    private function linkSet(Collection $sourceRows, Collection $manualRows): array
    {
        $links = [];
        $seen = [];

        foreach ($sourceRows as $row) {
            $platform = $row->platform !== null ? (string) $row->platform : null;
            if ($platform !== null && isset($seen[$platform])) {
                continue;
            }
            if ($platform !== null) {
                $seen[$platform] = true;
            }
            $links[] = ['platform' => $platform, 'url' => (string) $row->url, 'source' => 'synced'];
        }

        foreach ($manualRows as $row) {
            if (isset($seen[$row->platform])) {
                continue;
            }
            $seen[$row->platform] = true;
            $links[] = ['platform' => (string) $row->platform, 'url' => (string) $row->url, 'source' => 'manual'];
        }

        return $links;
    }

    /**
     * Prefer the cover, then poster, then any gallery frame — ROLE priority,
     * not positional order (frames() is the positional view). Same firstWhere
     * semantics as before slice 1a; only the URL source moved from raw
     * source_url to the resolver seam.
     *
     * @param  array<string, array{url: string, width: int|null, height: int|null}>  $resolved
     */
    private function cover(Collection $rows, array $resolved): ?string
    {
        foreach (['cover', 'poster', 'gallery'] as $role) {
            $row = $rows->firstWhere('role', $role);
            $url = $row !== null ? ($resolved[(string) $row->asset_id]['url'] ?? null) : null;
            if ($url !== null && $url !== '') {
                return $url;
            }
        }

        return null;
    }

    /**
     * Every servable frame, in item_media.position order. An asset that
     * resolves to no URL is OMITTED, never emitted as null — the unrenderable
     * ref-only Google assets degrade to an empty gallery (spec §3.5).
     *
     * @param  array<string, array{url: string, width: int|null, height: int|null}>  $resolved
     * @return list<array{url: string, width: int|null, height: int|null, role: string, alt: string|null}>
     */
    private function frames(Collection $rows, array $resolved): array
    {
        $frames = [];
        foreach ($rows as $row) {
            $hit = $resolved[(string) $row->asset_id] ?? null;
            if ($hit === null) {
                continue;
            }
            $frames[] = [
                'url' => $hit['url'],
                'width' => $hit['width'],
                'height' => $hit['height'],
                'role' => (string) $row->role,
                'alt' => $row->alt_text === null ? null : (string) $row->alt_text,
            ];
        }

        return $frames;
    }
}
