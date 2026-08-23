<?php

namespace App\Console\Commands;

use App\Models\Core\Site\Site;
use App\Services\Analytics\ActionScorer;
use App\Services\Analytics\ContentFreshness;
use App\Services\Analytics\ContentPopularityReader;
use App\Site\Actions\ActionCandidates;
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
 *   - action scores: content_type='action', keyed by App\Site\Actions\ActionId
 *                    — the unified lander list (pages + destination platforms
 *                    + served items + categories) scored by ActionScorer:
 *                    demand rate + reach + freshness + prior, see that class.
 *                    Page order reads the page:* action rows; the separate
 *                    page-score family was retired 2026-08-23. The action layer
 *                    owns its own lifecycle (stale keys deleted per run) and is
 *                    excluded from the generic fade-out union below.
 *
 * Item formula: score = Σ_days (W_CLICK·clicks_d + W_VIEW·impressions_d)
 * · 2^(-age_d / HALF_LIFE_DAYS) + freshness. Every DAY's events carry their
 * own true-half-life decay weight (day buckets, driver-portable SQL), so old
 * engagement fades even while new events arrive.
 *
 * freshness (ContentFreshness) is an additive, 14-day-half-life boost from the
 * link item's created_at. Zero-signal keys with a live boost are SEEDED into
 * the aggregate so a brand-new link ranks before its first event (cold start).
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

    // Scoring weights + decay. Tune here — the only tuning surface.
    private const W_CLICK = 3.0;

    private const W_VIEW = 1.0;

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
        'events' => 'engine_item',
        'attend' => 'engine_item',
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
    ];

    public function __construct(
        private readonly ContentFreshness $freshness,
        private readonly ActionCandidates $candidates,
        private readonly ActionScorer $scorer,
        private readonly ContentPopularityReader $popularity,
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
     * Distinct site ids with at least one raw event since $since, across the same
     * three tables this command already reads (section_views/link_clicks/item_views
     * — see the class docblock). PHP-side union of three DISTINCT plucks rather
     * than a cross-table SQL UNION so the query stays a plain per-table filter,
     * identical on Postgres and the SQLite test schema.
     *
     * @return list<string>
     */
    private function siteIdsWithRecentEvents(Carbon $since): array
    {
        $ids = [];
        foreach (['analytics.section_views', 'analytics.link_clicks', 'analytics.item_views'] as $table) {
            foreach (
                DB::connection('pgsql')->table($table)
                    ->where('occurred_at', '>=', $since->toISOString())
                    ->distinct()
                    ->pluck('site_id') as $siteId
            ) {
                $ids[(string) $siteId] = true;
            }
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
        $fresh = $this->freshness->boostsForSite($site);
        $itemAgg = $this->aggregateItems($site);
        foreach ($fresh['link_item'] as $key => $_boost) {
            $itemAgg['link_item'][$key] ??= ['clicks' => 0.0, 'impressions' => 0.0];
        }

        // Every stored non-action type joins the union so a type that lost all
        // signal still fades out (the 'action' type owns its own lifecycle).
        $storedTypes = DB::connection('pgsql')->table('analytics.content_popularity_scores')
            ->where('site_id', $site->id)
            ->where('content_type', '!=', ActionScorer::CONTENT_TYPE)
            ->distinct()
            ->pluck('content_type')
            ->all();
        $types = array_values(array_unique(array_merge(array_keys($itemAgg), $storedTypes)));

        $rows = [];
        $deletes = [];
        foreach ($types as $type) {
            $result = $this->scoreAndRank($site, $type, $itemAgg[$type] ?? [], $type === 'link_item' ? $fresh['link_item'] : []);
            $rows = array_merge($rows, $result['rows']);
            if ($result['deletes'] !== []) {
                $deletes[$type] = $result['deletes'];
            }
        }

        // The unified action layer — fail-open: a candidate/scoring fault is
        // reported and the item families still write.
        try {
            $actionResult = $this->computeActions($site, $rows);
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
    private function computeActions(Site $site, array $itemRows): array
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
        $candidates = $this->candidates->forSite($pro, $site);

        return $this->scorer->computeForSite($site, $candidates, $itemScores);
    }

    /**
     * Item-level signal per item_type: item_views (impressions) + link_clicks
     * (clicks, item_type inferred from section_key), day-bucketed with per-day
     * half-life weights (decayed floats).
     *
     * @return array<string, array<string, array{clicks: float, impressions: float}>>
     */
    private function aggregateItems(Site $site): array
    {
        $items = [];

        $bump = function (string $type, string $id, float $clicks, float $impressions) use (&$items): void {
            if ($id === '') {
                return;
            }
            $items[$type][$id] ??= ['clicks' => 0.0, 'impressions' => 0.0];
            $items[$type][$id]['clicks'] += $clicks;
            $items[$type][$id]['impressions'] += $impressions;
        };

        $day = $this->dayBucketExpr();
        $now = now();

        // Impressions from item_views (item_type carried directly).
        DB::connection('pgsql')->table('analytics.item_views')
            ->where('site_id', $site->id)
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

        return $items;
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
     * @param  array<string, array{clicks: float, impressions: float}>  $agg  decayed signals
     * @param  array<string, float>  $freshness  additive boost per content_key (ContentFreshness)
     * @return array{rows: list<array<string, mixed>>, deletes: list<string>}
     */
    private function scoreAndRank(Site $site, string $contentType, array $agg, array $freshness = []): array
    {
        $now = now();

        // Signal weights over the ALREADY-DECAYED day-bucket sums; freshness is
        // additive with its own (14d) decay.
        $computed = [];
        foreach ($agg as $key => $signal) {
            $computed[$key] = self::W_CLICK * $signal['clicks']
                + self::W_VIEW * $signal['impressions']
                + ($freshness[$key] ?? 0.0);
        }

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
            if (! isset($agg[$key]) && $score < self::SCORE_FLOOR) {
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
