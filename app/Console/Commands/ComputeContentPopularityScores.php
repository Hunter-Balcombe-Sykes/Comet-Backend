<?php

namespace App\Console\Commands;

use App\Models\Core\Site\Site;
use App\Services\Analytics\ActionScorer;
use App\Services\Analytics\ContentFreshness;
use App\Services\Analytics\ContentPopularityReader;
use App\Services\Analytics\ItemFamily;
use App\Services\Analytics\ScoringWindow;
use App\Services\PublicSite\SitepageDataResolverService;
use App\Site\Actions\ActionCandidates;
use App\Site\Pools\PoolWire;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Recompute analytics.content_popularity_scores from the raw event tables.
 *
 * Reads ONLY raw events (analytics.link_clicks, .item_views, .action_events).
 * Per site it produces:
 *   - item scores  : link_clicks.product_id + item_views, keyed
 *                    (item_type, content.items.id) for EVERY family — the
 *                    pool "smart" order and the item reach term below.
 *   - category scores: menu_category / service_category, keyed by collection
 *                    id — the SUM of the served members' item scores (D2),
 *                    ranked among the pool's categories.
 *   - action scores: content_type='action', keyed by App\Site\Actions\ActionId
 *                    — the unified lander list (pages + destination platforms
 *                    + served items + categories) scored by ActionScorer:
 *                    demand rate + reach + freshness + prior, see that class.
 *                    Page order reads the page:* action rows; the separate
 *                    page-score family was retired 2026-08-23. The action layer
 *                    owns its own lifecycle (stale keys deleted per run) and is
 *                    excluded from the generic fade-out union below.
 *
 * Item formula (smart ordering v2, per-family weights — ItemFamily):
 *   score = Σ_days (w_click·clicks_d + w_view·impressions_d + w_dwell·dwell_s_d)
 *           · 2^(-age_d / HALF_LIFE_DAYS) + freshness
 * Every DAY's events carry their own true-half-life decay weight (day
 * buckets, driver-portable SQL), so old engagement fades even while new
 * events arrive. dwell is carried by media only: the gallery page's
 * section_views dwell split equally across the served media items (an
 * approximation — no item-grain dwell exists yet).
 *
 * freshness (ContentFreshness) is an additive boost per family from
 * publishedAt ?? firstSeenAt at the family's own weight/half-life.
 * Zero-signal keys with a live boost are SEEDED into the aggregate so a
 * brand-new item ranks before its first event (cold start).
 *
 * Anti-thrash: the stored score is blended with the previous (0.7·new + 0.3·old)
 * and a row only overtakes the one ranked above it when its blended score beats
 * that incumbent by >10%. Upserted on (site_id, content_type, content_key).
 *
 * Fade-out: stored keys that no longer aggregate any signal (an item left the
 * pool, raw events purged by retention) decay through the blend
 * (new = 0 → 0.3·prev per run, no freshness) and are DELETED once below
 * SCORE_FLOOR — stale rows can't freeze at their last score/rank forever.
 */
class ComputeContentPopularityScores extends Command
{
    protected $signature = 'analytics:compute-popularity
                            {--dry-run : Report computed scores without writing}
                            {--site= : Restrict to a single site id (uuid)}';

    protected $description = 'Recompute content_popularity_scores (pages + scored items) from raw analytics events.';

    // Ceiling for a full sweep of every published site (routes/console.php runs
    // this every 15min with a 14min withoutOverlapping lock) — comfortably under
    // that lock so a hung run is flagged well before the next tick.
    // Documentation only — Illuminate\Console\Command never reads $timeout (unlike
    // the enforced, identically-named property on a ShouldQueue job).
    protected $timeout = 600;

    // Signal weights live in config/partna.php `pools.smart` (ItemFamily).

    // TRUE half-life of a day bucket's contribution: a day's events count half
    // after 90 days (2^(-age/90), matching ContentFreshness's decay form). The
    // old exp(-age/90) whole-history multiplier halved at ~62d and was applied
    // to ALL history at once, keyed to the latest event.
    private const HALF_LIFE_DAYS = 90.0;

    // Fade-out floor: a stored row with no live aggregate signal is deleted once
    // its blended score decays below this. Matches ContentFreshness::MIN_BOOST.
    private const SCORE_FLOOR = 0.05;

    // Hysteresis: blend new vs previous stored score, and the rank-swap gate.
    private const BLEND_NEW = 0.7;

    private const BLEND_PREV = 0.3;

    private const RANK_SWAP_THRESHOLD = 0.10; // must beat the incumbent above by >10%

    private const SITE_CHUNK = 200;

    /**
     * Families that never score: events keep occurrence order (ItemFamily has
     * no kind for them). Their beacons are dropped; stored rows fade out.
     *
     * @var list<string>
     */
    private const NEVER_SCORED = ['engine_item'];

    /** The gallery page's analytics section_key (PAGE_TO_SECTION_KEY.gallery). */
    private const GALLERY_SECTION_KEY = 'gallery';

    // SCALE-3: the scheduled (no --site) run used to full-sweep EVERY published
    // site every 15 minutes regardless of whether it had any recent activity.
    // Now scoped to sites with a raw event since this window. Widened 20->60min
    // (2026-07-20): at the 15-min cadence, a W-minute window survives K
    // consecutive missed ticks (deploy restart, scheduler blip) with zero gap
    // only when W >= (K+1) x 15 — 20min didn't even survive K=1 (see
    // routes/console.php's "missed-tick gap" note for that failure mode).
    // 60min survives K=3 (a 45-min scheduler outage). Beyond that, the gap is
    // mostly self-healing (a site recomputes from full raw history next time
    // it's scoped in) — see routes/console.php for the remaining exposure. An
    // explicit --site always bypasses this (manual/targeted runs process the
    // named site unconditionally, matching the pre-existing contract).
    private const RECENT_EVENTS_WINDOW_MINUTES = 60;

    /**
     * link_clicks.section_key → scored item_type. Clicks self-describe their
     * hosting section (shop / book / events); item_views carry item_type
     * directly. Mirrors AnalyticsQueryService::topProducts/topServices/topEvents'
     * section scoping so the click-side item family is consistent.
     *
     * @var array<string, string>
     */
    private const CLICK_SECTION_TO_ITEM_TYPE = [
        'shop' => 'shop_product',
        'shop-products' => 'shop_product',
        'shop-tracks' => 'shop_product',
        'bandcamp' => 'shop_product',
        'book' => 'service',
        'services' => 'service',
        // events / attend: event items never score (occurrence order is the
        // only honest order — ItemFamily), so their clicks are not aggregated.
        // ONE item scoring by link-out (2026-07-10): listen tracks, watch videos,
        // and custom links score from clicks in their own page's section. The ONE
        // theme tags each item click with its page's canonical section_key
        // (listen / watch / custom); sub-platform keys included for robustness.
        'listen' => 'listen_item',
        'music' => 'listen_item',
        'spotify' => 'listen_item',
        'apple-music' => 'listen_item',
        'soundcloud' => 'listen_item',
        'podcast' => 'listen_item',
        'watch' => 'watch_item',
        'youtube' => 'watch_item',
        'twitch' => 'watch_item',
        'vimeo' => 'watch_item',
        'custom' => 'link_item',
        'other' => 'link_item',
        'gallery' => 'gallery_item',
        'media' => 'gallery_item',
    ];

    public function __construct(
        private readonly ContentFreshness $freshness,
        private readonly ActionCandidates $candidates,
        private readonly ActionScorer $scorer,
        private readonly ContentPopularityReader $popularity,
        private readonly PoolWire $poolWire,
        private readonly SitepageDataResolverService $resolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $siteOpt = $this->option('site');

        // Eager-load user — computeActions reads $site->user for the candidate set
        // gating, and prod runs with lazy-loading disabled (Model::preventLazyLoading).
        $query = Site::query()->where('is_published', true)->with('user');
        if (is_string($siteOpt) && $siteOpt !== '') {
            $query->where('id', $siteOpt);
        } else {
            // SCALE-3: scope the periodic full sweep to sites with events since
            // the last window — see RECENT_EVENTS_WINDOW_MINUTES above.
            $since = now()->subMinutes(self::RECENT_EVENTS_WINDOW_MINUTES);
            $query->whereIn('id', $this->siteIdsWithRecentEvents($since));
        }

        $sitesProcessed = 0;
        $rowsWritten = 0;
        $rowsDeleted = 0;

        $query->chunkById(self::SITE_CHUNK, function ($sites) use ($dryRun, &$sitesProcessed, &$rowsWritten, &$rowsDeleted) {
            foreach ($sites as $site) {
                ['rows' => $rows, 'deletes' => $deletes] = $this->computeForSite($site);
                $sitesProcessed++;

                if ($rows === [] && $deletes === []) {
                    continue;
                }

                if ($dryRun) {
                    $this->reportDryRun($site, $rows, $deletes);

                    continue;
                }

                if ($rows !== []) {
                    DB::connection('pgsql')
                        ->table('analytics.content_popularity_scores')
                        ->upsert(
                            $rows,
                            ['site_id', 'content_type', 'content_key'],
                            ['score', 'rank', 'computed_at'],
                        );
                    $rowsWritten += count($rows);
                }

                // Faded-out keys — remove instead of leaving frozen stale rows.
                foreach ($deletes as $contentType => $keys) {
                    DB::connection('pgsql')
                        ->table('analytics.content_popularity_scores')
                        ->where('site_id', $site->id)
                        ->where('content_type', $contentType)
                        ->whereIn('content_key', $keys)
                        ->delete();
                    $rowsDeleted += count($keys);
                }
            }
        });

        $this->info(sprintf(
            '%s %d sites; %s %d score rows; %s %d faded rows.',
            $dryRun ? 'Scanned' : 'Processed',
            $sitesProcessed,
            $dryRun ? 'would write' : 'wrote',
            $rowsWritten,
            $dryRun ? 'would delete' : 'deleted',
            $rowsDeleted,
        ));

        Log::info('analytics:compute-popularity completed', [
            'dry_run' => $dryRun,
            'sites' => $sitesProcessed,
            'rows' => $rowsWritten,
            'deleted' => $rowsDeleted,
            'site_filter' => is_string($siteOpt) ? $siteOpt : null,
        ]);

        return self::SUCCESS;
    }

    /**
     * Distinct site ids with at least one raw event since $since, across the
     * four event tables this command reads (section_views / link_clicks /
     * item_views / action_events), UNION sites whose pool content changed in
     * the window (D6, 2026-08-23): a content.items row created or updated,
     * or an f_published row updated — so a brand-new item cold-starts by
     * freshness without waiting for traffic. PHP-side union of DISTINCT
     * plucks rather than a cross-table SQL UNION so each query stays a plain
     * per-table filter, identical on Postgres and the SQLite test schema.
     *
     * @return list<string>
     */
    private function siteIdsWithRecentEvents(Carbon $since): array
    {
        $ids = [];
        foreach (['analytics.section_views', 'analytics.link_clicks', 'analytics.item_views', 'analytics.action_events'] as $table) {
            foreach (
                DB::connection('pgsql')->table($table)
                    ->where('occurred_at', '>=', $since->toISOString())
                    ->distinct()
                    ->pluck('site_id') as $siteId
            ) {
                $ids[(string) $siteId] = true;
            }
        }

        try {
            // content.* timestamps are written in Carbon's default
            // 'Y-m-d H:i:s' form (the analytics tables use ISO-8601), so the
            // bound must match that form for the SQLite lane's string compare.
            $sinceSql = $since->toDateTimeString();
            $userIds = [];
            foreach (
                DB::connection('pgsql')->table('content.items')
                    ->where('created_at', '>=', $sinceSql)
                    ->orWhere('updated_at', '>=', $sinceSql)
                    ->distinct()
                    ->pluck('user_id') as $userId
            ) {
                $userIds[(string) $userId] = true;
            }
            foreach (
                DB::connection('pgsql')->table('content.f_published as fp')
                    ->join('content.items as i', 'i.id', '=', 'fp.item_id')
                    ->where('fp.updated_at', '>=', $sinceSql)
                    ->distinct()
                    ->pluck('i.user_id') as $userId
            ) {
                $userIds[(string) $userId] = true;
            }
            if ($userIds !== []) {
                foreach (
                    DB::connection('pgsql')->table('site.sites')
                        ->whereIn('user_id', array_keys($userIds))
                        ->pluck('id') as $siteId
                ) {
                    $ids[(string) $siteId] = true;
                }
            }
        } catch (QueryException) {
            // content.* lane absent — traffic scope only.
        }

        return array_keys($ids);
    }

    /**
     * Compute every upsert row + faded-key deletion for one site. Content types
     * come from the UNION of live aggregates and already-stored rows — a type
     * whose events were all purged must still fade its stored keys out.
     *
     * @return array{rows: list<array<string, mixed>>, deletes: array<string, list<string>>}
     */
    private function computeForSite(Site $site): array
    {
        // The served pools, once: media dwell needs the served media ids and
        // the action candidates are built from the same map.
        $pools = $this->servedPools($site);
        $mediaIds = [];
        foreach ((array) ($pools['media']['items'] ?? []) as $item) {
            if (is_array($item) && is_string($item['id'] ?? null) && $item['id'] !== '') {
                $mediaIds[] = $item['id'];
            }
        }

        $fresh = $this->freshness->boostsForSite($site);
        $itemAgg = $this->aggregateItems($site, $mediaIds);
        foreach ($fresh as $family => $boosts) {
            foreach ($boosts as $key => $_boost) {
                $itemAgg[$family][$key] ??= self::emptySignal();
            }
        }

        // Every stored non-action type joins the union so a type that lost all
        // signal still fades out (the 'action' type owns its own lifecycle;
        // the category families are derived from this run's item rows below).
        $categoryFamilies = array_values(ItemFamily::CATEGORY_FAMILIES);
        $storedTypes = DB::connection('pgsql')->table('analytics.content_popularity_scores')
            ->where('site_id', $site->id)
            ->where('content_type', '!=', ActionScorer::CONTENT_TYPE)
            ->whereNotIn('content_type', $categoryFamilies)
            ->distinct()
            ->pluck('content_type')
            ->all();
        $types = array_values(array_unique(array_merge(array_keys($itemAgg), $storedTypes)));

        $rows = [];
        $deletes = [];
        foreach ($types as $type) {
            $result = $this->scoreAndRank($site, $type, $itemAgg[$type] ?? [], $fresh[$type] ?? []);
            $rows = array_merge($rows, $result['rows']);
            if ($result['deletes'] !== []) {
                $deletes[$type] = $result['deletes'];
            }
        }

        // Category families (D2): a served menu / service category scores the
        // SUM of its members' item scores from THIS run, keyed by collection
        // id, ranked among the pool's categories.
        $itemScores = [];
        foreach ($rows as $row) {
            $itemScores[(string) $row['content_key']] = max($itemScores[(string) $row['content_key']] ?? 0.0, (float) $row['score']);
        }
        foreach (ItemFamily::CATEGORY_FAMILIES as $pool => $family) {
            $sums = [];
            foreach (self::categoryMembers((array) ($pools[$pool] ?? [])) as $cid => $memberIds) {
                $sums[$cid] = 0.0;
                foreach ($memberIds as $memberId) {
                    $sums[$cid] += $itemScores[$memberId] ?? 0.0;
                }
            }
            $result = $this->blendAndRank($site, $family, $sums, array_keys($sums));
            $rows = array_merge($rows, $result['rows']);
            if ($result['deletes'] !== []) {
                $deletes[$family] = $result['deletes'];
            }
        }

        // The unified action layer — fail-open: a candidate/scoring fault is
        // reported and the item families still write.
        try {
            $actionResult = $this->computeActions($site, $rows, $pools);
            $rows = array_merge($rows, $actionResult['rows']);
            if ($actionResult['deletes'] !== []) {
                $deletes[ActionScorer::CONTENT_TYPE] = $actionResult['deletes'];
            }
        } catch (\Throwable $e) {
            report($e);
            Log::warning('analytics.action_scoring_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }

        return ['rows' => $rows, 'deletes' => $deletes];
    }

    /**
     * Action rows for one site: the live candidate set scored by ActionScorer.
     * Item reach reads the item-family scores computed THIS run (so a first
     * run already folds pool engagement in), falling back to stored rows.
     *
     * @param  list<array<string, mixed>>  $itemRows  this run's item-family rows
     * @return array{rows: list<array<string, mixed>>, deletes: list<string>}
     */
    private function computeActions(Site $site, array $itemRows, array $pools): array
    {
        $pro = $site->user;
        if ($pro === null) {
            return ['rows' => [], 'deletes' => []];
        }
        $itemScores = $this->popularity->itemScoresForSite($site->id);
        foreach ($itemRows as $row) {
            $key = (string) $row['content_key'];
            $itemScores[$key] = max($itemScores[$key] ?? 0.0, (float) $row['score']);
        }
        $candidates = $this->candidates->forSite($pro, $site, null, $pools);

        return $this->scorer->computeForSite($site, $candidates, $itemScores);
    }

    /**
     * The PoolWire map for a site — fail-open to [] when the content lane is
     * absent (partial test envs) so the item families still score.
     *
     * @return array<string, array<string, mixed>>
     */
    private function servedPools(Site $site): array
    {
        try {
            return $this->poolWire->forSite($site, $this->resolver);
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * Served items per home category for one pool — the first provider-null
     * collection an item belongs to (a real menu/service category), exactly
     * as ActionCandidates homes them; items with no category are not members.
     *
     * @param  array<string, mixed>  $pool  one PoolWire entry
     * @return array<string, list<string>> collection id => member item ids
     */
    public static function categoryMembers(array $pool): array
    {
        $collections = is_array($pool['collections'] ?? null) ? $pool['collections'] : [];
        $out = [];
        foreach ((array) ($pool['items'] ?? []) as $item) {
            if (! is_array($item) || ! is_string($item['id'] ?? null)) {
                continue;
            }
            foreach ((array) ($item['collectionIds'] ?? []) as $cid) {
                $cid = (string) $cid;
                if (isset($collections[$cid]) && ($collections[$cid]['provider'] ?? null) === null) {
                    $out[$cid][] = $item['id'];
                    break;
                }
            }
        }

        return $out;
    }

    /** @return array{clicks: float, impressions: float, dwell: float} */
    private static function emptySignal(): array
    {
        return ['clicks' => 0.0, 'impressions' => 0.0, 'dwell' => 0.0];
    }

    /**
     * Item-level signal per item_type: item_views (impressions) + link_clicks
     * (clicks, item_type inferred from section_key) + gallery dwell split
     * across the served media items, day-bucketed with per-day half-life
     * weights (decayed floats).
     *
     * @param  list<string>  $mediaIds  the served media pool items (dwell recipients)
     * @return array<string, array<string, array{clicks: float, impressions: float, dwell: float}>>
     */
    private function aggregateItems(Site $site, array $mediaIds = []): array
    {
        $items = [];

        $bump = function (string $type, string $id, float $clicks, float $impressions, float $dwell = 0.0) use (&$items): void {
            if ($id === '' || in_array($type, self::NEVER_SCORED, true)) {
                return;
            }
            $items[$type][$id] ??= self::emptySignal();
            $items[$type][$id]['clicks'] += $clicks;
            $items[$type][$id]['impressions'] += $impressions;
            $items[$type][$id]['dwell'] += $dwell;
        };

        $day = $this->dayBucketExpr();
        $now = now();

        // SCALE-3: bound every raw-event read to ScoringWindow — see that
        // class for why 120 days rather than the 90-day purge retention.
        // Index-covered on every one of the four tables below
        // ((site_id, occurred_at), section_views additionally on
        // section_key) — see supabase/migrations/20260726000000_baseline_pilot.sql.
        $since = ScoringWindow::since();

        // Impressions from item_views (item_type carried directly).
        DB::connection('pgsql')->table('analytics.item_views')
            ->where('site_id', $site->id)
            ->where('occurred_at', '>=', $since)
            ->selectRaw("item_type, item_id, {$day} as day, COUNT(*) as impressions")
            ->groupByRaw("item_type, item_id, {$day}")
            ->get()
            ->each(function ($r) use ($bump, $now): void {
                $w = $this->dayWeight((string) $r->day, $now);
                $bump((string) $r->item_type, (string) $r->item_id, 0.0, $w * (int) $r->impressions);
            });

        // Clicks from link_clicks.product_id (mirror topItemsBySection); item_type
        // inferred from the hosting section_key. Every family is keyed by
        // content.items.id (2026-08-23): a legacy shop click that stored the
        // catalog handle, or a link click that stored the url, is resolved to
        // the item it belongs to; an already-id-keyed click passes through.
        $aliases = $this->itemIdAliases($site);
        DB::connection('pgsql')->table('analytics.link_clicks')
            ->where('site_id', $site->id)
            ->where('occurred_at', '>=', $since)
            ->whereNotNull('product_id')
            ->whereNotNull('section_key')
            ->selectRaw("product_id, section_key, {$day} as day, COUNT(*) as clicks")
            ->groupByRaw("product_id, section_key, {$day}")
            ->get()
            ->each(function ($r) use ($bump, $now, $aliases): void {
                $type = self::CLICK_SECTION_TO_ITEM_TYPE[(string) $r->section_key] ?? null;
                if ($type === null) {
                    return;
                }
                $key = (string) $r->product_id;
                $key = $aliases[$type][$key] ?? $key;
                $bump($type, $key, $this->dayWeight((string) $r->day, $now) * (int) $r->clicks, 0.0);
            });

        // Lander taps on an item: action (D7) — a session-distinct, day-bucketed
        // tap on `item:<id>` counts as one click in that item's family (kind
        // from content.items.kind).
        $kinds = $this->itemKinds($site);
        if ($kinds !== []) {
            DB::connection('pgsql')->table('analytics.action_events')
                ->where('site_id', $site->id)
                ->where('occurred_at', '>=', $since)
                ->where('event', 'tap')
                ->where('action_id', 'like', 'item:%')
                ->selectRaw("action_id, {$day} as day, COUNT(DISTINCT COALESCE(session_id, visitor_id, id)) as sessions")
                ->groupByRaw("action_id, {$day}")
                ->get()
                ->each(function ($r) use ($bump, $now, $kinds): void {
                    $itemId = substr((string) $r->action_id, 5);
                    $family = isset($kinds[$itemId]) ? ItemFamily::forKind($kinds[$itemId]) : null;
                    if ($family === null) {
                        return;
                    }
                    $bump($family, $itemId, $this->dayWeight((string) $r->day, $now) * (int) $r->sessions, 0.0);
                });
        }

        // Media dwell approximation: the gallery page's section dwell (seconds,
        // day-bucketed, decayed) shared equally by every served media item.
        if ($mediaIds !== []) {
            $share = 1.0 / count($mediaIds);
            DB::connection('pgsql')->table('analytics.section_views')
                ->where('site_id', $site->id)
                ->where('occurred_at', '>=', $since)
                ->where('section_key', self::GALLERY_SECTION_KEY)
                ->whereNotNull('duration_ms')
                ->selectRaw("{$day} as day, SUM(duration_ms) as dwell_ms")
                ->groupByRaw($day)
                ->get()
                ->each(function ($r) use ($bump, $now, $mediaIds, $share): void {
                    $seconds = $this->dayWeight((string) $r->day, $now) * ((float) $r->dwell_ms / 1000.0) * $share;
                    if ($seconds <= 0.0) {
                        return;
                    }
                    foreach ($mediaIds as $id) {
                        $bump('gallery_item', $id, 0.0, 0.0, $seconds);
                    }
                });
        }

        return $items;
    }

    /**
     * content.items.id => kind for the site's live items (the family a lander
     * tap lands in). [] when the content lane is absent.
     *
     * @return array<string, string>
     */
    private function itemKinds(Site $site): array
    {
        try {
            return DB::connection('pgsql')->table('content.items')
                ->where('user_id', $site->user_id)
                ->whereNull('removed_at')
                ->whereIn('kind', array_keys(ItemFamily::KIND_TO_FAMILY))
                ->pluck('kind', 'id')
                ->map(static fn ($kind): string => (string) $kind)
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * Legacy click keys → item id, per family: shop_product by
     * content.f_catalog.handle, link_item by content.f_link.url. One query
     * each per site; absent when the content lane is missing (partial envs).
     *
     * @return array<string, array<string, string>> family => alias => item id
     */
    private function itemIdAliases(Site $site): array
    {
        $out = ['shop_product' => [], 'link_item' => []];
        try {
            DB::connection('pgsql')->table('content.items as i')
                ->join('content.f_catalog as fc', 'fc.item_id', '=', 'i.id')
                ->where('i.user_id', $site->user_id)
                ->where('i.kind', 'product')
                ->whereNull('i.removed_at')
                ->whereNotNull('fc.handle')
                ->get(['i.id', 'fc.handle'])
                ->each(function ($r) use (&$out): void {
                    $out['shop_product'][(string) $r->handle] ??= (string) $r->id;
                });
            DB::connection('pgsql')->table('content.items as i')
                ->join('content.f_link as fl', 'fl.item_id', '=', 'i.id')
                ->where('i.user_id', $site->user_id)
                ->where('i.kind', 'link')
                ->whereNull('i.removed_at')
                ->get(['i.id', 'fl.url'])
                ->each(function ($r) use (&$out): void {
                    $out['link_item'][(string) $r->url] ??= (string) $r->id;
                });
        } catch (QueryException) {
            // content.* lane absent — clicks keep their stored key.
        }

        return $out;
    }

    /**
     * Score, blend with previous, rank with hysteresis, and shape the upsert
     * rows + faded-key deletions for one content_type on one site.
     *
     * @param  array<string, array{clicks: float, impressions: float, dwell: float}>  $agg  decayed signals
     * @param  array<string, float>  $freshness  additive boost per content_key (ContentFreshness)
     * @return array{rows: list<array<string, mixed>>, deletes: list<string>}
     */
    private function scoreAndRank(Site $site, string $contentType, array $agg, array $freshness = []): array
    {
        // The family's weights over the ALREADY-DECAYED day-bucket sums;
        // freshness is additive with its own per-family decay.
        $w = ItemFamily::weightsFor($contentType);
        $computed = [];
        foreach ($agg as $key => $signal) {
            $computed[$key] = $w['click'] * $signal['clicks']
                + $w['view'] * $signal['impressions']
                + $w['dwell'] * $signal['dwell']
                + ($freshness[$key] ?? 0.0);
        }

        return $this->blendAndRank($site, $contentType, $computed, array_keys($agg));
    }

    /**
     * Blend computed scores with the previous run, fade stored keys that
     * carry no live signal, rank with hysteresis, and shape the rows.
     *
     * @param  array<string, float>  $computed  content_key => this run's raw score
     * @param  list<string>  $liveKeys  keys with live signal (never faded)
     * @return array{rows: list<array<string, mixed>>, deletes: list<string>}
     */
    private function blendAndRank(Site $site, string $contentType, array $computed, array $liveKeys): array
    {
        $now = now();
        $live = array_flip($liveKeys);

        // Previous stored score + rank (for blend + rank hysteresis).
        $previous = DB::connection('pgsql')->table('analytics.content_popularity_scores')
            ->where('site_id', $site->id)
            ->where('content_type', $contentType)
            ->get(['content_key', 'score', 'rank']);
        $prevScore = [];
        $prevRank = [];
        foreach ($previous as $row) {
            $prevScore[(string) $row->content_key] = (float) $row->score;
            $prevRank[(string) $row->content_key] = (int) $row->rank;
        }

        // Fade-out: stored keys with no aggregate signal this run (page lost
        // presence, raw events purged) decay through the blend (0.3·prev per
        // run) instead of freezing at their last value. Deliberately NO
        // freshness here — a gated-off page must die even if its connection is
        // recent.
        foreach ($prevScore as $key => $_prev) {
            $computed[$key] ??= 0.0;
        }

        // Blend: first-seen keys blend with themselves (new == computed).
        $blended = [];
        foreach ($computed as $key => $score) {
            $prev = $prevScore[$key] ?? $score;
            $blended[$key] = self::BLEND_NEW * $score + self::BLEND_PREV * $prev;
        }

        // Partition: signal-less keys that have faded below the floor are
        // deleted; everything else (including still-fading keys) is ranked.
        $deletes = [];
        foreach ($blended as $key => $score) {
            if (! isset($live[$key]) && $score < self::SCORE_FLOOR) {
                $deletes[] = (string) $key;
                unset($blended[$key]);
            }
        }

        $ranks = $this->rankWithHysteresis($blended, $prevRank);

        $rows = [];
        foreach ($blended as $key => $score) {
            $rows[] = [
                'id' => (string) Str::orderedUuid(),
                'site_id' => $site->id,
                'content_type' => $contentType,
                'content_key' => $key,
                'score' => $score,
                'rank' => $ranks[$key],
                'computed_at' => $now->toISOString(),
            ];
        }

        return ['rows' => $rows, 'deletes' => $deletes];
    }

    /**
     * Assign contiguous ranks (1..n) with anti-thrash. Seed order = previous rank
     * (incumbents keep their order; new keys start below, highest blended first),
     * then bubble a lower key above an upper one ONLY when it beats it by >10%.
     * First run (no previous ranks) collapses to a plain blended-score sort.
     *
     * @param  array<string, float>  $blended  content_key => blended score
     * @param  array<string, int>  $prevRank  content_key => previous rank
     * @return array<string, int> content_key => new rank
     */
    private function rankWithHysteresis(array $blended, array $prevRank): array
    {
        $keys = array_keys($blended);

        // Seed: previous rank asc (missing = bottom); tie-break higher score first.
        usort($keys, static function (string $a, string $b) use ($blended, $prevRank): int {
            $ra = $prevRank[$a] ?? PHP_INT_MAX;
            $rb = $prevRank[$b] ?? PHP_INT_MAX;
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            return $blended[$b] <=> $blended[$a];
        });

        // Bubble with the >10% gate.
        $n = count($keys);
        do {
            $swapped = false;
            for ($i = 0; $i < $n - 1; $i++) {
                $upper = $keys[$i];
                $lower = $keys[$i + 1];
                if ($blended[$lower] > $blended[$upper] * (1 + self::RANK_SWAP_THRESHOLD)) {
                    [$keys[$i], $keys[$i + 1]] = [$keys[$i + 1], $keys[$i]];
                    $swapped = true;
                }
            }
        } while ($swapped);

        $ranks = [];
        foreach ($keys as $idx => $key) {
            $ranks[$key] = $idx + 1;
        }

        return $ranks;
    }

    /**
     * Driver-portable day-bucket expression for occurred_at — Postgres in prod,
     * SQLite in the test suite. Both yield 'YYYY-MM-DD' strings.
     */
    private function dayBucketExpr(): string
    {
        return DB::connection('pgsql')->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m-%d', occurred_at)"
            : '(occurred_at::date)::text';
    }

    /** True-half-life weight for one day bucket: 2^(-age_days / HALF_LIFE_DAYS). */
    private function dayWeight(string $day, \DateTimeInterface $now): float
    {
        $ageDays = max(0.0, ($now->getTimestamp() - Carbon::parse($day)->getTimestamp()) / 86400.0);

        return 2 ** (-$ageDays / self::HALF_LIFE_DAYS);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, list<string>>  $deletes
     */
    private function reportDryRun(Site $site, array $rows, array $deletes = []): void
    {
        $byType = [];
        foreach ($rows as $row) {
            $byType[$row['content_type']][] = $row;
        }
        $this->line("site {$site->id}:");
        foreach ($byType as $type => $typeRows) {
            usort($typeRows, static fn ($a, $b) => $a['rank'] <=> $b['rank']);
            $summary = implode(', ', array_map(
                static fn ($r) => sprintf('%s#%d(%.2f)', $r['content_key'], $r['rank'], $r['score']),
                array_slice($typeRows, 0, 8),
            ));
            $this->line(sprintf('  %-14s %d rows: %s', $type, count($typeRows), $summary));
        }
        foreach ($deletes as $type => $keys) {
            $this->line(sprintf(
                '  %-14s would delete %d faded: %s',
                $type,
                count($keys),
                implode(', ', array_slice($keys, 0, 8)),
            ));
        }
    }
}
