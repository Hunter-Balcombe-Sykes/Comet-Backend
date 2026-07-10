<?php

namespace App\Console\Commands;

use App\Enums\SitepageId;
use App\Models\Core\Site\Site;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Analytics\ContentFreshness;
use App\Services\Analytics\RankedActionsComputer;
use App\Services\PublicSite\SiteActionsService;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Recompute analytics.content_popularity_scores from the raw event tables.
 *
 * Reads ONLY raw events (analytics.section_views, .link_clicks, .item_views) —
 * never the vestigial site_metrics_daily/_hourly (no model/reader/writer). Per
 * site it produces two score families:
 *   - page scores      : section_views impressions + link_clicks, bucketed to
 *                        page-ids via SitepageId::SECTION_KEY_TO_PAGE, then
 *                        presence + Business gated (shared resolver gate).
 *   - item scores      : link_clicks.product_id + item_views, keyed
 *                        (item_type, item_id).
 * plus a third, DERIVED family layered on top (content_type='action', keyed
 * '<kind>:<ref>'): the unified page|item|button ranked-action list computed by
 * RankedActionsComputer from this run's blended scores + button click signals.
 * The action layer owns its own lifecycle (stale keys deleted per run) and is
 * excluded from the generic fade-out union below.
 *
 * Formula: score = Σ_days (W_CLICK·clicks_d + W_VIEW·impressions_d +
 * W_DWELL_PER_SECOND·dwell_s_d) · 2^(-age_d / HALF_LIFE_DAYS) + freshness.
 * Every DAY's events carry their own true-half-life decay weight (day buckets,
 * driver-portable SQL), so old engagement fades even while new events arrive.
 * The previous model multiplied the WHOLE-history sum by decay-since-the-LATEST
 * event — one fresh click "resurrected" years of stale counts to full weight and
 * dormant-with-a-trickle content was indistinguishable from active content.
 * dwell_s (page grain only) is that day's SUM(duration_ms)/1000; at 0.05/s,
 * 20s ≈ one impression and 60s ≈ one click. Items have no dwell signal (term 0).
 *
 * freshness (ContentFreshness) is an additive, 14-day-half-life boost from the
 * owning row's created_at — pages via their newest active connection (+ native
 * services → book), link_items via their custom connection. Zero-signal keys with
 * a live boost are SEEDED into the aggregate so brand-new content ranks before
 * its first event (cold start), then fades back to engagement ranking.
 *
 * Anti-thrash: the stored score is blended with the previous (0.7·new + 0.3·old)
 * and a row only overtakes the one ranked above it when its blended score beats
 * that incumbent by >10%. Upserted on (site_id, content_type, content_key).
 *
 * Fade-out: stored keys that no longer aggregate any signal (a page lost
 * presence, raw events purged by retention) decay through the blend
 * (new = 0 → 0.3·prev per run, no freshness) and are DELETED once below
 * SCORE_FLOOR — stale rows can't freeze at their last score/rank forever.
 */
class ComputeContentPopularityScores extends Command
{
    protected $signature = 'analytics:compute-popularity
                            {--dry-run : Report computed scores without writing}
                            {--site= : Restrict to a single site id (uuid)}';

    protected $description = 'Recompute content_popularity_scores (pages + scored items) from raw analytics events.';

    // Scoring weights + decay. Tune here — the only tuning surface.
    private const W_CLICK = 3.0;

    private const W_VIEW = 1.0;

    // Per-second dwell weight (pages only): 20s on a page ≈ one impression (1.0),
    // 60s ≈ one click (3.0). Ingest caps a single report at 600s, so one visitor
    // contributes at most 30.0 (~10 clicks) of dwell per impression row.
    private const W_DWELL_PER_SECOND = 0.05;

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
        private readonly SitepageDataResolverService $resolver,
        private readonly ContentFreshness $freshness,
        private readonly SiteActionsService $actions,
        private readonly RankedActionsComputer $rankedActions,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $siteOpt = $this->option('site');

        // Eager-load user — aggregatePages reads $site->user for AccountCapabilities
        // gating, and prod runs with lazy-loading disabled (Model::preventLazyLoading).
        $query = Site::query()->where('is_published', true)->with('user');
        if (is_string($siteOpt) && $siteOpt !== '') {
            $query->where('id', $siteOpt);
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
     * Compute every upsert row + faded-key deletion for one site. Content types
     * come from the UNION of live aggregates and already-stored rows — a type
     * whose events were all purged must still fade its stored keys out.
     *
     * @return array{rows: list<array<string, mixed>>, deletes: array<string, list<string>>}
     */
    private function computeForSite(Site $site): array
    {
        // Freshness boosts (additive term) + the zero-signal keys they seed so
        // brand-new content ranks before its first event.
        $fresh = $this->freshness->boostsForSite($site);

        // Decayed signals keyed content_type => content_key => [clicks, impressions, dwell_ms] (floats).
        $pageAgg = $this->aggregatePages($site, array_keys($fresh['page']));
        $itemAgg = $this->aggregateItems($site);

        // Seed fresh link_items with zero signals (items have no presence gate).
        foreach ($fresh['link_item'] as $key => $_boost) {
            $itemAgg['link_item'][$key] ??= ['clicks' => 0.0, 'impressions' => 0.0, 'dwell_ms' => 0.0];
        }

        // 'action' rows (ranked-actions layer) are OWNED by computeActions
        // below — excluded from the generic union so the fade-out loop never
        // treats them as a signal-less content family.
        $storedTypes = DB::connection('pgsql')->table('analytics.content_popularity_scores')
            ->where('site_id', $site->id)
            ->where('content_type', '!=', RankedActionsComputer::CONTENT_TYPE)
            ->distinct()
            ->pluck('content_type')
            ->all();

        $types = array_values(array_unique(array_merge(
            $pageAgg !== [] ? ['page'] : [],
            array_keys($itemAgg),
            $storedTypes,
        )));

        $rows = [];
        $deletes = [];

        foreach ($types as $type) {
            $agg = $type === 'page' ? $pageAgg : ($itemAgg[$type] ?? []);
            $freshMap = match ($type) {
                'page' => $fresh['page'],
                'link_item' => $fresh['link_item'],
                default => [],
            };
            $result = $this->scoreAndRank($site, $type, $agg, $freshMap);
            $rows = array_merge($rows, $result['rows']);
            if ($result['deletes'] !== []) {
                $deletes[$type] = $result['deletes'];
            }
        }

        // Ranked actions (unified page|item|button list) — layered ON TOP of
        // the rows above; fail-open so an action-layer fault degrades to "no
        // rankedActions refresh", never to broken page/item scores.
        try {
            $actionResult = $this->computeActions($site, $rows);
            $rows = array_merge($rows, $actionResult['rows']);
            if ($actionResult['deletes'] !== []) {
                $deletes[RankedActionsComputer::CONTENT_TYPE] = $actionResult['deletes'];
            }
        } catch (\Throwable $e) {
            Log::warning('analytics.ranked_actions_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }

        return ['rows' => $rows, 'deletes' => $deletes];
    }

    /**
     * Ranked-action rows for one site from THIS RUN's blended page/item scores
     * (fresher than the stored rows mid-run) + the live action pool.
     *
     * @param  list<array<string, mixed>>  $rows  this run's upsert rows (page + item types)
     * @return array{rows: list<array<string, mixed>>, deletes: list<string>}
     */
    private function computeActions(Site $site, array $rows): array
    {
        $pro = $site->user;
        if ($pro === null) {
            return ['rows' => [], 'deletes' => []];
        }

        $pageScores = [];
        $itemScores = [];
        $itemRanks = [];
        foreach ($rows as $row) {
            if ($row['content_type'] === 'page') {
                $pageScores[$row['content_key']] = (float) $row['score'];
            } else {
                $itemScores[$row['content_type']][$row['content_key']] = (float) $row['score'];
                $itemRanks[$row['content_type']][$row['content_key']] = (int) $row['rank'];
            }
        }

        $pool = $this->actions->pool($pro, $site, ranks: $itemRanks);

        return $this->rankedActions->computeForSite($site, $pool, $pageScores, $itemScores);
    }

    /**
     * Page-level signal: section_views (impressions + dwell) + link_clicks
     * (clicks), day-bucketed with each day carrying its own half-life weight,
     * folded to page-ids, then presence + Business gated. Values are DECAYED
     * floats — the day weight is already applied.
     *
     * $seedPages (freshness) enter with zero signals BEFORE the presence gate,
     * so a brand-new page ranks by its boost alone — but only when present.
     *
     * @param  list<string>  $seedPages
     * @return array<string, array{clicks: float, impressions: float, dwell_ms: float}>
     */
    private function aggregatePages(Site $site, array $seedPages = []): array
    {
        $pages = [];

        $bump = function (?string $sectionKey, float $clicks, float $impressions, float $dwellMs) use (&$pages): void {
            $page = SitepageId::SECTION_KEY_TO_PAGE[(string) $sectionKey] ?? null;
            if ($page === null) {
                return;
            }
            $pages[$page] ??= ['clicks' => 0.0, 'impressions' => 0.0, 'dwell_ms' => 0.0];
            $pages[$page]['clicks'] += $clicks;
            $pages[$page]['impressions'] += $impressions;
            $pages[$page]['dwell_ms'] += $dwellMs;
        };

        $day = $this->dayBucketExpr();
        $now = now();

        // Impressions + dwell per (section, day); duration_ms is NULL until a
        // dwell beacon annotates the row, so SUM needs the COALESCE.
        DB::connection('pgsql')->table('analytics.section_views')
            ->where('site_id', $site->id)
            ->whereNotNull('section_key')
            ->selectRaw("section_key, {$day} as day, COUNT(*) as impressions, COALESCE(SUM(duration_ms), 0) as dwell_ms")
            ->groupByRaw("section_key, {$day}")
            ->get()
            ->each(function ($r) use ($bump, $now): void {
                $w = $this->dayWeight((string) $r->day, $now);
                $bump($r->section_key, 0.0, $w * (int) $r->impressions, $w * (int) $r->dwell_ms);
            });

        // Clicks per (section, day).
        DB::connection('pgsql')->table('analytics.link_clicks')
            ->where('site_id', $site->id)
            ->whereNotNull('section_key')
            ->selectRaw("section_key, {$day} as day, COUNT(*) as clicks")
            ->groupByRaw("section_key, {$day}")
            ->get()
            ->each(function ($r) use ($bump, $now): void {
                $bump($r->section_key, $this->dayWeight((string) $r->day, $now) * (int) $r->clicks, 0.0, 0.0);
            });

        foreach ($seedPages as $page) {
            $pages[$page] ??= ['clicks' => 0.0, 'impressions' => 0.0, 'dwell_ms' => 0.0];
        }

        if ($pages === []) {
            return [];
        }

        // Presence + Business gate — same shared gate the payload's pageOrder uses.
        $pro = $site->user;
        if ($pro === null) {
            return [];
        }
        $caps = AccountCapabilities::for($pro);
        $present = array_flip($this->resolver->presentPageIds($site, $caps, $this->resolver->loadSections($site)));

        return array_intersect_key($pages, $present);
    }

    /**
     * Item-level signal per item_type: item_views (impressions) + link_clicks
     * (clicks, item_type inferred from section_key), day-bucketed with per-day
     * half-life weights (decayed floats, same as aggregatePages).
     *
     * @return array<string, array<string, array{clicks: float, impressions: float, dwell_ms: float}>>
     */
    private function aggregateItems(Site $site): array
    {
        $items = [];

        $bump = function (string $type, string $id, float $clicks, float $impressions) use (&$items): void {
            if ($id === '') {
                return;
            }
            $items[$type][$id] ??= ['clicks' => 0.0, 'impressions' => 0.0, 'dwell_ms' => 0.0];
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
        // inferred from the hosting section_key.
        DB::connection('pgsql')->table('analytics.link_clicks')
            ->where('site_id', $site->id)
            ->whereNotNull('product_id')
            ->whereNotNull('section_key')
            ->selectRaw("product_id, section_key, {$day} as day, COUNT(*) as clicks")
            ->groupByRaw("product_id, section_key, {$day}")
            ->get()
            ->each(function ($r) use ($bump, $now): void {
                $type = self::CLICK_SECTION_TO_ITEM_TYPE[(string) $r->section_key] ?? null;
                if ($type === null) {
                    return;
                }
                $bump($type, (string) $r->product_id, $this->dayWeight((string) $r->day, $now) * (int) $r->clicks, 0.0);
            });

        return $items;
    }

    /**
     * Score, blend with previous, rank with hysteresis, and shape the upsert
     * rows + faded-key deletions for one content_type on one site.
     *
     * @param  array<string, array{clicks: float, impressions: float, dwell_ms: float}>  $agg  decayed signals
     * @param  array<string, float>  $freshness  additive boost per content_key (ContentFreshness)
     * @return array{rows: list<array<string, mixed>>, deletes: list<string>}
     */
    private function scoreAndRank(Site $site, string $contentType, array $agg, array $freshness = []): array
    {
        $now = now();

        // Signal weights over the ALREADY-DECAYED day-bucket sums; freshness is
        // additive with its own (14d) decay. dwell_ms only exists on the page
        // grain (items carry no dwell signal — their term is 0).
        $computed = [];
        foreach ($agg as $key => $signal) {
            $computed[$key] = self::W_CLICK * $signal['clicks']
                + self::W_VIEW * $signal['impressions']
                + self::W_DWELL_PER_SECOND * ($signal['dwell_ms'] ?? 0.0) / 1000.0
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
