<?php

namespace App\Site\Pools;

use App\Models\Core\Site\Site;
use App\Services\Analytics\ContentPopularityReader;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Content\ContentItemSlugAllocator;
use App\Services\Media\MediaUrlResolver;
use App\Services\Platforms\ConnectionDisplayName;
use App\Services\Platforms\DisplaySettingsFilter;
use App\Site\Actions\ActionSettings;
use App\Site\Sections\SectionCandidates;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
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
        'thumbnail', 'favicon', 'frames', 'startsAt', 'startsAtLocal', 'endsAtLocal',
        'timezone', 'venue', 'locality', 'price', 'availability', 'links',
        'popularityRank', 'description', 'vendor', 'variants', 'collectionIds',
        'review', 'selected', 'origin', 'overrides', 'sources',
        'format', 'album', 'trackNumber', 'collectionPositions', 'duplicateCandidates',
    ];

    /** Dashboard-only item keys, stripped before the public wire. */
    public const DASHBOARD_ONLY_ITEM_KEYS = ['selected', 'overrides', 'sources', 'duplicateCandidates'];

    /** Public fields of one store card in a pool's `collections` map. */
    public const STORE_KEYS = [
        'externalRef', 'provider', 'url', 'name', 'currency',
        'favicon', 'logo', 'discountCode', 'position',
    ];

    /** Public fields of one product variant. */
    public const VARIANT_KEYS = ['label', 'sku', 'imageUrl', 'availability', 'price'];

    private const LIBRARY_LIMIT = 500;

    // CCG-102: this is now the SOLE holder of the constant, and the sole
    // consumer of CacheKeyGenerator::sitePopularityRanks(). It exists because
    // the read used to hit Postgres on every public request; the TTL tracks
    // the analytics:compute-popularity cadence (routes/console.php, 15
    // minutes), beyond which extra staleness buys nothing.
    // PublicIntegrationController held it too until slice 5b Task 8 retired
    // its shop block, and PublicMenuController until slice 7 Phase 3 Task 10
    // deleted the /menu endpoint. Both read the SAME key, so any future
    // second holder must copy this value verbatim rather than pick its own.
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
     *   diningModes: list<string>|null,
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

        $libraryQuery = DB::connection('pgsql')->table('content.items')
            ->where('user_id', $site->user_id)
            ->whereIn('kind', PoolRegistry::kinds($pool))
            ->whereNull('removed_at');
        // Disconnect = hide (W2): the library lists only items with a live
        // source (manual, or a present + active connection).
        LiveSourceScope::apply($libraryQuery);
        $libraryIds = $libraryQuery
            ->orderByDesc('last_seen_at')
            ->limit(self::LIBRARY_LIMIT)
            ->pluck('id')
            ->all();

        // Pins from a removed connection hide too — the pin row stays (a
        // reconnect brings it back), but it does not publish.
        if ($pinned !== []) {
            $livePinsQuery = DB::connection('pgsql')->table('content.items')->whereIn('id', $pinned);
            LiveSourceScope::apply($livePinsQuery);
            $livePinned = $livePinsQuery->pluck('id')->flip()->all();
            $pinned = array_values(array_filter($pinned, fn ($id) => isset($livePinned[$id])));
            $selectionIds = [];
            foreach ([...$pinned, ...$ruleIds] as $itemId) {
                if (! isset($excluded[$itemId])) {
                    $selectionIds[] = $itemId;
                }
            }
        }

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

        // Per-pool ordering mode (spec §5.4): pins and auto reorder together
        // in newest/smart; manual keeps pins-then-rule. Events always keep
        // occurrence order; reviews never rank.
        $settings = ActionSettings::fromSite($site);
        $mode = in_array($pool, ['events', 'reviews'], true) ? 'manual' : $settings->poolMode($pool);
        $selection = PoolOrdering::order($mode, $selection);
        if ($mode !== 'manual') {
            // Owner locks (settings.pool_locks) hold items in place while the
            // mode fills the rest — the dashboard's "Lock in position".
            $selection = PoolOrdering::applyLocks($selection, $settings->poolLocksFor($pool));
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
            'collections' => PoolOrdering::orderCollections($mode, $this->collectionsFor($selection, $stores), $selection),
            'stats' => $this->statsFor($pool, $selection),
            'diningModes' => $this->diningModesFor($pool, $site),
            // W8: the platforms a manual link may be added for on this pool —
            // ItemLinkRules::ROSTER, so the dashboard stops hand-copying it.
            'linkRoster' => ItemLinkRules::rosterFor($pool),
        ];
    }

    /**
     * A public timestamp, as ISO-8601 in UTC (#API-1).
     *
     * The query builder hands back naive "Y-m-d H:i:s" strings, which a
     * browser's Date() reads as LOCAL time — a +10h error on an AEST reader,
     * silently, with no parse failure to notice. The nested sources[] array got
     * this fix; the three TOP-LEVEL fields visitors actually see (publishedAt,
     * firstSeenAt, startsAt) did not.
     *
     * ->utc() is not decoration. latestFor() compares publishedAt/firstSeenAt
     * as STRINGS inside a tuple, so the ordering is only correct while every
     * value renders in one offset; mixed offsets would sort "2026-01-01T12:00:00+11:00"
     * after "2026-01-01T09:00:00+00:00" despite being the earlier instant.
     *
     * NOT applied to startsAtLocal / endsAtLocal. Those are deliberately wall
     * clock — an event at 7pm local stays 7pm local across a DST rule change,
     * which is the whole reason content.f_occurrence stores both.
     *
     * Returns null rather than a guess when the value will not parse: these
     * fields can carry a manual override, and emitting an unparseable string to
     * the wire is worse than admitting we have no date.
     */
    private static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->utc()->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
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

        // #LIFE-2: the aggregates are SOURCE-level, and this query had no
        // liveness filter of any kind — no removed_at, no connection check. So
        // disconnecting a Google listing because the reviews are bad left the
        // 4.8 and the review count publishing on the page indefinitely: the
        // items went (LiveSourceScope covers those) and the badge summarising
        // them stayed. Same predicate as everywhere else, from the same helper.
        $query = DB::connection('pgsql')->table('content.source_stats as ss')
            ->join('content.source_items as si', 'si.source_id', '=', 'ss.source_id')
            ->join('content.sources as stats_src', 'stats_src.id', '=', 'ss.source_id')
            ->leftJoin('site.platform_connections as stats_conn', 'stats_conn.id', '=', 'stats_src.connection_id')
            ->whereIn('si.item_id', array_column($selection, 'id'))
            // A source_item retired by absence folding does not carry the badge
            // either — the same reading LiveSourceScope takes for the items.
            ->whereNull('si.removed_at')
            // Two connected places would be unusual, but the busiest listing is
            // the defensible one to show and ordering makes that a decision
            // rather than whatever row Postgres returned first.
            ->orderByDesc('ss.rating_count');

        LiveSourceScope::constrainToLiveSource($query, 'stats_src', 'stats_conn');

        $row = $query->first(['ss.rating_avg', 'ss.rating_count', 'ss.summary_text']);

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
     * A dated item always outranks an undated one (X5): first_seen_at is the
     * moment WE saw it, not a release date, and an Apple song with no
     * releaseDate ("Runway Houses City Clouds (2020 Mix)") was taking the tag
     * off a release dated last month.
     *
     * @param  list<array<string, mixed>>  $selection
     */
    private function latestItemId(array $selection): ?string
    {
        $latest = null;
        $latestKey = null;
        foreach ($selection as $item) {
            $published = $item['publishedAt'] ?? null;
            $at = $published ?? $item['firstSeenAt'] ?? null;
            if ($at === null) {
                continue;
            }
            $key = [$published !== null ? 1 : 0, $at];
            if ($latestKey === null || $key > $latestKey) {
                $latestKey = $key;
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

        // Menus group too (slice 4): a dish belongs to its categories and to
        // the ordering platforms it is sold on. Services group too (owner,
        // 2026-08-17): their kind='service_category' collections existed since
        // slice 3b but never reached the wire, so the services pool shipped
        // flat. The COLLECTIONS read is gated on any of the three kinds; the
        // catalogue, variant and popularity reads below stay shop-only,
        // because menus and services have none of those.
        $hasMenuItem = $items->contains(fn (object $i): bool => $i->kind === 'menu_item');
        $hasService = $items->contains(fn (object $i): bool => $i->kind === 'service');
        $groupsIntoCollections = $hasProduct || $hasMenuItem || $hasService;

        $storesByItem = collect();
        $stores = collect();
        $catalog = collect();
        $variantsByItem = collect();
        // Popularity ranks for EVERY item (2026-08-23, pool smart order): one
        // cached read of every item family. The lookup is FAMILY-AWARE — a
        // product reads shop_product by handle, a link reads link_item by url,
        // everything else reads its kind's family by item id — so a watch_item
        // row keyed by a slug can never leak onto a product sharing it.
        $ranks = $this->popularityRanks($site);
        $linkMode = (string) ($site->shop_link_mode ?? 'checkout');

        if ($groupsIntoCollections) {
            // LEFT join onto storefronts, and no `c.kind` filter: a menu
            // category is a collection with NO sidecar, and gating on the
            // sidecar would leave every dish publishing collectionIds that
            // point at collections absent from the map — dangling ids on the
            // wire. The kind filter is redundant now that the join no longer
            // requires a storefront: a product only ever sits in storefront
            // collections, and a dish only in menu_category / order_platform.
            $links = DB::connection('pgsql')->table('content.collection_items as ci')
                ->join('content.collections as c', 'c.id', '=', 'ci.collection_id')
                ->leftJoin('content.storefronts as s', 's.collection_id', '=', 'c.id')
                ->whereIn('ci.item_id', $ids)
                // An owner-deleted collection must not reappear as a group
                // header just because its members are still selected.
                ->whereNull('c.removed_at')
                // Lowest position composes when an item sits in two; the
                // collection's own external_ref breaks the tie (it is NOT NULL
                // on every row, unlike the storefront's), matching brandMap()'s
                // ordering so the dashboard and the wire agree.
                ->orderBy('c.position')->orderBy('c.external_ref')
                ->get([
                    'ci.item_id', 'c.id as collection_id', 'c.label', 'c.position',
                    'ci.position as member_position',
                    'c.external_ref as collection_ref', 'c.kind as collection_kind',
                    's.external_ref', 's.provider', 's.url', 's.currency',
                    's.discount_code', 's.referral_query', 's.logo_url', 's.favicon_url',
                ]);

            $storesByItem = $links->groupBy('item_id');
            $stores = $links->unique('collection_id')->keyBy('collection_id');
        }

        // Listen restructure (2026-08-18): a release's format (album|ep|single)
        // and a track's parent release + position, off f_catalog. Value-merged
        // across an item's sources: the first non-null per column, in source
        // priority order, so an Apple song that knows its album fills the gap
        // a Spotify row left.
        $music = collect();
        if ($items->contains(fn ($item) => in_array($item->kind, ['track', 'release', 'episode'], true))) {
            $music = DB::connection('pgsql')->table('content.f_catalog as c')
                ->join('content.sources as cs', 'cs.id', '=', 'c.source_id')
                ->whereIn('c.item_id', $ids)
                ->orderByDesc('cs.priority')
                ->get(['c.item_id', 'c.release_type', 'c.collection_title', 'c.track_number', 'c.disc_number'])
                ->groupBy('item_id')
                ->map(function ($rows) {
                    $out = ['release_type' => null, 'collection_title' => null, 'track_number' => null, 'disc_number' => null];
                    foreach ($rows as $row) {
                        foreach ($out as $key => $current) {
                            if ($current === null && $row->{$key} !== null && $row->{$key} !== '') {
                                $out[$key] = $row->{$key};
                            }
                        }
                    }

                    return (object) $out;
                });
        }

        if ($hasProduct) {

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

        }

        // Manual overrides: the user's edit beats every cache. ONE read for
        // every (facet, column) — this was headline-only until 2026-08-18,
        // which meant every OTHER override the dashboard's item sheets wrote
        // (description, duration, venue, dates, URL…) saved a row the wire
        // silently never applied.
        $overrideRows = DB::connection('pgsql')->table('content.manual_overrides')
            ->whereIn('item_id', $ids)
            ->get(['item_id', 'facet', 'column_name', 'value']);
        $overridesByKey = [];
        foreach ($overrideRows as $row) {
            $value = is_string($row->value) ? json_decode($row->value, true) : $row->value;
            $overridesByKey[$row->facet.'.'.$row->column_name][(string) $row->item_id] = $value;
        }
        $overrides = collect($overridesByKey['f_text.headline'] ?? []);

        // Source links, each carrying its connection's platform key. Ordered
        // by source priority so ->first() per item IS the primary source.
        // #LIFE-4: no liveness filter at all. An item kept alive by ONE live
        // source could still publish a "book now" link belonging to a platform
        // the owner had disconnected — the item survives LiveSourceScope
        // legitimately, and then this query hands it the dead platform's url.
        $sourceLinksQuery = DB::connection('pgsql')->table('content.f_link')
            ->join('content.sources', 'content.sources.id', '=', 'content.f_link.source_id')
            ->leftJoin('site.platform_connections', 'site.platform_connections.id', '=', 'content.sources.connection_id')
            ->whereIn('content.f_link.item_id', $ids)
            ->orderByDesc('content.sources.priority');

        LiveSourceScope::constrainToLiveSource($sourceLinksQuery);

        $sourceLinks = $sourceLinksQuery
            ->get([
                'content.f_link.item_id',
                'content.f_link.url',
                'content.sources.kind as source_kind',
                'site.platform_connections.platform as platform',
            ])
            ->each(function (object $row): void {
                $row->platform = self::wirePlatform($row->platform);
            })
            ->groupBy('item_id');

        // A dish's per-platform links (W5): every offer that knows the store
        // url it came from contributes a synced link for that ordering
        // platform (host → roster platform), so a dish on Uber Eats AND
        // DoorDash shows both. Menu items had no f_link at all before this.
        // #LIFE-4 again. This path landed AFTER the audit was taken and carries
        // the identical gap — it never joined platform_connections at all, so
        // there was nothing to filter on. Same helper, so the two cannot drift.
        $offerLinksQuery = DB::connection('pgsql')->table('content.offers')
            ->join('content.sources', 'content.sources.id', '=', 'content.offers.source_id')
            ->leftJoin('site.platform_connections', 'site.platform_connections.id', '=', 'content.sources.connection_id')
            ->whereIn('content.offers.item_id', $ids)
            ->whereNotNull('content.offers.url')
            ->orderByDesc('content.sources.priority');

        LiveSourceScope::constrainToLiveSource($offerLinksQuery);

        $offerLinks = $offerLinksQuery
            ->get(['content.offers.item_id', 'content.offers.url', 'content.sources.kind as source_kind'])
            ->map(function (object $row): ?object {
                $platform = ItemLinkRules::platformForUrl((string) $row->url);

                return $platform === null ? null : (object) ['item_id' => $row->item_id, 'url' => (string) $row->url, 'source_kind' => (string) $row->source_kind, 'platform' => $platform];
            })
            ->filter()
            ->groupBy('item_id');
        foreach ($offerLinks as $itemId => $rows) {
            $sourceLinks[$itemId] = ($sourceLinks[$itemId] ?? collect())->concat($rows);
        }

        // A connection-fed item with NO url of its own (a Fresha service —
        // the vendor has no per-service page, only the venue's booking page)
        // still came FROM a platform: derive it from the item's live
        // connection source so the source badge and the item sheet's platform
        // row can name it, and lend the connection's own url as the link
        // (overnight 2026-08-18 W6). Highest-priority connection wins.
        // Every source that lists the item (W8: the item sheet's "Sources"
        // list with sync badges) — manual and connection, live only; the
        // connection's platform + display name + last sync time ride along.
        $sourceRows = DB::connection('pgsql')->table('content.source_items')
            ->join('content.sources', 'content.sources.id', '=', 'content.source_items.source_id')
            ->leftJoin('site.platform_connections', 'site.platform_connections.id', '=', 'content.sources.connection_id')
            ->whereIn('content.source_items.item_id', $ids)
            ->whereNull('content.source_items.removed_at')
            ->where(function ($w) {
                $w->where('content.sources.kind', 'manual')
                    ->orWhere(function ($c) {
                        $c->whereNotNull('site.platform_connections.id')
                            ->whereNull('site.platform_connections.deleted_at');
                    });
            })
            ->orderByDesc('content.sources.priority')
            ->get([
                'content.source_items.item_id',
                'content.source_items.last_seen_at',
                'content.sources.kind as source_kind',
                'site.platform_connections.id as connection_id',
                'site.platform_connections.platform as platform',
                'site.platform_connections.surface_key as surface_key',
                'site.platform_connections.payload as payload',
                'site.platform_connections.is_active as is_active',
            ])
            ->groupBy('item_id');
        // The connection's ingest cadence (last run, auto-sync) for the sync
        // badge — a separate read because ingest.* is its own lane (many
        // content-only fixtures do not create it) and the join would have
        // fanned the row set out per stream anyway.
        $connectionIds = $sourceRows->flatten(1)->pluck('connection_id')->filter()->unique()->values()->all();
        $ingestByConnection = [];
        if ($connectionIds !== []) {
            try {
                $ingestByConnection = DB::connection('pgsql')->table('ingest.sources')
                    ->whereIn('connection_id', $connectionIds)
                    ->orderByDesc('last_run_at')
                    ->get(['connection_id', 'last_run_at', 'auto_sync'])
                    ->unique('connection_id')
                    ->keyBy('connection_id')
                    ->all();
            } catch (QueryException) {
                // No ingest schema in this environment: badges read "never".
                $ingestByConnection = [];
            }
        }
        // Where a manual-lane item came from. The manual source is one row per
        // user (partial unique index), so it cannot say "found in your bio
        // link" vs "typed by you" itself; the writer records that as a typed
        // item tag (tag_type 'origin', e.g. 'link_in_bio') and the sheet reads
        // it here. Absent = added by hand.
        $originByItem = DB::connection('pgsql')->table('content.item_tags')
            ->whereIn('item_id', $ids)
            ->where('tag_type', 'origin')
            ->get(['item_id', 'tag'])
            ->groupBy('item_id')
            ->map(fn ($rows) => (string) $rows->first()->tag)
            ->all();

        $sourcesByItem = $sourceRows->map(function ($rows, $itemId) use ($ingestByConnection, $originByItem): array {
            $seen = [];
            $out = [];
            foreach ($rows as $row) {
                $key = $row->source_kind === 'manual' ? 'manual' : (string) $row->connection_id;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                // Timestamps go out as ISO-8601 with zone: the query builder
                // hands back naive "Y-m-d H:i:s" strings which a browser's
                // Date() would read as LOCAL time (a +10h badge — review).
                $iso = fn ($v) => $v === null ? null : Carbon::parse((string) $v)->toIso8601String();
                if ($row->source_kind === 'manual') {
                    $out[] = ['kind' => 'manual', 'platform' => null, 'accountName' => null, 'origin' => $originByItem[(string) $itemId] ?? null, 'lastSeenAt' => $iso($row->last_seen_at), 'lastSyncedAt' => null, 'autoSync' => false, 'active' => true];

                    continue;
                }
                $payload = is_string($row->payload) ? (json_decode($row->payload, true) ?: []) : (array) ($row->payload ?? []);
                $out[] = [
                    'kind' => 'connection',
                    'platform' => (string) self::wirePlatform($row->platform),
                    'accountName' => ConnectionDisplayName::for((string) ($row->surface_key ?? ''), $payload),
                    'lastSeenAt' => $iso($row->last_seen_at),
                    'lastSyncedAt' => $iso($ingestByConnection[(string) $row->connection_id]->last_run_at ?? null),
                    'autoSync' => (bool) ($ingestByConnection[(string) $row->connection_id]->auto_sync ?? false),
                    'active' => (bool) $row->is_active,
                ];
            }

            return $out;
        });
        // #LIFE-3: the liveness filter belongs HERE, not on $sourceRows. That
        // query also feeds the item sheet's Sources list, which deliberately
        // KEEPS a paused connection so it can badge it `active: false` — the
        // owner needs to see the source it paused. This map is the public-wire
        // consumer: it supplies an item's fallback platform/url when the item
        // has no link of its own, so a paused connection reaching it publishes
        // exactly what LiveSourceScope hides everywhere else (owner ruling
        // 2026-08-19: pause = hide). The #LIFE-4 fix makes this path HOTTER,
        // not colder — dropping a paused source's f_link is precisely what
        // leaves $primary null and falls through to here.
        $sourcePlatforms = $sourceRows
            ->map(function ($rows): ?object {
                $rows = $rows->filter(fn ($r) => $r->source_kind !== 'manual' && $r->connection_id !== null && (bool) $r->is_active);
                $row = $rows->first();
                if ($row === null || ! is_string($row->platform) || $row->platform === '') {
                    return null;
                }
                $payload = is_string($row->payload) ? (json_decode($row->payload, true) ?: []) : (array) ($row->payload ?? []);
                $url = $payload['url'] ?? ($payload['selection']['url'] ?? null);

                return (object) ['platform' => self::wirePlatform($row->platform), 'url' => is_string($url) && preg_match('~^https?://~i', $url) ? $url : null];
            })
            ->filter();

        // Open identity candidates involving these items (task #18): the
        // resolver's Evidential tier — "these might be the same thing" — for
        // the dashboard's Possible-duplicate chip + same/different verbs.
        // Dashboard-only; stripped from the public wire.
        $candidateRows = DB::connection('pgsql')->table('content.identity_candidates as ic')
            ->join('content.items as li', 'li.id', '=', 'ic.left_item_id')
            ->join('content.items as ri', 'ri.id', '=', 'ic.right_item_id')
            ->whereNull('ic.dismissed_at')
            ->whereNull('li.removed_at')->whereNull('ri.removed_at')
            ->where(fn ($w) => $w->whereIn('ic.left_item_id', $ids)->orWhereIn('ic.right_item_id', $ids))
            ->get(['ic.left_item_id', 'ic.right_item_id', 'ic.evidence', 'li.headline_cache as left_headline', 'ri.headline_cache as right_headline']);
        $candidatesByItem = [];
        foreach ($candidateRows as $row) {
            $evidence = is_string($row->evidence) ? (json_decode($row->evidence, true)['key'] ?? null) : null;
            $candidatesByItem[(string) $row->left_item_id][] = ['itemId' => (string) $row->right_item_id, 'headline' => $row->right_headline, 'evidence' => $evidence];
            $candidatesByItem[(string) $row->right_item_id][] = ['itemId' => (string) $row->left_item_id, 'headline' => $row->left_headline, 'evidence' => $evidence];
        }

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
            // `logo` joins the set (2026-08-17): a link's favicon rides the
            // logo role and reads back as `favicon` below. cover()/frames()
            // still see only their own roles.
            ->whereIn('content.item_media.role', ['cover', 'poster', 'gallery', 'logo', 'video'])
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
                'content.media_assets.mime_type',
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
            if ($primary === null && isset($sourcePlatforms[$itemId])) {
                $fallback = $sourcePlatforms[$itemId];
                $primary = ['platform' => $fallback->platform, 'url' => $fallback->url, 'source' => 'synced'];
                if ($fallback->url !== null) {
                    $links = [$primary];
                }
            }

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

            // The item's own override for one (facet, column) — a scalar, or
            // $fallback when none is stored. Null stored IS a value (an
            // explicit clear), matching the override endpoint's contract.
            $ov = function (string $key, mixed $fallback) use ($overridesByKey, $itemId): mixed {
                return array_key_exists((string) $itemId, $overridesByKey[$key] ?? [])
                    ? $overridesByKey[$key][(string) $itemId]
                    : $fallback;
            };

            $out[$itemId] = [
                'id' => (string) $itemId,
                'kind' => $item->kind,
                'slug' => $slugMap[$itemId]['slug'] ?? null,
                'aliases' => $slugMap[$itemId]['aliases'] ?? [(string) $itemId],
                'headline' => is_string($overrideHeadline) && $overrideHeadline !== ''
                    ? $overrideHeadline
                    : $item->headline_cache,
                'headlineEdited' => is_string($overrideHeadline) && $overrideHeadline !== '',
                'url' => $ov('f_link.url', $outboundUrl),
                'platform' => $primary['platform'] ?? null,
                'creator' => $ov('f_authored.creator', $creators[$itemId]->creator ?? $channels[$itemId]->handle ?? null),
                'publishedAt' => self::iso($ov('f_published.published_from', $published[$itemId] ?? null)),
                'firstSeenAt' => self::iso($item->first_seen_at),
                'durationSeconds' => (function () use ($ov, $durations, $itemId): ?int {
                    $v = $ov('f_duration.seconds', isset($durations[$itemId]) ? (int) $durations[$itemId] : null);

                    return is_numeric($v) ? (int) $v : null;
                })(),
                'thumbnail' => $this->cover($covers->get($itemId, collect()), $resolvedUrls),
                // The site's icon, for link cards (2026-08-17). Null on
                // every kind that carries no logo-role media — same
                // shape-does-not-change-with-kind contract as thumbnail.
                'favicon' => $this->favicon($covers->get($itemId, collect()), $resolvedUrls),
                // Slice 1a §3.5: media items ship every frame (positional);
                // products joined in 5b — the legacy shop wire carried 271
                // gallery images and retiring it without this loses them.
                // Every other kind ships [] — the wire shape does not change
                // with kind, same contract startsAt/venue/price follow.
                'frames' => in_array($item->kind, ['media', 'product'], true)
                    ? $this->frames(
                        $covers->get($itemId, collect())->filter(fn (object $row): bool => $row->role !== 'logo'),
                        $resolvedUrls
                    )
                    : [],
                // Dated / located / priced facets. Present on every pool item
                // and null off events, so the wire shape does not change with
                // kind — same contract durationSeconds already has.
                'startsAt' => self::iso($occursAt[$itemId] ?? null),
                'startsAtLocal' => $ov('f_occurrence.starts_at_local', $occurrenceDetail[$itemId]->starts_at_local ?? null),
                'endsAtLocal' => $ov('f_occurrence.ends_at_local', $occurrenceDetail[$itemId]->ends_at_local ?? null),
                'timezone' => $ov('f_occurrence.timezone', $occurrenceDetail[$itemId]->timezone ?? null),
                'venue' => $ov('f_place.venue_name', $places[$itemId]->venue_name ?? null),
                'locality' => $ov('f_place.locality', $places[$itemId]->locality ?? null),
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
                'popularityRank' => self::rankFor($ranks, (string) $item->kind, $itemId, $handle, $primary['url'] ?? null),
                // Additive and nullable on EVERY item, never a kind-shaped
                // sub-object — the same contract startsAt / venue / price
                // already follow, so the wire shape does not vary with kind.
                'description' => $ov('f_text.body', $texts[$itemId]->body ?? null),
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
                // Category-first curation (2026-08-18): the item's position
                // WITHIN each collection it belongs to (content.collection_items
                // .position) — the order a menu category / service category
                // reads in. Keyed by collection id; absent = unpositioned.
                'collectionPositions' => $itemStores
                    ->filter(fn ($row) => $row->member_position !== null)
                    ->mapWithKeys(fn ($row) => [(string) $row->collection_id => (int) $row->member_position])
                    ->all(),
                // Slice 6: present on every pool item and null off every kind
                // but `review`, the same contract startsAt / venue / price
                // keep. Attribution is read from f_review — the ONE copy that
                // Manifest::$redactionScopes, content:prune-orphaned-review-pii
                // and the DSAR omission all reach. Do NOT source it from
                // headline: that copy was the §2.2 defect, and the projector
                // now nulls it by contract.
                // W8: which (facet.column) fields the owner has overridden —
                // the sheet reads this to lock/mark those fields instead of
                // guessing from headlineEdited alone.
                // Listen restructure: what KIND of listen item this is, in the
                // vocabulary the dashboard/sitepage speak — album | ep | single
                // | compilation for a release (default album), track, episode;
                // null on every other pool. `album` = a track's parent release.
                'format' => match ($item->kind) {
                    'release' => in_array($music[$itemId]->release_type ?? null, ['album', 'ep', 'single', 'compilation'], true)
                        ? $music[$itemId]->release_type
                        : 'album',
                    'track' => 'track',
                    'episode' => 'episode',
                    default => null,
                },
                'album' => $item->kind === 'track' ? ($music[$itemId]->collection_title ?? null) : null,
                'trackNumber' => $item->kind === 'track' && isset($music[$itemId]->track_number) ? (int) $music[$itemId]->track_number : null,
                'overrides' => array_values(array_filter(
                    array_keys($overridesByKey),
                    fn (string $key) => array_key_exists((string) $itemId, $overridesByKey[$key]),
                )),
                'sources' => $sourcesByItem[$itemId] ?? [],
                'duplicateCandidates' => $candidatesByItem[(string) $itemId] ?? [],
                'review' => isset($reviews[$itemId]) ? [
                    'rating' => $reviews[$itemId]->rating === null ? null : (float) $reviews[$itemId]->rating,
                    'text' => $reviews[$itemId]->text,
                    'authorName' => $reviews[$itemId]->author_name,
                    'authorPhotoUrl' => $reviews[$itemId]->author_photo_url,
                    'authorUri' => $reviews[$itemId]->author_uri,
                    // #API-1 applies here too — content.f_review.reviewed_at is
                    // timestamptz, `review` is a PUBLIC wire field (ITEM_KEYS,
                    // and not dashboard-only), and pdo_pgsql hands it back as
                    // "2026-07-01 10:00:00+00" whose rendering also shifts with
                    // the session TimeZone. Missed on the first pass because the
                    // audit named only the three top-level fields and the
                    // existing assertion passes on SQLite, which returns the
                    // seeded string verbatim and cannot see the difference.
                    'reviewedAt' => self::iso($reviews[$itemId]->reviewed_at),
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

    /** Item kind => the content_popularity_scores family that ranks it. */
    private const KIND_RANK_FAMILY = [
        'product' => 'shop_product', 'link' => 'link_item', 'video' => 'watch_item',
        'track' => 'listen_item', 'release' => 'listen_item', 'episode' => 'listen_item',
        'menu_item' => 'menu_item', 'service' => 'service', 'media' => 'gallery_item',
        'event' => 'engine_item',
    ];

    /**
     * The rank an item carries on the wire: its kind's family, keyed the way
     * that family keys (handle / url / id). Null when unranked.
     *
     * @param  array<string, array<string, int>>  $ranks  family => key => rank
     */
    private static function rankFor(array $ranks, string $kind, string $itemId, string $handle, ?string $url): ?int
    {
        $family = self::KIND_RANK_FAMILY[$kind] ?? null;
        if ($family === null || ! isset($ranks[$family])) {
            return null;
        }
        $key = match ($kind) {
            'product' => $handle,
            'link' => (string) $url,
            default => $itemId,
        };

        return $key !== '' ? ($ranks[$family][$key] ?? null) : null;
    }

    /**
     * Popularity ranks for a site across every item family (family => key => rank).
     *
     * Same key and TTL as PublicIntegrationController (CCG-102) on purpose:
     * two different keys would silently halve a single-flight cache that
     * exists because this read used to hit Postgres on every public request.
     *
     * @return array<string, array<string, int>>
     */
    private function popularityRanks(Site $site): array
    {
        $ranks = $this->cache->rememberLocked(
            CacheKeyGenerator::sitePopularityRanks((string) $site->id),
            self::POPULARITY_CACHE_TTL_SECONDS,
            fn () => $this->popularity->forSite((string) $site->id),
        );

        return $ranks;
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
     * A collection's public display name, or null when the stored label is
     * really just its identifier wearing a name's clothes.
     *
     * `content.collections.label` is NOT NULL, so every write path has to put
     * SOMETHING there — and for a machine-created collection the honest value
     * is often the id itself. Slice 5b established the rule for stores whose
     * name was never fetched (`label === external_ref` → null) precisely so a
     * raw brand id like "75102060779" could never publish as a store-card name
     * on a CDN-cached page.
     *
     * Slice 4 needed it one step wider. A menu ordering platform's collection
     * ref is namespaced (`order:doordash`) while its label is the bare slug
     * (`doordash`), so the equality test missed it and the wire published
     * "doordash" and "uber-eats" as display names — strings a consumer can
     * only render WRONGLY, since neither title-cases to "DoorDash" or
     * "Uber Eats". Publishing null and letting the consumer map `provider`
     * is strictly better than publishing a name that is guaranteed incorrect.
     *
     * A real display-name vocabulary for the ordering platforms belongs to
     * Phase 6, which promotes them to first-class surfaces with brand
     * metadata. Minting one here would be a second source of truth for it.
     */
    private function publicCollectionName(string $label, string $externalRef, string $collectionRef): ?string
    {
        foreach (array_unique([$externalRef, $collectionRef]) as $ref) {
            // The bare ref, or the ref minus its `kind:` namespace prefix.
            if ($label === $ref || str_ends_with($ref, ':'.$label)) {
                return null;
            }
        }

        return $label;
    }

    /**
     * The vendor's service modes (DELIVERY / PICKUP), for the menus pool only.
     *
     * Store-level metadata, not per-item content: which modes a restaurant
     * offers describes the restaurant, so it rides the pool ENVELOPE the way
     * `stats` does rather than being stamped onto every dish. Owner ruling
     * 2026-08-15 (Unit 6) — the alternative was dropping it as a regression.
     *
     * Null for every other pool, and buildPools() spreads it only when
     * non-null, so no other pool's payload changes shape.
     *
     * @return list<string>|null
     */
    private function diningModesFor(string $pool, Site $site): ?array
    {
        if ($pool !== 'menus') {
            return null;
        }

        $raw = DB::connection('pgsql')->table('site.menus')
            ->where('user_id', $site->user_id)
            ->whereNull('deleted_at')
            ->value('dining_modes');

        // menus_dining_modes_is_array permits NULL or a jsonb array, and the
        // Postgres driver hands jsonb back as a STRING while the SQLite mirror
        // stores TEXT — decode either, and treat an empty array as absent so
        // the key does not appear carrying nothing.
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        return is_array($decoded) && $decoded !== [] ? array_values($decoded) : null;
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
            // A menu category has no storefront sidecar, so every store-only
            // field is null on it. The KEY SET never varies — PoolWireShapeTest
            // fails on additions as well as removals, and a frontend
            // destructuring this map must not have to branch on which kind of
            // collection it got. Same contract `price`, `startsAt` and `review`
            // keep on an item.
            //
            // externalRef prefers the STOREFRONT's ref where one exists, so
            // slice 5b's shop behaviour (including the name-nulling rule below)
            // is unchanged, and falls back to the collection's own — which is
            // NOT NULL on every row.
            $externalRef = $row->external_ref === null
                ? (string) $row->collection_ref
                : (string) $row->external_ref;

            $out[(string) $collectionId] = [
                'externalRef' => $externalRef,
                'provider' => $row->provider === null ? null : (string) $row->provider,
                'url' => $row->url === null ? null : (string) $row->url,
                'name' => $this->publicCollectionName((string) $row->label, $externalRef, (string) $row->collection_ref),
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
    /**
     * The platform key a connection carries on the wire. Brand connects store
     * the CATALOG brand key in site.platform_connections.platform
     * (`uber_eats`, `just_eat`, `order_online`, `eat_app`) while every other
     * platform, ItemLinkRules' roster and the dashboard's glyph/roster maps
     * use the hyphenated slug (`uber-eats`). Both leaked onto one wire: an
     * ingest-lane dish read `platform: "uber_eats"` in links[]/sources[]
     * beside a legacy-lane dish's `uber-eats` (session 3, 2026-08-18, F28),
     * so the dashboard drew no glyph and the per-platform dedupe missed. One
     * spelling out: the slug.
     */
    private static function wirePlatform(?string $platform): ?string
    {
        return $platform === null || $platform === '' ? $platform : str_replace('_', '-', $platform);
    }

    /**
     * `source` on each link (the sheet's badge + whether it can be removed):
     *   synced — a connection's source listed it; the platform keeps it current
     *   own    — the manual lane's own f_link (a hand-added or bio-found item's
     *            URL); it is the item, not a per-platform extra, and there is
     *            no item_links row to delete
     *   manual — a hand-saved item_links row for a platform no source covers
     *
     * A manual-source row carries no connection, so its platform is derived
     * from the URL host (roster match) rather than left NULL — NULL drew a
     * blank glyph, titled the row by its host, and slipped past the
     * per-platform dedupe, so the same eventbrite URL listed twice (once from
     * f_link, once synthesised from the offer). Dedupe is by platform AND by
     * URL, so an unrostered host cannot double up either.
     */
    private function linkSet(Collection $sourceRows, Collection $manualRows): array
    {
        $links = [];
        $seen = [];
        $seenUrls = [];

        foreach ($sourceRows as $row) {
            $url = (string) $row->url;
            $isOwn = ($row->source_kind ?? null) === 'manual';
            $platform = $row->platform !== null && $row->platform !== '' ? (string) $row->platform : null;
            if ($platform === null && $isOwn) {
                $platform = ItemLinkRules::platformForUrl($url);
            }
            $urlKey = self::linkUrlKey($url);
            if (($platform !== null && isset($seen[$platform])) || isset($seenUrls[$urlKey])) {
                continue;
            }
            if ($platform !== null) {
                $seen[$platform] = true;
            }
            $seenUrls[$urlKey] = true;
            $links[] = ['platform' => $platform, 'url' => $url, 'source' => $isOwn ? 'own' : 'synced'];
        }

        foreach ($manualRows as $row) {
            $urlKey = self::linkUrlKey((string) $row->url);
            if (isset($seen[$row->platform]) || isset($seenUrls[$urlKey])) {
                continue;
            }
            $seen[$row->platform] = true;
            $seenUrls[$urlKey] = true;
            $links[] = ['platform' => (string) $row->platform, 'url' => (string) $row->url, 'source' => 'manual'];
        }

        return $links;
    }

    /** Case-insensitive, scheme- and trailing-slash-insensitive dedupe key. */
    private static function linkUrlKey(string $url): string
    {
        $key = strtolower(trim($url));
        $key = preg_replace('#^https?://(www\.)?#', '', $key) ?? $key;

        return rtrim($key, '/');
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
            // Best quality wins within a role (owner ruling, W5): when several
            // sources gave this item a cover — Apple's 1200px art beside a
            // 300px thumbnail from another platform — pick the largest known
            // area; rows without dims keep the source-priority order they
            // arrived in and only win when nothing measured exists.
            $candidates = $rows->where('role', $role)->values();
            $best = null;
            $bestArea = -1;
            foreach ($candidates as $row) {
                $hit = $resolved[(string) $row->asset_id] ?? null;
                // `$hit['url']` needs no `?? ''` — the null check short-circuits
                // first and the shape types url as a plain string. `width`/`height`
                // below DO keep theirs: those are genuinely int|null.
                if ($hit === null || $hit['url'] === '') {
                    continue;
                }
                $area = ($hit['width'] ?? 0) * ($hit['height'] ?? 0);
                if ($area > $bestArea) {
                    $best = $hit['url'];
                    $bestArea = $area;
                }
            }
            if ($best !== null) {
                return $best;
            }
        }

        return null;
    }

    /** The logo-role asset's URL — a link's favicon — or null. */
    private function favicon(Collection $rows, array $resolved): ?string
    {
        $row = $rows->firstWhere('role', 'logo');
        $url = $row !== null ? ($resolved[(string) $row->asset_id]['url'] ?? null) : null;

        return $url !== null && $url !== '' ? $url : null;
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
        // The cover (or first still) is the poster every video frame carries.
        $poster = null;
        foreach ($rows as $row) {
            if ((string) $row->role !== 'video' && isset($resolved[(string) $row->asset_id])) {
                $poster = $resolved[(string) $row->asset_id]['url'];
                break;
            }
        }
        $frames = [];
        foreach ($rows as $row) {
            $hit = $resolved[(string) $row->asset_id] ?? null;
            if ($hit === null) {
                continue;
            }
            $isVideo = (string) $row->role === 'video' || str_starts_with((string) ($row->mime_type ?? ''), 'video/');
            $frames[] = [
                'url' => $hit['url'],
                'width' => $hit['width'],
                'height' => $hit['height'],
                'role' => (string) $row->role,
                // kind + poster (R7): apps/pages MediaCard plays a `video`
                // frame and falls back to its poster; every still is `image`.
                'kind' => $isVideo ? 'video' : 'image',
                'poster' => $isVideo ? $poster : null,
                'alt' => $row->alt_text === null ? null : (string) $row->alt_text,
            ];
        }

        return $frames;
    }
}
