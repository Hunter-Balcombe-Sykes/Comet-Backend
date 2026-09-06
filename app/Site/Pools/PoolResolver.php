<?php

namespace App\Site\Pools;

use App\Models\Core\Site\Site;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Analytics\Concerns\EscalatesRepeatedFaults;
use App\Services\Analytics\ContentPopularityReader;
use App\Services\Analytics\ItemFamily;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Content\ContentItemSlugAllocator;
use App\Services\Media\InstagramMediaUrl;
use App\Services\Media\MediaMirror;
use App\Services\Media\MediaUrlResolver;
use App\Services\Platforms\ConnectionDisplayName;
use App\Services\Platforms\DisplaySettingsFilter;
use App\Site\Actions\ActionSettings;
use App\Site\Sections\SectionCandidates;
use App\Support\UrlSafety;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
 * popularityRank carries the item's rank in its kind's family
 * (analytics.content_popularity_scores, keyed by item id for every family
 * since 2026-08-23), null until the scoring job has ranked it. The wire
 * carries the field either way so the shape doesn't change under the FE.
 */
class PoolResolver
{
    use EscalatesRepeatedFaults;

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
        'thumbnail', 'thumb', 'pending', 'favicon', 'frames', 'startsAt', 'startsAtLocal', 'endsAtLocal',
        'timezone', 'venue', 'locality', 'price', 'availability', 'links',
        'popularityRank', 'description', 'vendor', 'variants', 'collectionIds',
        'review', 'selected', 'origin', 'overrides', 'sources',
        'format', 'album', 'trackNumber', 'collectionPositions', 'duplicateCandidates',
    ];

    /** Dashboard-only item keys, stripped before the public wire. */
    public const DASHBOARD_ONLY_ITEM_KEYS = ['selected', 'overrides', 'sources', 'duplicateCandidates', 'pending'];

    /** Public fields of one store card in a pool's `collections` map. */
    public const STORE_KEYS = [
        'externalRef', 'provider', 'url', 'name', 'currency',
        'favicon', 'logo', 'logoMarkSvg', 'discountCode', 'position', 'popularityRank',
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
        private readonly InstagramMediaUrl $instagramUrls,
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
    public function hasSelection(Site $site, string $pool, ?object $section = null, ?Collection $curation = null): bool
    {
        // Both pre-reads are injectable, exactly as plan()'s are (Nightwatch
        // #499, 2026-09-05): the presence probe asks this per pool, and each
        // ask paid a sections read and a section_items read of its own. A
        // caller holding preloadSections() / preloadCuration() output hands
        // them in — two shared queries for every pool instead of two apiece.
        // A lone call still reads its own. The per-pool fault isolation the
        // probe depends on is unchanged: this still runs ONE pool's candidate
        // query, so the caller can still wrap each pool separately — and a
        // test double that overrides this method still intercepts every probe.
        $section ??= $this->provisioner->ensure($site, $pool);

        // SCALE-13: `id`/`created_at` are read nowhere downstream, and excluded
        // rows have no pruning path, so a full-row select grows unbounded with
        // a site's lifetime for no benefit.
        $curation ??= DB::connection('pgsql')->table('site.section_items')
            ->where('section_id', $section->id)
            ->get(['section_id', 'item_id', 'state', 'sort_key']);

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

        // The same canonical facet read itemPayloads() publishes from. This
        // probe only asks "would anything survive?", so it never renders a row
        // — but it must decide over the SAME rows resolve() will, or nav
        // advertises a page whose pool is empty behind it.
        $suppressed = $this->reviewsSuppressedByOwner($site, $candidates)
            + $this->reviewsOutsidePersonScope($site, $candidates, ReviewFacets::forItems($candidates));

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
     *   stats: array{ratingAvg: ?float, ratingCount: ?int, summaryText: ?string, scope: 'listing'|'published', platform: ?string, placeId: ?string}|null,
     *   diningModes: list<string>|null,
     *   unavailablePoolLocks: list<string>,
     * }
     */
    public function resolve(Site $site, string $pool): array
    {
        // plan → hydrate → assemble (split 2026-08-24 for the actions
        // endpoint): a single pool resolves exactly as it always did, and
        // PoolWire::forSite runs the SAME three steps with every pool's ids
        // in ONE hydrate pass — ~20 facet queries total instead of ~20 per
        // pool, which is what took GET /site/actions past 60s of round trips
        // on a high-latency link (measured 2026-08-24: 244 queries, 58.8s of
        // it pools hydration).
        $plan = $this->plan($site, $pool);
        [$payloads, $stores] = $this->itemPayloads(
            $site,
            array_values(array_unique([...$plan['selectionIds'], ...$plan['libraryIds']])),
            // #API-7: THIS is the dashboard entry point (PoolController::show,
            // PoolItemCreateController::store), the only reader of
            // duplicateCandidates. PoolWire's hydrateItems() seam opts out.
            withDuplicateCandidates: true,
        );

        return $this->assemble($site, $pool, $plan, $payloads, $stores);
    }

    /**
     * Step 1 of resolve(): which item ids this pool would publish and list —
     * curation, rule candidates, the library, live-pin filtering. No item
     * hydration happens here, so a caller batching several pools can union
     * the ids and hydrate once.
     *
     * @param  bool  $withLibrary  false skips the LIBRARY_LIMIT-row library read and
     *                             returns `libraryIds: []` — for a caller that will
     *                             assemble() with withLibrary false anyway (PoolWire)
     * @return array{
     *   pinned: list<string>,
     *   ruleIds: list<string>,
     *   autoSet: array<string, int>,
     *   selectionIds: list<string>,
     *   libraryIds: list<string>,
     * }
     */
    public function plan(Site $site, string $pool, ?object $section = null, ?Collection $curation = null, bool $withLibrary = true): array
    {
        // Both pre-reads are injectable so a batching caller (PoolWire) can
        // supply every pool's sections and curation from two shared queries;
        // a lone resolve() fetches its own exactly as before.
        $section ??= $this->provisioner->ensure($site, $pool);

        $curation ??= DB::connection('pgsql')->table('site.section_items')
            ->where('section_id', $section->id)
            ->get(['section_id', 'item_id', 'state', 'sort_key']);

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

        // The library read is skippable (Nightwatch #499): a caller that
        // assembles with withLibrary false — PoolWire, the public wire — never
        // reads these ids, and this is a LIBRARY_LIMIT-row scan per pool.
        $libraryIds = [];
        if ($withLibrary) {
            $libraryQuery = DB::connection('pgsql')->table('content.items')
                ->where('user_id', $site->user_id)
                ->whereIn('kind', PoolRegistry::kinds($pool))
                ->whereNull('removed_at');
            // Disconnect = hide (W2): the library lists only items with a live
            // source (manual, or a present + active connection). #FU-2: the scope
            // pins its own source/connection hops by correlating to the outer items
            // row, so plan()'s liveness verdict and itemPayloads()'s can no longer
            // disagree about the same mislinked item.
            LiveSourceScope::apply($libraryQuery, 'content.items');
            $libraryIds = $libraryQuery
                ->orderByDesc('last_seen_at')
                ->limit(self::LIBRARY_LIMIT)
                ->pluck('id')
                ->all();
        }

        // Pins from a removed connection hide too — the pin row stays (a
        // reconnect brings it back), but it does not publish.
        if ($pinned !== []) {
            // #FU-2: owner-scoped, because LiveSourceScope::apply() now pins its
            // own hops by CORRELATING to this row's user_id — a correlation is
            // only as strong as the outer query's own tenancy, and a pin row
            // (site.section_items) carries none. It also matches itemPayloads(),
            // which drops a foreign pin anyway; here it stops one occupying a
            // slot on the way.
            $livePinsQuery = DB::connection('pgsql')->table('content.items')
                ->whereIn('id', $pinned)
                ->where('user_id', $site->user_id);
            LiveSourceScope::apply($livePinsQuery, 'content.items');
            $livePinned = $livePinsQuery->pluck('id')->flip()->all();
            $pinned = array_values(array_filter($pinned, fn ($id) => isset($livePinned[$id])));
            $selectionIds = [];
            foreach ([...$pinned, ...$ruleIds] as $itemId) {
                if (! isset($excluded[$itemId])) {
                    $selectionIds[] = $itemId;
                }
            }
        }

        return [
            'pinned' => $pinned,
            'ruleIds' => $ruleIds,
            'autoSet' => $autoSet,
            'selectionIds' => $selectionIds,
            'libraryIds' => $libraryIds,
        ];
    }

    /**
     * The batched pre-reads plan() accepts (2026-08-24): every pool's section
     * in one ensureMany, and every section's curation rows in one whereIn —
     * PoolWire's per-pool loop stops paying a round trip apiece for them.
     *
     * @param  list<string>  $pools
     * @return array<string, object> keyed by pool
     */
    public function preloadSections(Site $site, array $pools): array
    {
        if ($pools === []) {
            return [];
        }

        return $this->provisioner->ensureMany($site, $pools);
    }

    /**
     * @param  array<string, object>  $sections  keyed by pool (preloadSections)
     * @return array<string, Collection<int, \stdClass>> curation rows keyed by SECTION id
     */
    public function preloadCuration(array $sections): array
    {
        $ids = array_values(array_unique(array_map(
            static fn (object $section): string => (string) $section->id,
            $sections,
        )));
        if ($ids === []) {
            return [];
        }

        return DB::connection('pgsql')->table('site.section_items')
            ->whereIn('section_id', $ids)
            ->get(['section_id', 'item_id', 'state', 'sort_key'])
            ->groupBy('section_id')
            ->all();
    }

    /**
     * Step 2, exposed for batching: render-ready payloads for a set of item
     * ids spanning ANY number of pools — itemPayloads() is pool-agnostic (it
     * gates its shop/menu-only reads on the kinds actually present).
     * Tuple, not a private property: collectionsFor() needs the store rows
     * this already fetched, and stashing them on $this would make resolve()
     * order-dependent (and unsafe under a reused instance).
     *
     * @param  list<string>  $ids
     * @param  bool  $withDuplicateCandidates  #API-7 (= SCALE-9, remainder sweep): run the
     *                                         content.identity_candidates read. Defaults OFF because
     *                                         this seam's only production caller is PoolWire, whose
     *                                         three consumers all strip the key
     *                                         (DASHBOARD_ONLY_ITEM_KEYS) before anyone reads it.
     *                                         Opt-in, not opt-out: a future caller that forgets the
     *                                         flag loses a dashboard chip, which is visible;
     *                                         forgetting to pass `false` would silently put a query
     *                                         back on the hot path, which is not.
     * @return array{array<string, array<string, mixed>>, Collection<string, object>}
     */
    public function hydrateItems(Site $site, array $ids, bool $withDuplicateCandidates = false): array
    {
        return $this->itemPayloads($site, $ids, $withDuplicateCandidates);
    }

    /**
     * Step 3 of resolve(): the pool's wire shape from its plan and an
     * (possibly shared) payload map. Only reads $payloads entries for its own
     * plan's ids, so a superset map from a batched hydrate changes nothing.
     *
     * @param  array{pinned: list<string>, ruleIds: list<string>, autoSet: array<string, int>, selectionIds: list<string>, libraryIds: list<string>}  $plan
     * @param  array<string, array<string, mixed>>  $payloads
     * @param  Collection<string, object>  $stores
     * @param  bool  $withLibrary  SCALE-2: PoolWire's three consumers
     *                             (IndividualProfilePayloadBuilder, ActionCandidates, ComputeContentPopularityScores)
     *                             read only `selection`/`collections` off this method's output — the
     *                             library is served exclusively by PoolController::show -> resolve(),
     *                             which never passes false. Default true keeps resolve() and
     *                             PoolController byte-identical; PoolWire passes false so a public
     *                             build never hydrates and then discards up to 9 x 500 library items.
     * @return array{
     *   selection: list<array<string, mixed>>,
     *   library: list<array<string, mixed>>,
     *   latestItemId: string|null,
     *   collections: array<string, array<string, mixed>>,
     *   stats: array{ratingAvg: ?float, ratingCount: ?int, summaryText: ?string, scope: 'listing'|'published', platform: ?string, placeId: ?string}|null,
     *   diningModes: list<string>|null,
     *   unavailablePoolLocks: list<string>,
     * }
     */
    public function assemble(Site $site, string $pool, array $plan, array $payloads, Collection $stores, bool $withLibrary = true): array
    {
        $pinned = $plan['pinned'];
        $autoSet = $plan['autoSet'];
        $selectionIds = $plan['selectionIds'];
        $libraryIds = $plan['libraryIds'];

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
        // Item 2 (2026-09-01): the media deck leads with its videos — every
        // item shipping a playable video frame ranks ahead of every still,
        // newest-first within each class (the mode order IS the within-class
        // order). 'newest' only: smart is an explicit engagement ranking and
        // manual is the owner's hand order (pins first) — both owner choices
        // this default must not fight. In manual the auto tail still leads
        // with videos via ruleCandidates()' own ORDER BY.
        if ($pool === 'media' && $mode === 'newest') {
            $selection = self::videosLead($selection);
        }
        $collections = PoolOrdering::orderCollections($mode, $this->collectionsFor($pool, $site, $selection, $stores), $selection);
        // #RANK-2: a lock that couldn't be placed (item not in the
        // selection, or its position collided with another lock in the same
        // category) is reported here instead of silently dropped — mirrors
        // ActionSlots' `unavailable` contract. Dashboard-only: PoolWire's
        // public wire allowlists its keys explicitly and never forwards this.
        $unavailablePoolLocks = [];
        if ($mode !== 'manual') {
            // Owner locks (settings.pool_locks) hold items in place while the
            // mode fills the rest — the dashboard's "Lock in position". A
            // category pool (menus / services) displays grouped by category
            // (D4), so its locks hold a position WITHIN the item's category
            // and the wire is flattened in category order.
            $lockResult = isset(ItemFamily::CATEGORY_FAMILIES[$pool])
                ? PoolOrdering::applyLocksPerCollection($selection, $settings->poolLocksFor($pool), $collections)
                : PoolOrdering::applyLocks($selection, $settings->poolLocksFor($pool));
            $selection = $lockResult['items'];
            $unavailablePoolLocks = $lockResult['unavailable'];
        }

        $library = [];
        if ($withLibrary) {
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
        }

        return [
            'selection' => $selection,
            'library' => $library,
            'latestItemId' => PoolRegistry::carriesLatestTag($pool)
                ? $this->latestItemId($selection)
                : null,
            'collections' => $collections,
            'stats' => $this->statsFor($pool, $site, $selection),
            'diningModes' => $this->diningModesFor($pool, $site),
            // W8: the platforms a manual link may be added for on this pool —
            // ItemLinkRules::ROSTER, so the dashboard stops hand-copying it.
            'linkRoster' => ItemLinkRules::rosterFor($pool),
            'unavailablePoolLocks' => $unavailablePoolLocks,
        ];
    }

    /**
     * Stable class partition (Item 2): items with a servable video frame
     * first, stills after, relative order preserved either side. Classified
     * on the RESOLVED frames rather than item_media rows on purpose — a video
     * nothing can play (expired unmirrored source; frames() dropped it) must
     * not lead the deck with a frozen card.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function videosLead(array $items): array
    {
        $videos = [];
        $stills = [];
        foreach ($items as $item) {
            $hasVideo = false;
            foreach ((array) ($item['frames'] ?? []) as $frame) {
                if (($frame['kind'] ?? null) === 'video') {
                    $hasVideo = true;
                    break;
                }
            }
            $hasVideo ? $videos[] = $item : $stills[] = $item;
        }

        return [...$videos, ...$stills];
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
     * A VENUE aggregate, and therefore only ever a venue's to publish. The
     * person scope (2026-09-01) reached the review cards and stopped at the
     * badge above them, which is the same disclosure with the numbers left in:
     * ollies is one barista's page carrying a hair salon's Fresha listing, and
     * because the only review that survived the scope came off that listing,
     * this query reached its stats row and the coffee shop published
     * "5/5 — Based on 174 reviews". The venue's OWN 4.2 from 3,925 would have
     * been no better — an aggregate over reviews that are not this person's is
     * not this person's aggregate, whichever venue it is right about, and a
     * count of 3,925 is meaningless beside one shown review.
     *
     * So a person-scoped page publishes no venue aggregate at all, and the one
     * number it may show is computed over the reviews it ACTUALLY published —
     * a claim the reader can check by counting the cards under it, which is
     * exactly the property the listing's aggregate lacked.
     *
     * #LABEL-1, the half that scoping cannot reach: WHOSE number this is.
     * Both branches now state their own provenance — scope, platform, and the
     * place where a vendor gives us one — because the consumer folds this
     * block onto its Google surface without asking, and a Fresha average
     * published under Google's name is a false attribution on a page of any
     * account type, not only a business's.
     *
     * @param  list<array<string, mixed>>  $selection
     * @return array{ratingAvg: ?float, ratingCount: ?int, summaryText: ?string, scope: 'listing'|'published', platform: ?string, placeId: ?string}|null
     */
    private function statsFor(string $pool, Site $site, array $selection): ?array
    {
        if (! PoolRegistry::carriesSourceStats($pool) || $selection === []) {
            return null;
        }

        if ($this->pageIsPersonScoped($site)) {
            return self::statsOverPublishedReviews($selection);
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
            // #FU-2: the connection's OWN tenancy, stated in the ON clause.
            // `where` here would collapse the left join and drop every
            // manual-source stat — connection_id is NULLABLE.
            ->leftJoin('site.platform_connections as stats_conn', function ($j) use ($site) {
                $j->on('stats_conn.id', '=', 'stats_src.connection_id')
                    ->where('stats_conn.user_id', '=', (string) $site->user_id);
            })
            ->whereIn('si.item_id', array_column($selection, 'id'))
            // #W1-SEC-10: state tenancy on the source rather than inheriting it
            // from the selection's ids. The badge summarises a SOURCE, and this
            // query reaches sources through source_items, which carries no
            // user_id — one mislinked source_id and the page publishes another
            // account's star rating.
            //
            // #FU-2 (2026-08-31): the stats_conn hop — stats_src.connection_id,
            // the second FK out of this source — is pinned in the ON clause
            // above, not here. A foreign connection now joins as NULL, which
            // constrainToLiveSource() reads as "not live": fail-closed, so a
            // mislinked source loses its badge rather than keeping a
            // disconnected listing's rating alive.
            ->where('stats_src.user_id', $site->user_id)
            // A source_item retired by absence folding does not carry the badge
            // either — the same reading LiveSourceScope takes for the items.
            ->whereNull('si.removed_at')
            // A row with no average cannot BE a badge — the sitepage renders
            // nothing when rating is null — so it must not win the ordering and
            // shadow a row that can. ProjectionWriter writes every source_stats
            // column on every run rather than only the ones the run carried, so
            // a Google response with place_rating_count and no place_rating
            // leaves exactly this row: a countable blank. Ordered on count
            // alone it outranked a live 4.2 and took the badge off the page.
            ->whereNotNull('ss.rating_avg');

        // The tie-break, settled 2026-09-01 and now TOTAL. ollies holds two
        // google-business connections for one place_id (the 02:40:59 one
        // retired 91 seconds later) and so two stats rows for one coffee shop;
        // on any day the venue's count has not moved they differ only in
        // updated_at, and `orderByDesc(rating_count)` alone left the winner to
        // whatever row the engine handed back — a badge that could differ
        // between two requests for the same page.
        //
        //   count   — first, and NULLS LAST. Across DIFFERENT places the
        //             busier listing is still the defensible one, and a quiet
        //             venue's nightly refresh must not outrank a busy one's
        //             weekly. Postgres sorts DESC as NULLS FIRST, so a row with
        //             an average but no count led the order there while SQLite
        //             (NULLS LAST) put it where we want it — a disagreement the
        //             test suite could not have shown us. Spelled as a portable
        //             is-null key rather than `NULLS LAST` for that reason.
        //   updated — between two rows for the SAME place the fresher is the
        //             current truth about it (4.2/3925 read 08-29 over 4.2/3919
        //             read 08-26). Counts only move when the venue's do, so
        //             this is the criterion that actually separates duplicates.
        //   source  — arbitrary, and that is the point: a total order means one
        //             answer forever rather than a stable-looking one.
        $query->orderByRaw('case when ss.rating_count is null then 1 else 0 end')
            ->orderByDesc('ss.rating_count')
            ->orderByDesc('ss.updated_at')
            ->orderBy('ss.source_id');

        LiveSourceScope::constrainToLiveSource($query, 'stats_src', 'stats_conn');

        // #LABEL-1: the connection the winning row hangs off, so the wire can
        // NAME the platform it is quoting. Free — stats_conn is already joined
        // (and already tenancy-pinned) for the liveness check below.
        $row = $query->first([
            'ss.rating_avg', 'ss.rating_count', 'ss.summary_text',
            'stats_conn.platform as platform', 'stats_conn.place_id as place_id',
        ]);

        if ($row === null) {
            return null;
        }

        // Null-preserving: f_review.rating's bare-cast trap applies here too —
        // (float) null is 0.0, which would publish a zero-star business.
        return [
            'ratingAvg' => $row->rating_avg === null ? null : (float) $row->rating_avg,
            'ratingCount' => $row->rating_count === null ? null : (int) $row->rating_count,
            'summaryText' => $row->summary_text,
            // #LABEL-1 (2026-09-01). The badge fix stopped a hair salon's 5.0
            // publishing on a barista's page; it did not stop a Fresha 5.0
            // publishing as a GOOGLE rating, because the wire never said whose
            // number this was. resolve-site-content.ts folds whatever `stats`
            // holds onto googleBusinessSurface unconditionally, so the salon's
            // Fresha average came out under the Google listing's name — on a
            // person's page and a venue's alike, which is the half the
            // 103626f17 residual note called business-only and got wrong.
            //
            // `scope` first, because it is the claim being made and it changes
            // what the other two mean: 'listing' is the connected place's OWN
            // published aggregate, over a corpus we did not choose; 'published'
            // is ours, computed over the cards on the page. Rendering the
            // second as the first is the mislabelling even when the platform
            // happens to match.
            'scope' => 'listing',
            // The vendor slug in the wire's own spelling (google-business,
            // fresha), NULL for a manual source, which has no connection and
            // therefore no platform to name. Never a fallback guess: an
            // unnamed platform must read as "we do not know", because the
            // consumer's only safe response to that is to attribute nothing.
            'platform' => self::wirePlatform($row->platform === null ? null : (string) $row->platform),
            // Which PLACE at that vendor, where the vendor gives us one.
            // place_id is the Google Place ID mirror (FOUND-18) and no other
            // connector fills it, so this is null off google-business — the
            // literal "where known". It matters because ollies holds two
            // google-business connections for one place_id: a consumer that
            // wants to check the badge belongs to the listing it is drawn
            // beside needs the place, not the connection row that won a
            // tie-break.
            'placeId' => $row->place_id === null || $row->place_id === '' ? null : (string) $row->place_id,
        ];
    }

    /**
     * The badge a PERSON's page may carry: the average and the count of the
     * reviews this page actually published, and no prose.
     *
     * $selection is post-suppression by construction — the owner's display
     * toggle, LiveSourceScope and reviewsOutsidePersonScope() have all already
     * run — so this aggregates exactly the cards the visitor is looking at and
     * cannot drift from them. Nothing is queried: itemPayloads() already put
     * every rating on the wire.
     *
     * summaryText is null and stays null. summary_text is Google's own prose
     * about the BUSINESS, written over the whole review corpus; there is no
     * person-scoped version of it to compute and no honest way to re-scope the
     * one we hold.
     *
     * Null rather than a zero when no published review carries a rating —
     * f_review.rating's bare-cast trap in aggregate form: 0/0 is not a
     * zero-star person, it is no badge. Rounded to the vendors' own precision
     * so the wire value is the rendered one (the sitepage prints toFixed(1)).
     *
     * #LABEL-1: `scope` is 'published' here and that is the whole point — this
     * number is OURS, an average over the cards on the page, and it must never
     * be rendered as a platform's rating for a listing. `platform` names the
     * vendor the RATED reviews came from only when they all came from one, and
     * `placeId` is null unconditionally: an average over a person's reviews is
     * not any place's aggregate, so handing the consumer a place identity here
     * would re-open the exact door this field was added to close.
     *
     * @param  list<array<string, mixed>>  $selection
     * @return array{ratingAvg: ?float, ratingCount: ?int, summaryText: ?string, scope: 'listing'|'published', platform: ?string, placeId: ?string}|null
     */
    private static function statsOverPublishedReviews(array $selection): ?array
    {
        $ratings = [];
        $platforms = [];
        foreach ($selection as $item) {
            $rating = $item['review']['rating'] ?? null;
            if ($rating === null) {
                continue;
            }
            $ratings[] = (float) $rating;
            // Only the platforms behind the reviews that COUNTED. An unrated
            // review is not in the average and so has no claim on its label:
            // a Fresha card with no score must not make a Google-only average
            // read "Google and Fresha". Read off the payload's own sources
            // list — itemPayloads() already built it, so this method keeps its
            // no-extra-query property.
            //
            // #LIFE-3 again: that list deliberately KEEPS a paused connection
            // so the item sheet can badge it `active: false`, and a paused
            // connection is not publishing anything. A review still on the
            // page while one of its sources is paused is there because another
            // source is live; naming the paused one would credit a platform
            // the owner switched off.
            foreach (is_array($item['sources'] ?? null) ? $item['sources'] : [] as $source) {
                $platform = $source['platform'] ?? null;
                if (is_string($platform) && $platform !== '' && ($source['active'] ?? false) === true) {
                    $platforms[$platform] = true;
                }
            }
        }

        if ($ratings === []) {
            return null;
        }

        return [
            'ratingAvg' => round(array_sum($ratings) / count($ratings), 1),
            // count($ratings), NOT count($selection). The two are equal only
            // while every published review carries a score, and an
            // employee-scoped Fresha source publishes unrated reviews as
            // readily as rated ones — that is the shape ollies actually has.
            // Counting the selection would advertise "2 reviews" over a
            // one-review average: a count the reader can no longer check by
            // counting the cards, which is the ONE property this badge was
            // rebuilt to have.
            'ratingCount' => count($ratings),
            'summaryText' => null,
            'scope' => 'published',
            // One platform or none. Two vendors behind one average is not a
            // platform's rating in any sense a reader would recognise, and
            // naming either would be picking a winner; null is the honest
            // answer and the consumer's cue to attribute nothing.
            'platform' => count($platforms) === 1 ? self::wirePlatform((string) array_key_first($platforms)) : null,
            'placeId' => null,
        ];
    }

    /**
     * Whether this page is one person's rather than a venue's — the same
     * judgement reviewsOutsidePersonScope() makes about the review CARDS,
     * asked from one place so the badge above them cannot disagree with them.
     *
     * An unresolvable owner is person-scoped: fail closed. The cards already
     * read it that way (a site whose owner row cannot be loaded suppresses
     * every review), and a venue aggregate published over a suppressed pool
     * would be the whole incident again with the cards missing.
     */
    private function pageIsPersonScoped(Site $site): bool
    {
        $pro = $site->user;

        return $pro === null || AccountCapabilities::for($pro)->reviews_scoped_to_person;
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
     * @param  bool  $withDuplicateCandidates  see hydrateItems(). Deliberately has no
     *                                         default: both call sites state which audience
     *                                         they are building for.
     * @return array{array<string, array<string, mixed>>, Collection<string, object>}
     *
     * #W1-SEC-10: the $ids list is owner-scoped by the content.items read
     * directly below, but the link tables it travels through — source_items,
     * collection_items, item_media, offers, the facet tables — carry no user_id
     * of their own, so without an explicit predicate the one tenancy check in
     * this method is the FIRST query, and a single mislinked source_id,
     * collection_id or asset_id anywhere upstream (a writer bug, a hand-run SQL
     * fix) publishes another account's row on this page. Every join below onto a
     * table that OWNS a user_id therefore states it: content.sources (f_catalog,
     * f_link, offers, source_items), content.collections, content.storefronts
     * (in the ON clause — a where would collapse the left join) and
     * content.media_assets. All four columns are NOT NULL. Three lead a real
     * index — idx_content_sources_user, idx_collections_user, and
     * media_assets_fingerprint_unique (user_id, fingerprint); storefronts'
     * (user_id, provider, external_ref) index is PARTIAL, but that join arrives
     * on storefronts' PRIMARY KEY, so the predicate filters the single row
     * already fetched. Nothing here changes a plan. Same posture the
     * identity_candidates read already takes.
     *
     * CLOSED 2026-08-31 (#FU-2), and stated here because it was open long
     * enough that its absence was itself documented: content.sources.connection_id,
     * the second FK hop out of the pinned sources. It is NULLABLE
     * (20260727140000 L30) — a kind='manual' source always carries NULL — so the
     * predicate lives in the ON CLAUSE of each left join, never in a `where`: a
     * `where` on a left-joined column silently converts the join to an INNER one
     * and every manual-lane item vanishes from the public wire. Four joins carry
     * it (f_link, offers, source_items here; source_stats in statsFor()), and the
     * two plain connection reads below carry a `where` of their own —
     * $payloadByConnection is defence-in-depth (it reads a PK list a pinned join
     * produced), but the ingest.sources read is NOT: ingest.sources.connection_id
     * is a SEPARATE FK with a SEPARATE writer, so a foreign ingest row naming
     * this owner's connection would badge an item with another account's sync
     * cadence with nothing else to stop it. site.platform_connections.user_id and
     * ingest.sources.user_id are both NOT NULL, so neither predicate can silently
     * match nothing, and both joins arrive on a PRIMARY KEY — no plan changes.
     *
     * A foreign connection now joins as NULL, which every consumer here already
     * reads as "not live" (LiveSourceScope::constrainToLiveSource, or the inline
     * kind/deleted_at predicate on $sourceRows), so the row is dropped rather
     * than publishing a foreign platform label, display name or fallback url.
     * reviewsSuppressedByOwner() needed a SECOND predicate on top of the pin —
     * see its own docblock: a NULL row there VOTES rather than disappearing.
     *
     * NOTHING ON THIS HOP IS NOW UNPINNED — the two residuals this paragraph
     * used to name were closed the same day (#FU-2 residuals), and how they are
     * pinned differs from here because their shapes do:
     *
     *  - ItemLinkRules::syncedPlatformsFor() takes a REQUIRED $userId and states
     *    both hops as plain `where`s. Its joins are INNER by construction, so a
     *    `where` cannot collapse anything; there is no manual lane to lose,
     *    because a manual source's NULL connection_id never survives that inner
     *    join in the first place. It is dashboard-only, but a foreign
     *    connection reaching it VETOED the owner's own manual link.
     *  - LiveSourceScope::apply() carries its own copy of both hops and pins
     *    them by CORRELATION to the outer items row (lpc.user_id / lsrc.user_id
     *    = the item's user_id), so a caller with no user VALUE in hand —
     *    SectionCandidates — is pinned too and a future one cannot forget. That
     *    correlation is only as strong as the outer query's own tenancy, so all
     *    three call sites now scope their items: the library and the pins
     *    re-check here, and the site join in SectionCandidates.
     *
     * Its "no source_items at all" arm stays deliberately UNPINNED — see the
     * comment there: pinning it would make an item whose only source_items are
     * foreign read as "no sources -> live", which fails open.
     */
    private function itemPayloads(Site $site, array $ids, bool $withDuplicateCandidates): array
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
        // cached read of every item family, each keyed by item id. The lookup
        // is FAMILY-AWARE — an item only reads its own kind's family — so a
        // watch_item row can never leak onto a product.
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
                // #W1-SEC-10: the storefront's OWN tenancy, stated in the ON
                // clause and not as a where — a where on a left-joined column
                // silently converts this back to an inner join, and a menu
                // category (no sidecar) would stop grouping. collection_id is
                // storefronts' PRIMARY KEY and user_id is NOT NULL since
                // 20260819000100, so this filters the one row already fetched.
                // What it protects is a checkout URL and a DISCOUNT CODE.
                ->leftJoin('content.storefronts as s', fn ($j) => $j
                    ->on('s.collection_id', '=', 'c.id')
                    ->where('s.user_id', '=', $site->user_id))
                ->whereIn('ci.item_id', $ids)
                // #W1-SEC-10: content.collection_items has no user_id, so the
                // collection's own tenancy is stated here rather than inferred
                // from the members. A group header carries a LABEL onto the
                // public wire — the one thing in this query that would be
                // read as the owner's own words.
                ->where('c.user_id', $site->user_id)
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
                    's.logo_mark_svg_url',
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
                ->where('cs.user_id', $site->user_id)
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
            // #FU-2: ON clause, never a `where` — connection_id is NULLABLE.
            // This query SELECTS platform_connections.platform, so an unpinned
            // hop published another account's platform label.
            ->leftJoin('site.platform_connections', function ($j) use ($site) {
                $j->on('site.platform_connections.id', '=', 'content.sources.connection_id')
                    ->where('site.platform_connections.user_id', '=', (string) $site->user_id);
            })
            ->whereIn('content.f_link.item_id', $ids)
            ->where('content.sources.user_id', $site->user_id)
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

        // A dish's per-platform links (W5, reshaped 2026-08-26): each
        // per-platform offer contributes the dish's OWN item deep link
        // (offers.item_url, stored by the projection with its platform slug)
        // — a dish on Uber Eats AND DoorDash shows both, each opening that
        // exact dish. Legacy rows (pre-migration, store url on offers.url
        // with no platform label) keep contributing via the old host
        // derivation until the next wholesale scrape rebuild replaces them.
        // #LIFE-4 again. This path landed AFTER the audit was taken and carries
        // the identical gap — it never joined platform_connections at all, so
        // there was nothing to filter on. Same helper, so the two cannot drift.
        $offerLinksQuery = DB::connection('pgsql')->table('content.offers')
            ->join('content.sources', 'content.sources.id', '=', 'content.offers.source_id')
            // #FU-2, same shape as the f_link read above. Liveness-only consumer
            // here (no pc column is selected), so what an unpinned hop bought was
            // a foreign LIVE verdict resurrecting a dead per-dish deep link.
            ->leftJoin('site.platform_connections', function ($j) use ($site) {
                $j->on('site.platform_connections.id', '=', 'content.sources.connection_id')
                    ->where('site.platform_connections.user_id', '=', (string) $site->user_id);
            })
            ->whereIn('content.offers.item_id', $ids)
            ->where('content.sources.user_id', $site->user_id)
            ->where(function ($w) {
                $w->whereNotNull('content.offers.item_url')
                    ->orWhereNotNull('content.offers.url');
            })
            ->orderByDesc('content.sources.priority');

        LiveSourceScope::constrainToLiveSource($offerLinksQuery);

        $offerLinks = $offerLinksQuery
            ->get(['content.offers.item_id', 'content.offers.url', 'content.offers.item_url', 'content.offers.platform as offer_platform', 'content.sources.kind as source_kind'])
            ->map(function (object $row): ?object {
                // Stored attribution + item link first; host-derived store
                // link only for legacy rows that predate the columns.
                if (is_string($row->item_url) && trim($row->item_url) !== '') {
                    $stored = is_string($row->offer_platform) && $row->offer_platform !== '' ? self::wirePlatform($row->offer_platform) : null;
                    $platform = $stored ?? ItemLinkRules::platformForUrl(trim($row->item_url));

                    return $platform === null ? null : (object) ['item_id' => $row->item_id, 'url' => trim($row->item_url), 'source_kind' => (string) $row->source_kind, 'platform' => $platform];
                }
                if (! is_string($row->url) || $row->url === '') {
                    return null;
                }
                $platform = ItemLinkRules::platformForUrl((string) $row->url);

                return $platform === null ? null : (object) ['item_id' => $row->item_id, 'url' => (string) $row->url, 'source_kind' => (string) $row->source_kind, 'platform' => $platform];
            })
            ->filter()
            ->groupBy('item_id');
        foreach ($offerLinks as $itemId => $rows) {
            $sourceLinks[$itemId] = ($sourceLinks[$itemId] ?? collect())->concat($rows);
        }

        // A connection-fed item with NO url of its own (a connection whose
        // connector lands no per-item link; Fresha services HAVE one since
        // 2026-08-24 — FreshaConnector::bookingDeepLink — so they no longer
        // reach this) still came FROM a platform: derive it from the item's live
        // connection source so the source badge and the item sheet's platform
        // row can name it, and lend the connection's own url as the link
        // (overnight 2026-08-18 W6). Highest-priority connection wins.
        // Every source that lists the item (W8: the item sheet's "Sources"
        // list with sync badges) — manual and connection, live only; the
        // connection's platform + display name + last sync time ride along.
        $sourceRows = DB::connection('pgsql')->table('content.source_items')
            ->join('content.sources', 'content.sources.id', '=', 'content.source_items.source_id')
            // #FU-2, and this is the load-bearing one: pc.id keys
            // $payloadByConnection (-> ConnectionDisplayName) and $sourcePlatforms
            // (-> the connection's OWN url as this item's fallback link), so an
            // unpinned hop put another account's display name and URL on a
            // CDN-cached public page. ON clause: the inline where below already
            // keeps every manual row, and a `where` here would not.
            ->leftJoin('site.platform_connections', function ($j) use ($site) {
                $j->on('site.platform_connections.id', '=', 'content.sources.connection_id')
                    ->where('site.platform_connections.user_id', '=', (string) $site->user_id);
            })
            ->whereIn('content.source_items.item_id', $ids)
            ->where('content.sources.user_id', $site->user_id)
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
                'site.platform_connections.is_active as is_active',
            ])
            ->groupBy('item_id');
        // The connection's ingest cadence (last run, auto-sync) for the sync
        // badge — a separate read because ingest.* is its own lane (many
        // content-only fixtures do not create it) and the join would have
        // fanned the row set out per stream anyway.
        $connectionIds = $sourceRows->flatten(1)->pluck('connection_id')->filter()->unique()->values()->all();
        // SCALE-14: $sourceRows fans out to one row per (item, source), so a
        // payload column selected there gets re-materialised once per item —
        // up to LIBRARY_LIMIT copies of the same connection's (possibly
        // multi-MB) JSONB blob. Keyed by distinct connection instead, it is
        // fetched once — same shape as the $ingestByConnection read below.
        $payloadByConnection = $connectionIds === [] ? [] : DB::connection('pgsql')->table('site.platform_connections')
            ->whereIn('id', $connectionIds)
            // #FU-2: defence-in-depth. $connectionIds now comes from a pinned
            // join and `id` is the PK, so nothing foreign can be in the list —
            // but this read hands `payload` (the account name and the fallback
            // url) to the wire and must be safe read on its own. Not a join, so
            // a plain `where` is correct and carries no null-collapse risk.
            ->where('user_id', $site->user_id)
            ->pluck('payload', 'id')
            ->all();
        $ingestByConnection = [];
        if ($connectionIds !== []) {
            try {
                $ingestByConnection = DB::connection('pgsql')->table('ingest.sources')
                    ->whereIn('connection_id', $connectionIds)
                    // #FU-2, and NOT defence-in-depth: ingest.sources.connection_id
                    // is a SECOND FK with a SEPARATE writer, so a foreign ingest
                    // row naming this owner's connection would badge this item with
                    // another account's last sync and auto-sync flag whatever the
                    // join above does. ingest.sources.user_id is NOT NULL
                    // (20260727130000), so this can never silently match nothing.
                    ->where('user_id', $site->user_id)
                    ->orderByDesc('last_run_at')
                    ->get(['connection_id', 'last_run_at', 'auto_sync'])
                    ->unique('connection_id')
                    ->keyBy('connection_id')
                    ->all();
            } catch (QueryException $e) {
                // Fail-open: the sheet still renders, badges just read "never".
                // But #LIFE-15: this was indistinguishable from a real DB fault
                // on a query the PUBLIC payload also runs, with no log line at
                // all. A missing ingest schema is a legitimate shape here, so
                // it stays a warning breadcrumb; only a SUSTAINED run reaches
                // Nightwatch (EscalatesRepeatedFaults, same as
                // ContentPopularityReader). Never fail-closed.
                Log::warning('pools.ingest_badges_read_failed', ['error' => $e->getMessage()]);
                self::escalateIfSustained($e, 'pool_ingest_badges');
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

        $sourcesByItem = $sourceRows->map(function ($rows, $itemId) use ($ingestByConnection, $originByItem, $payloadByConnection): array {
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
                // ->utc() is what makes the line above TRUE (SEM-17) — without it
                // the stamp carried no zone conversion at all. Same call, same
                // reason, as latestFor()'s helper.
                $iso = fn ($v) => $v === null ? null : Carbon::parse((string) $v)->utc()->toIso8601String();
                if ($row->source_kind === 'manual') {
                    $out[] = ['kind' => 'manual', 'platform' => null, 'accountName' => null, 'origin' => $originByItem[(string) $itemId] ?? null, 'lastSeenAt' => $iso($row->last_seen_at), 'lastSyncedAt' => null, 'autoSync' => false, 'active' => true];

                    continue;
                }
                $payload = self::decodedPayload($payloadByConnection[(string) $row->connection_id] ?? null);
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
            ->map(function ($rows) use ($payloadByConnection): ?object {
                $rows = $rows->filter(fn ($r) => $r->source_kind !== 'manual' && $r->connection_id !== null && (bool) $r->is_active);
                $row = $rows->first();
                if ($row === null || ! is_string($row->platform) || $row->platform === '') {
                    return null;
                }
                $payload = self::decodedPayload($payloadByConnection[(string) $row->connection_id] ?? null);
                $url = $payload['url'] ?? ($payload['selection']['url'] ?? null);

                // #SEC-2: both gates. safeHref is the shared emit-path allowlist; the
                // anchored regex stays because safeHref accepts the opaque form
                // (`https:evil`) that has no authority and this fallback needs one.
                return (object) ['platform' => self::wirePlatform($row->platform), 'url' => is_string($url) && preg_match('~^https?://~i', $url) ? UrlSafety::safeHref($url) : null];
            })
            ->filter();

        // Open identity candidates involving these items (task #18): the
        // resolver's Evidential tier — "these might be the same thing" — for
        // the dashboard's Possible-duplicate chip + same/different verbs.
        //
        // #API-7 (= SCALE-9 in the 2026-08-24 remainder sweep): "dashboard-only;
        // stripped from the public wire" used to describe the OUTPUT only — the
        // query ran for every audience and PoolWire then unset the key. It is
        // now gated, so the public payload build, GET /site/actions and the
        // scoring job each drop one round trip. Row volume was never the cost
        // (idx_identity_candidates_open makes the empty case an index scan whose
        // two content.items joins never execute); the round trip was, and that
        // is what the 2026-08-24 batching work was denominated in.
        //
        // #SEC-5: the OR below only requires ONE side to be in $ids, so a
        // same-tenant pairing convention isn't enough — a row that (by writer
        // bug or otherwise) pairs one of this user's items with another
        // tenant's would leak that tenant's headline_cache. Scope ic/li/ri to
        // $site->user_id explicitly so tenancy is a property of the query,
        // not of whatever wrote the row. WHERE (not ON) is equivalent here:
        // these are INNER joins, so a WHERE predicate drops the same rows an
        // ON predicate would, and it matches this query's existing style.
        $candidatesByItem = [];
        if ($withDuplicateCandidates) {
            $candidateRows = DB::connection('pgsql')->table('content.identity_candidates as ic')
                ->join('content.items as li', 'li.id', '=', 'ic.left_item_id')
                ->join('content.items as ri', 'ri.id', '=', 'ic.right_item_id')
                ->where('ic.user_id', $site->user_id)
                ->where('li.user_id', $site->user_id)
                ->where('ri.user_id', $site->user_id)
                ->whereNull('ic.dismissed_at')
                ->whereNull('li.removed_at')->whereNull('ri.removed_at')
                ->where(fn ($w) => $w->whereIn('ic.left_item_id', $ids)->orWhereIn('ic.right_item_id', $ids))
                ->get(['ic.left_item_id', 'ic.right_item_id', 'ic.evidence', 'li.headline_cache as left_headline', 'ri.headline_cache as right_headline']);
            foreach ($candidateRows as $row) {
                $evidence = is_string($row->evidence) ? (json_decode($row->evidence, true)['key'] ?? null) : null;
                $candidatesByItem[(string) $row->left_item_id][] = ['itemId' => (string) $row->right_item_id, 'headline' => $row->right_headline, 'evidence' => $evidence];
                $candidatesByItem[(string) $row->right_item_id][] = ['itemId' => (string) $row->left_item_id, 'headline' => $row->left_headline, 'evidence' => $evidence];
            }
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
        // query (ReviewFacets::forItems([]) short-circuits without touching the
        // database) — this sits behind the 60s payload cache on the public path.
        //
        // ONE read, shared with the scope below, and that sharing is the fix
        // (2026-09-01, fourth pass). This lane used to read content.f_review
        // twice — here with keyBy(), which keeps the LAST row of a
        // two-source review, and inside reviewsOutsidePersonScope(), which
        // scanned them all and admitted on the FIRST that named the owner. The
        // row that justified admission was deterministically not the row that
        // published. Passing the resolution in rather than letting the scope
        // fetch its own is what makes those two answers structurally the same
        // rows; see ReviewFacets.
        $reviewIds = $items->filter(fn (object $i): bool => $i->kind === 'review')->keys()->all();

        $reviewFacets = ReviewFacets::forItems($reviewIds);
        $suppressedReviews = [];
        if ($reviewIds !== []) {
            $suppressedReviews = $this->reviewsSuppressedByOwner($site, $reviewIds)
                + $this->reviewsOutsidePersonScope($site, $reviewIds, $reviewFacets);
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
            // #W1-SEC-10: content.item_media carries no user_id, so asset_id is a
            // SECOND FK hop out of the owner-scoped id list. media_assets.user_id
            // is NOT NULL and indexed via UNIQUE (user_id, fingerprint), and what
            // this query hands MediaUrlResolver — source_url, storage_path,
            // site_media_id — goes straight onto the public wire. Without the
            // predicate one mislinked asset_id publishes another account's image
            // URL and its R2 storage path.
            ->where('content.media_assets.user_id', $site->user_id)
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
                // pending() asks the ROW whether bytes are still coming.
                'content.media_assets.mirror_eligible',
                'content.media_assets.mirror_attempts',
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

            $sourceLinksForItem = $sourceLinks->get($itemId, collect());
            $manualLinksForItem = $manualLinks->get($itemId, collect());
            $links = $this->linkSet($sourceLinksForItem, $manualLinksForItem);
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

            // #SEC-2 regression fix: ActionCandidates::itemCandidate() (app/Site/Actions/
            // ActionCandidates.php) reads the item's DIRTY url as its own drop signal — a
            // present-but-unsafe string means "not a candidate at all", while a null/absent
            // url means "no outbound link, fall through to a page-anchor candidate". Those
            // are two different cases with the same wire value if we null the url here, so
            // safeHref()'s rejection has to be handled at THIS seam, the only place that
            // still holds the raw string — dropping the item from the pool selection
            // entirely, not just nulling its url field. Do not "simplify" this back to a
            // plain safeHref() assignment: that silently un-drops the item everywhere else
            // that reads pool payloads (actions rail included), it just makes the button
            // point at a useless internal anchor instead of the rejected href.
            //
            // The predicate is NOT "was the raw top-priority link unsafe" — linkSet()
            // (also #SEC-2) already walks every source/manual row in priority order and
            // drops only the unsafe ones, falling through to a lower-priority row. A
            // high-priority javascript: row with a safe fallback beneath it must still
            // publish that fallback, not vanish the item. So the drop decision needs two
            // separate signals: did this item ever carry stored link intent at all (raw,
            // ungated — same priority walk linkSet() uses, source row first then manual),
            // and did the item's OWN link — linkSet()'s already-safety-filtered $primary,
            // with the owner's override substituted on top exactly as $ov() does for the
            // emitted field — survive. Only "intent present AND nothing of its own survived"
            // is a drop; "no intent" is a legitimate anchor-only item and must not be dropped.
            //
            // Deliberately NOT $outboundUrl here. For a product, $outboundUrl is
            // ShopOutboundUrl::compose($primary['url'], …, $primaryStore, …) — it can fold
            // in the STORE's own url/discount_code/referral_query (content.storefronts,
            // independently hand-editable) on top of an already-safe bare item link. A
            // poisoned store field is that store's data-quality problem, not this item's:
            // the store card already degrades its own url/favicon/logo to null without
            // disappearing (see the #SEC-2 gate below), and a product with a perfectly
            // good bare link must degrade the same way — emit url=>null — not vanish from
            // the catalog because an unrelated field on a different row was bad.
            $rawPrimaryUrl = $sourceLinksForItem->first()->url ?? $manualLinksForItem->first()?->url;
            $rawOutboundUrl = $ov('f_link.url', $rawPrimaryUrl);
            $hadLinkIntent = is_string($rawOutboundUrl) && $rawOutboundUrl !== '';
            $ownUrl = $ov('f_link.url', $primary['url'] ?? null);
            $ownLinkSurvives = is_string($ownUrl) && UrlSafety::safeHref($ownUrl) !== null;
            if ($hadLinkIntent && ! $ownLinkSurvives) {
                continue;
            }
            // Same expression as the emitted `url` field below — computed once here and
            // reused there. Deliberately a DIFFERENT input from $ownLinkSurvives above
            // (routes through $outboundUrl/compose()) — it can be null on a surviving
            // item (a product whose store-composed link failed), which is fine: the wire
            // value degrading to null is not the same event as the drop decision above.
            $emittedUrl = UrlSafety::safeHref($ov('f_link.url', $outboundUrl));

            // THE row this item's review card is built from — the one the
            // person scope above was applied through, read off the same
            // resolution rather than a second keyBy() of its own.
            $review = $reviewFacets->for((string) $itemId)->published();

            $out[$itemId] = [
                'id' => (string) $itemId,
                'kind' => $item->kind,
                'slug' => $slugMap[$itemId]['slug'] ?? null,
                'aliases' => $slugMap[$itemId]['aliases'] ?? [(string) $itemId],
                'headline' => is_string($overrideHeadline) && $overrideHeadline !== ''
                    ? $overrideHeadline
                    : $item->headline_cache,
                'headlineEdited' => is_string($overrideHeadline) && $overrideHeadline !== '',
                // #SEC-2: the last gate before the CDN. f_link.url is hand-overridable
                // (UpsertManualOverrideRequest validates only `present`), so the owner's
                // own stored string reaches the public wire here unless it passes. An
                // item whose OWN stored link is present-but-unsafe with no safe fallback
                // never reaches this line — it is dropped above. $emittedUrl is this same
                // expression, computed once and reused; it CAN still be null here for a
                // surviving item — a product whose store-composed link failed degrades to
                // url=>null rather than vanishing (see the drop-decision comment above).
                'url' => $emittedUrl,
                'platform' => $primary['platform'] ?? null,
                'creator' => $ov('f_authored.creator', $creators[$itemId]->creator ?? $channels[$itemId]->handle ?? null),
                'publishedAt' => self::iso($ov('f_published.published_from', $published[$itemId] ?? null)),
                'firstSeenAt' => self::iso($item->first_seen_at),
                'durationSeconds' => (function () use ($ov, $durations, $itemId): ?int {
                    $v = $ov('f_duration.seconds', isset($durations[$itemId]) ? (int) $durations[$itemId] : null);

                    return is_numeric($v) ? (int) $v : null;
                })(),
                'thumbnail' => $this->cover($covers->get($itemId, collect()), $resolvedUrls),
                // The 640px tier of the same cover (2026-09-04) — what tiles
                // and cards should load; null when the cover has no thumb
                // (upload, vendor link) and consumers fall back to thumbnail.
                'thumb' => $this->thumb($covers->get($itemId, collect()), $resolvedUrls),
                // Dashboard-only: no cover renders YET because its bytes
                // are still being mirrored — the setup tile shows a skeleton
                // instead of an empty card. False once anything resolves.
                'pending' => $this->pending($covers->get($itemId, collect()), $resolvedUrls),
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
                'popularityRank' => self::rankFor($ranks, (string) $item->kind, $itemId),
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
                // published(), not a second keyBy(): THE row, chosen by the one
                // resolution the person scope was applied through. An item that
                // survived suppression did so on evidence read off this exact
                // row, so the card and the admission can no longer describe
                // different reviews.
                'review' => $review === null ? null : [
                    'rating' => $review->rating === null ? null : (float) $review->rating,
                    'text' => $review->text,
                    'authorName' => $review->author_name,
                    // #SEC-2: authorUri IS an href (the reviewer's Google profile) and is
                    // third-party-sourced, so it belongs on the same gate as `url`.
                    'authorPhotoUrl' => UrlSafety::safeHref($review->author_photo_url),
                    'authorUri' => UrlSafety::safeHref($review->author_uri),
                    // #API-1 applies here too — content.f_review.reviewed_at is
                    // timestamptz, `review` is a PUBLIC wire field (ITEM_KEYS,
                    // and not dashboard-only), and pdo_pgsql hands it back as
                    // "2026-07-01 10:00:00+00" whose rendering also shifts with
                    // the session TimeZone. Missed on the first pass because the
                    // audit named only the three top-level fields and the
                    // existing assertion passes on SQLite, which returns the
                    // seeded string verbatim and cannot see the difference.
                    'reviewedAt' => self::iso($review->reviewed_at),
                ],
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
     * #W1-SEC-10: `cs.user_id` is pinned explicitly rather than inherited from
     * the fact that $reviewIds was owner-scoped upstream. content.source_items
     * carries no user_id of its own, so without this the query's ONLY tenancy
     * is the id list handed in — and a mislinked source_id (a writer bug, a
     * hand-run SQL fix) would have this page read another owner's connection
     * settings to decide what to publish. Same predicate the
     * identity_candidates read already states structurally; NOT NULL on
     * content.sources, so it can never silently match nothing.
     *
     * ⚠️ Alone among the pinned reads, this predicate is fail-OPEN on corrupt
     * data: suppression needs EVERY source row of an item to hide reviews, so
     * dropping rows drops suppressions and MORE reviews publish. That is the
     * right direction for the cross-tenant case this pins (a foreign row must
     * not un-suppress, and must not suppress either), but it means a bug that
     * made this query return too little would silently republish content an
     * owner switched off, rather than blanking the pool. The other nine
     * #W1-SEC-10 predicates fail closed. Change this one with that asymmetry
     * in mind.
     *
     * ⚠️ #FU-2 (2026-08-31): the `pc` leftJoin below is where the connection_id
     * hop bit hardest — pc.platform and pc.display_settings are READ to decide
     * what this page publishes, and cs.user_id does not cover that hop. It is now
     * pinned in the ON clause (never a `where`: connection_id is NULLABLE and a
     * `where` would drop the manual lane). The pin ALONE was not enough. A
     * foreign connection joins as NULL, and connectionHidesReviews() reads NULL
     * settings as "does not hide" — so the foreign row kept voting, and one such
     * vote defeats the every() below and UN-suppresses what the owner switched
     * off. The second predicate therefore DROPS a connection-kind source whose
     * connection did not resolve: unknown votes neither way. That drop is a drop
     * from the VOTE, not from the payload — an item whose only source row is
     * dropped here simply gains no suppression and still publishes. Manual
     * sources have no connection and are untouched, and a SOFT-deleted connection
     * still has a non-null pc.id, so a silenced source stays silenced while its
     * rows linger — which is the behaviour the paragraph above depends on.
     *
     * @param  list<string>  $reviewIds
     * @return array<string, true>
     */
    private function reviewsSuppressedByOwner(Site $site, array $reviewIds): array
    {
        $rows = DB::connection('pgsql')->table('content.source_items as si')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->leftJoin('site.platform_connections as pc', function ($j) use ($site) {
                $j->on('pc.id', '=', 'cs.connection_id')
                    ->where('pc.user_id', '=', (string) $site->user_id);
            })
            ->whereIn('si.item_id', $reviewIds)
            ->where('cs.user_id', $site->user_id)
            // #FU-2, the second half — the pin alone is NOT enough HERE. A
            // foreign connection joins as NULL, and connectionHidesReviews()
            // reads NULL settings as "does not hide", so the foreign row would
            // keep VOTING, just differently — and one such vote defeats the
            // every() below and UN-suppresses what the owner switched off. A
            // connection-kind source whose connection did not resolve to this
            // owner is UNKNOWN, so it votes neither way: drop it from the vote.
            // Manual sources have no connection and keep voting exactly as
            // before. This drops the row from the SUPPRESSION VOTE only — the
            // item itself still publishes; nothing here touches the payload.
            ->where(function ($w) {
                $w->where('cs.kind', 'manual')->orWhereNotNull('pc.id');
            })
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
     * Person-scoping for the reviews pool (owner, 2026-08-28): review items a
     * partna account may NOT show, keyed by item id. A venue-level source
     * (Google listing, Booksy/Treatwell page, storewide Fresha) reviews the
     * WORKPLACE — an individual's page keeps only the reviews attributable to
     * THEM: Fresha's structured staff attribution (f_review.staff_name), a
     * mention of their name in the prose the card will actually carry, or a
     * source that was employee-scoped at the vendor WHEN THE REVIEW LANDED (so
     * an UNATTRIBUTED review it landed is theirs even when the text never says
     * a name — see the storewide note below for why "when" is load-bearing).
     * Empty for business accounts — the venue's reviews ARE its reviews — and
     * applied identically in hasSelection() and itemPayloads(), so the page nav
     * advertises cannot be a page with an empty pool behind it.
     *
     * With no usable name on file, venue reviews stay excluded (fail closed):
     * publishing a co-worker's praise on the wrong person's page is the harm
     * this scope exists to prevent, and employee-scoped sources still pass.
     *
     * $facets is HANDED IN, never fetched here, and that is the fix this
     * method's fourth pass consists of. It used to run its own
     * `content.f_review` read while itemPayloads() ran another, and the two
     * disagreed about which row of a two-source review was authoritative: this
     * one admitted on the first row whose prose named the owner, that one
     * rendered the last row by updated_at. "Raff was wonderful" on the Google
     * copy admitted an item the Fresha copy then published as "Great service
     * today, thanks!" — a venue review on a named person's page, admitted by a
     * sentence nobody could see. Three waves of guards each reasoned about a
     * row that was not the row the visitor saw, which is why each produced
     * another blocker. There is now ONE resolution (ReviewFacets) and both
     * callers read it, so the two answers cannot be about different rows.
     *
     * The two attribution questions are asked at different scopes ON PURPOSE:
     *
     *   - staffNames() spans EVERY row. The vendor's structured answer is a
     *     claim about the REVIEW, one review however many vendors retell it,
     *     and two vendors disagreeing about whose it is is an uncertainty
     *     rather than a tie for updated_at to break.
     *   - publishedTextNames() reads the PUBLISHED row alone. Prose is a claim
     *     about the words on the card, and only one vendor's wording is ever
     *     on the card. Where the copies differ, the quieter one wins and the
     *     review stays with the venue.
     *
     * 2026-09-01, after we published other people's reviews on real people's
     * pages, this method fails closed in the two places it used to fail open:
     *
     *   - An UNRESOLVABLE user returned the empty map, which means "suppress
     *     nothing" — the paragraph above says the opposite, and the code said
     *     it for a year. A site whose owner row cannot be loaded now suppresses
     *     every review. Only a resolvable account that is genuinely
     *     venue-scoped (business, reviews_scoped_to_person false) gets the
     *     empty map.
     *   - An employee-scoped source used to `continue` BEFORE the f_review
     *     facet was read, so a review whose own structured attribution names
     *     somebody else could not veto itself. ollies carries a Fresha source
     *     with selection_ref 5035183 and published "Ciel was amazing" on Raff
     *     McGuiness's page through exactly that hole. Structured attribution
     *     naming a different person is the FIRST thing checked and it vetoes:
     *     a review that says it is about Ciel is not about Raff.
     *
     * @param  list<string>  $reviewIds
     * @return array<string, true>
     */
    private function reviewsOutsidePersonScope(Site $site, array $reviewIds, ReviewFacets $facets): array
    {
        if ($reviewIds === []) {
            return [];
        }

        // One predicate for the cards and the badge above them (2026-09-01):
        // statsFor() asks the same question, and two spellings of "is this a
        // person's page" is how the badge came to publish a venue over a pool
        // the cards had already been scoped out of.
        if (! $this->pageIsPersonScoped($site)) {
            return [];
        }

        $pro = $site->user;
        if ($pro === null) {
            return array_fill_keys($reviewIds, true);
        }

        $employeeScoped = $this->reviewsIngestedUnderEmployeeScope($site, $reviewIds);
        $names = $this->personNameTokens($pro);

        $outside = [];
        foreach ($reviewIds as $itemId) {
            $itemId = (string) $itemId;
            $set = $facets->for($itemId);
            $staffNames = $set->staffNames();

            // The veto, and it outranks every admission below including the
            // employee-scoped source. staff_name is the vendor stating WHICH
            // team member the review is about; when it names someone who is
            // not this account holder — or when we hold no name to check it
            // against — no other signal can overturn that.
            //
            // EVERY name, not the first: a deduped review's Google row can
            // carry "Raff" while its Fresha row carries "Ciel", and stopping
            // at the first match would admit exactly the review the second
            // name disowns. Two sources disagreeing about who a review is
            // about is not a tie to be broken, it is an uncertainty, and this
            // scope resolves uncertainty by leaving the review with the venue.
            $disowned = false;
            foreach ($staffNames as $staffName) {
                if (! PersonNameMatch::matchesStaffName($staffName, $names)) {
                    $disowned = true;

                    break;
                }
            }
            if ($disowned) {
                $outside[$itemId] = true;

                continue;
            }

            // Survived the veto with at least one attribution: every vendor
            // that named anybody named this account holder, which is the
            // strongest admission there is.
            if ($staffNames !== []) {
                continue;
            }

            if (isset($employeeScoped[$itemId])) {
                continue;
            }

            // No facet row at all, or no name in the prose that will be
            // rendered, is an uncertainty: the card cannot claim this person,
            // so it stays with the venue.
            if ($set->publishedTextNames($names)) {
                continue;
            }

            $outside[$itemId] = true;
        }

        return $outside;
    }

    /**
     * Review items landed by a source that was scoped to ONE team member at
     * the vendor AT THE TIME IT LANDED THEM, keyed by item id.
     *
     * "At the time" is the whole method (BLOCKER, 2026-09-01). This gate used
     * to read `ingest.sources.selection_ref` — the source's CURRENT selection —
     * and the reviews already sitting in `content` carried no record of the
     * selection in force when they were ingested. So a Fresha connection that
     * harvested vision-hair-studio-melbourne-tzo6gxk0 STOREWIDE and was later
     * narrowed to employee 5035183 retroactively re-labelled the entire salon's
     * corpus as that employee's, and published all of it on their page —
     * permanently, since nothing in the storewide rows ever said otherwise and
     * no later run could tell them apart. The scope of a review is a fact about
     * its ingestion, so `content.source_items.ingest_selection_ref` records it
     * at write time (ProjectionWriter::upsertSourceItem) and this reads that.
     *
     * NULL means "landed before we recorded this, or by a lane that has no
     * vendor selection at all" and is NOT employee scope. That is the fail
     * direction the whole method needs: this is the ONE tier that admits a
     * review carrying no name evidence whatsoever — no staff attribution, no
     * mention in the prose — purely on the vendor's word that the feed was
     * already filtered to this person, so an unknown answer must never open it.
     * The empty string and 'storewide' are excluded for the same reason and
     * with the same force: storewide is the vendor stating the opposite claim.
     *
     * #W1-SEC-10 / #FU-2: `cs.user_id` is pinned explicitly. This join is a
     * GATE — passing it is what lets a review with no name evidence onto a
     * person's page — and `content.source_items` carries no user_id of its own,
     * so without the predicate the query's only tenancy would be the id list
     * handed in, and a mislinked source_id (a writer bug, a hand-run SQL fix)
     * would open the gate on another account's ingest selection. The second hop
     * the #FU-2 pass had to pin, `cs.connection_id -> ing.connection_id`, is
     * GONE: the selection now travels on the row the review landed on, so
     * `ingest.sources` — written by its own lane, and the table whose
     * present-tense value caused the blocker above — is no longer consulted at
     * all. WHERE, not ON: this is an INNER join, so a where drops exactly the
     * rows an ON clause would. Fail direction is closed — fewer gate passes
     * means more venue reviews excluded.
     *
     * @param  list<string>  $reviewIds
     * @return array<string, true>
     */
    private function reviewsIngestedUnderEmployeeScope(Site $site, array $reviewIds): array
    {
        $rows = DB::connection('pgsql')->table('content.source_items as si')
            ->join('content.sources as cs', 'cs.id', '=', 'si.source_id')
            ->whereIn('si.item_id', $reviewIds)
            ->where('cs.user_id', $site->user_id)
            ->whereNotNull('si.ingest_selection_ref')
            ->whereNotIn('si.ingest_selection_ref', ['', 'storewide'])
            ->get(['si.item_id']);

        $employeeScoped = [];
        foreach ($rows as $row) {
            $employeeScoped[(string) $row->item_id] = true;
        }

        return $employeeScoped;
    }

    /**
     * The name forms a partna account can be recognised by: full display
     * name, first_name, and each of their leading tokens. Null when nothing
     * usable is on file.
     *
     * @return array{full: list<string>, first: list<string>}|null
     */
    private function personNameTokens(object $pro): ?array
    {
        // Delegated (2026-08-29): the GDPR export needs the SAME judgement to
        // decide whether f_review.staff_name is the requester or a co-worker
        // (#W1-PRIV-2), and two drifting name matchers is a hazard this repo
        // has already paid for once.
        return PersonNameMatch::tokens(
            $pro->display_name ?? null,
            $pro->first_name ?? null,
        );
    }

    /**
     * The rank an item carries on the wire: its kind's family, keyed by the
     * item id (every family, 2026-08-23). Null when unranked.
     *
     * @param  array<string, array<string, int>>  $ranks  family => item id => rank
     */
    private static function rankFor(array $ranks, string $kind, string $itemId): ?int
    {
        $family = ItemFamily::forKind($kind);
        if ($family === null || $itemId === '') {
            return null;
        }

        return $ranks[$family][$itemId] ?? null;
    }

    /**
     * Popularity ranks for a site across every item family (family => key => rank).
     *
     * Same key and TTL as PublicIntegrationController (CCG-102) on purpose:
     * two different keys would silently halve a single-flight cache that
     * exists because this read used to hit Postgres on every public request.
     *
     * CCH-11: forSite() fails open to [] on a DB fault, indistinguishable from
     * a genuine "nothing scored yet" site — and rememberLocked would cache
     * either one for the full 900s TTL. $failed is set only inside the
     * closure, so it stays false (and the write below a no-op) on a cache
     * HIT, where the closure never runs and lastReadFailed() would otherwise
     * still be reporting some earlier, unrelated call on this shared reader
     * instance. On an actual miss that faulted, shortenDegraded()
     * immediately overwrites what rememberLocked just wrote — primary and
     * stale — with the short degraded TTL, so the next request retries the DB
     * within seconds instead of serving an empty ranking for 15 minutes.
     *
     * @return array<string, array<string, int>>
     */
    private function popularityRanks(Site $site): array
    {
        $failed = false;
        $key = CacheKeyGenerator::sitePopularityRanks((string) $site->id);
        $ranks = $this->cache->rememberLocked(
            $key,
            self::POPULARITY_CACHE_TTL_SECONDS,
            function () use ($site, &$failed) {
                $ranks = $this->popularity->forSite((string) $site->id);
                $failed = $this->popularity->lastReadFailed();

                return $ranks;
            },
        );

        if ($failed) {
            $this->cache->shortenDegraded(
                $key,
                $ranks,
                max(1, (int) config('partna.public_profile.degraded_cache_ttl_seconds', 10)),
            );
        }

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
                // Re-measured 2026-08-30 (the earlier "0 of 268 dev rows" claim
                // repeated here twice is stale and was false): 399 of 908 dev
                // item_variants rows carry a non-null image_url, every one a
                // Shopify CDN url with a ?v=<epoch> cache-buster.
                // #SEC-2: same http/https allowlist as every other wire url — UrlSafety has
                // no image-specific form. Also drops protocol-relative/relative srcs, which
                // no current writer produces.
                'imageUrl' => UrlSafety::safeHref($row->image_url),
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
    private function collectionsFor(string $pool, Site $site, array $selection, Collection $stores): array
    {
        $referenced = collect($selection)->flatMap(fn (array $i) => $i['collectionIds'] ?? [])->unique();
        // A category's own rank (menu_category / service_category — the SUM
        // of its members' scores, D2); null on a storefront or when unranked.
        $categoryFamily = ItemFamily::CATEGORY_FAMILIES[$pool] ?? null;
        $categoryRanks = $categoryFamily === null || $referenced->isEmpty()
            ? []
            : ($this->popularityRanks($site)[$categoryFamily] ?? []);

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
                // #SEC-2: same http/https allowlist as every other wire url.
                'url' => UrlSafety::safeHref($row->url),
                'name' => $this->publicCollectionName((string) $row->label, $externalRef, (string) $row->collection_ref),
                'currency' => $row->currency === null ? null : (string) $row->currency,
                'favicon' => UrlSafety::safeHref($row->favicon_url),
                'logo' => UrlSafety::safeHref($row->logo_url),
                // The processed store logo mark (ProcessShopBrandLogoJob's
                // SVG), public since 2026-08-26 — the sitepage shop overlay
                // wears it. Same column the dashboard's brandMap() reads.
                'logoMarkSvg' => UrlSafety::safeHref($row->logo_mark_svg_url),
                'discountCode' => $row->discount_code === null ? null : (string) $row->discount_code,
                'position' => (int) $row->position,
                'popularityRank' => $row->provider === null ? ($categoryRanks[(string) $collectionId] ?? null) : null,
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
     * A connection's `payload` JSONB column, decoded — SQLite hands the
     * column back as a string, Postgres may too depending on the read path,
     * so both branches stay. Missing/null reads as empty, same as the two
     * inline expressions this replaces.
     */
    private static function decodedPayload(mixed $raw): array
    {
        return is_string($raw) ? (json_decode($raw, true) ?: []) : (array) ($raw ?? []);
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
            // #SEC-2: an unsafe stored url is not a link, same rule ActionCandidates
            // applies — the entry is dropped, never emitted with a null url, because
            // `links[].url` is a non-null string on the wire.
            $url = UrlSafety::safeHref($row->url);
            if ($url === null) {
                continue;
            }
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
            // #SEC-2: same drop-not-null rule as the source loop above.
            $url = UrlSafety::safeHref($row->url);
            if ($url === null) {
                continue;
            }
            $urlKey = self::linkUrlKey($url);
            if (isset($seen[$row->platform]) || isset($seenUrls[$urlKey])) {
                continue;
            }
            $seen[$row->platform] = true;
            $seenUrls[$urlKey] = true;
            $links[] = ['platform' => (string) $row->platform, 'url' => $url, 'source' => 'manual'];
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
     * @param  array<string, array{url: string, width: int|null, height: int|null, thumb: string|null}>  $resolved
     */
    private function cover(Collection $rows, array $resolved): ?string
    {
        return $this->bestCover($rows, $resolved)['url'] ?? null;
    }

    private function thumb(Collection $rows, array $resolved): ?string
    {
        return $this->bestCover($rows, $resolved)['thumb'] ?? null;
    }

    /**
     * No cover resolves, and at least one cover-role row is still expecting
     * bytes — eligible, unmirrored, not an upload, retries left, and actually
     * fetchable. Read from the ROW, not from the source url: the url test
     * could not tell a row whose retries were spent from one still in flight,
     * and it read a vendor host where the answer was already on the row.
     *
     * @param  array<string, array{url: string, width: int|null, height: int|null, thumb: string|null}>  $resolved
     */
    private function pending(Collection $rows, array $resolved): bool
    {
        $coverRows = $rows->filter(fn (object $row): bool => in_array((string) $row->role, ['cover', 'poster', 'gallery'], true));
        foreach ($coverRows as $row) {
            if (isset($resolved[(string) $row->asset_id])) {
                return false;
            }
        }

        $max = MediaMirror::maxAttempts();
        foreach ($coverRows as $row) {
            // Casts, not ===: SQLite hands a boolean back as 1/0. (PDO_PGSQL
            // returns native bools, which is what makes the cast sufficient
            // here — it would not rescue a 't'/'f' string.)
            if ((bool) $row->mirror_eligible
                && $row->storage_path === null
                && $row->site_media_id === null
                // storage_path never becomes non-null for a link that cannot
                // be fetched, so a capped row is a skeleton that never
                // resolves — it is not coming, and must not claim to be.
                && (int) $row->mirror_attempts < $max
                // Dispatch skips a row with no source url (ProjectionWriter's
                // no_source_url branch) BEFORE the attempts counter moves, so
                // this row can never be fetched and never becomes non-pending.
                // Without this clause it is a skeleton that never resolves —
                // exactly what the attempts cap above exists to prevent.
                && is_string($row->source_url) && $row->source_url !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{url: string, width: int|null, height: int|null, thumb: string|null}|null
     */
    private function bestCover(Collection $rows, array $resolved): ?array
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
                    $best = $hit;
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
     * @param  array<string, array{url: string, width: int|null, height: int|null, thumb: string|null}>  $resolved
     * @return list<array{url: string, thumb: string|null, width: int|null, height: int|null, role: string, kind: string, poster: string|null, posterThumb: string|null, alt: string|null}>
     */
    private function frames(Collection $rows, array $resolved): array
    {
        // The cover (or first still) is the poster every video frame carries.
        // `poster` stays the MASTER on purpose: the sitepage gallery rail
        // renders a video's standing image at ~1000 physical px (measured
        // 2026-09-05), where the 640 rung reads soft. `posterThumb` rides
        // beside it for the small seats — the same pair every image frame
        // carries as url/thumb.
        $poster = null;
        $posterThumb = null;
        foreach ($rows as $row) {
            if ((string) $row->role !== 'video' && isset($resolved[(string) $row->asset_id])) {
                $poster = $resolved[(string) $row->asset_id]['url'];
                $posterThumb = $resolved[(string) $row->asset_id]['thumb'] ?? null;
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
            // An UNMIRRORED third-party video used to be dropped outright
            // (2026-08-28: back then the only reels left unmirrored were dead
            // on arrival). Item 7 (2026-09-01) narrows the drop to URLs the
            // oe pre-flight PROVES dead — those still ship a <video> that
            // never plays, a frozen black card, and the item's cover carries
            // the card instead. A fresh signed URL serves from source while
            // its mirror drains; the swap to owned bytes lands on a later
            // document rebuild, and the poster wiring below covers the
            // rotation window. Owner uploads ride site_media_id and mirrored
            // reels ride storage_path; both skip the gate. Host only in the
            // log — a signed media URL never reaches a log line.
            if ($isVideo && $row->storage_path === null && $row->site_media_id === null) {
                if ($this->instagramUrls->isExpired($hit['url'])) {
                    continue;
                }
                Log::info('pool.video.progressive_serve', [
                    'host' => parse_url($hit['url'], PHP_URL_HOST) ?: null,
                ]);
            }
            $frames[] = [
                'url' => $hit['url'],
                // 640px tier for grids and srcset; null where none exists.
                'thumb' => $hit['thumb'] ?? null,
                'width' => $hit['width'],
                'height' => $hit['height'],
                'role' => (string) $row->role,
                // kind + poster (R7): apps/pages MediaCard plays a `video`
                // frame and falls back to its poster; every still is `image`.
                'kind' => $isVideo ? 'video' : 'image',
                'poster' => $isVideo ? $poster : null,
                'posterThumb' => $isVideo ? $posterThumb : null,
                'alt' => $row->alt_text === null ? null : (string) $row->alt_text,
            ];
        }

        return $frames;
    }
}
