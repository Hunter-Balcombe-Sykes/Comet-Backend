<?php

namespace App\Console\Commands;

use App\Enums\SitepageId;
use App\Models\Core\Site\Site;
use App\Services\Accounts\AccountCapabilities;
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
 *
 * Formula: score = (W_CLICK·clicks + W_VIEW·impressions) · recency, where
 * recency = exp(-age_days / HALF_LIFE_DAYS). age_days is measured from the most
 * recent contributing event (event recency — a uniform, event-table-only basis
 * that also covers pages, which have no single backing content row).
 *
 * Anti-thrash: the stored score is blended with the previous (0.7·new + 0.3·old)
 * and a row only overtakes the one ranked above it when its blended score beats
 * that incumbent by >10%. Upserted on (site_id, content_type, content_key).
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

    private const HALF_LIFE_DAYS = 90.0;

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

        $query->chunkById(self::SITE_CHUNK, function ($sites) use ($dryRun, &$sitesProcessed, &$rowsWritten) {
            foreach ($sites as $site) {
                $rows = $this->computeForSite($site);
                $sitesProcessed++;

                if ($rows === []) {
                    continue;
                }

                if ($dryRun) {
                    $this->reportDryRun($site, $rows);

                    continue;
                }

                DB::connection('pgsql')
                    ->table('analytics.content_popularity_scores')
                    ->upsert(
                        $rows,
                        ['site_id', 'content_type', 'content_key'],
                        ['score', 'rank', 'computed_at'],
                    );
                $rowsWritten += count($rows);
            }
        });

        $this->info(sprintf(
            '%s %d sites; %s %d score rows.',
            $dryRun ? 'Scanned' : 'Processed',
            $sitesProcessed,
            $dryRun ? 'would write' : 'wrote',
            $rowsWritten,
        ));

        Log::info('analytics:compute-popularity completed', [
            'dry_run' => $dryRun,
            'sites' => $sitesProcessed,
            'rows' => $rowsWritten,
            'site_filter' => is_string($siteOpt) ? $siteOpt : null,
        ]);

        return self::SUCCESS;
    }

    /**
     * Compute every upsert row for one site (all content_types). Empty when the
     * site has no scoreable events.
     *
     * @return list<array<string, mixed>>
     */
    private function computeForSite(Site $site): array
    {
        // Raw signals: {score-basis} keyed as content_type => content_key => [clicks, impressions, last_at].
        $pageAgg = $this->aggregatePages($site);
        $itemAgg = $this->aggregateItems($site);

        $rows = [];

        if ($pageAgg !== []) {
            $rows = array_merge($rows, $this->scoreAndRank($site, 'page', $pageAgg));
        }

        foreach ($itemAgg as $itemType => $agg) {
            if ($agg !== []) {
                $rows = array_merge($rows, $this->scoreAndRank($site, $itemType, $agg));
            }
        }

        return $rows;
    }

    /**
     * Page-level signal: section_views (impressions) + link_clicks (clicks),
     * bucketed to page-ids, then presence + Business gated. Keyed page-id =>
     * ['clicks', 'impressions', 'last_at'].
     *
     * @return array<string, array{clicks: int, impressions: int, last_at: ?string}>
     */
    private function aggregatePages(Site $site): array
    {
        $pages = [];

        $bump = function (?string $sectionKey, int $clicks, int $impressions, ?string $lastAt) use (&$pages): void {
            $page = SitepageId::SECTION_KEY_TO_PAGE[(string) $sectionKey] ?? null;
            if ($page === null) {
                return;
            }
            $pages[$page] ??= ['clicks' => 0, 'impressions' => 0, 'last_at' => null];
            $pages[$page]['clicks'] += $clicks;
            $pages[$page]['impressions'] += $impressions;
            $pages[$page]['last_at'] = $this->maxDate($pages[$page]['last_at'], $lastAt);
        };

        // Impressions from section_views (mirror topSections' GROUP BY).
        DB::connection('pgsql')->table('analytics.section_views')
            ->where('site_id', $site->id)
            ->whereNotNull('section_key')
            ->selectRaw('section_key, COUNT(*) as impressions, MAX(occurred_at) as last_at')
            ->groupBy('section_key')
            ->get()
            ->each(fn ($r) => $bump($r->section_key, 0, (int) $r->impressions, $r->last_at));

        // Clicks from link_clicks by section_key.
        DB::connection('pgsql')->table('analytics.link_clicks')
            ->where('site_id', $site->id)
            ->whereNotNull('section_key')
            ->selectRaw('section_key, COUNT(*) as clicks, MAX(occurred_at) as last_at')
            ->groupBy('section_key')
            ->get()
            ->each(fn ($r) => $bump($r->section_key, (int) $r->clicks, 0, $r->last_at));

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
     * (clicks, item_type inferred from section_key). Returns item_type =>
     * (content_key => ['clicks','impressions','last_at']).
     *
     * @return array<string, array<string, array{clicks: int, impressions: int, last_at: ?string}>>
     */
    private function aggregateItems(Site $site): array
    {
        $items = [];

        $bump = function (string $type, string $id, int $clicks, int $impressions, ?string $lastAt) use (&$items): void {
            if ($id === '') {
                return;
            }
            $items[$type][$id] ??= ['clicks' => 0, 'impressions' => 0, 'last_at' => null];
            $items[$type][$id]['clicks'] += $clicks;
            $items[$type][$id]['impressions'] += $impressions;
            $items[$type][$id]['last_at'] = $this->maxDate($items[$type][$id]['last_at'], $lastAt);
        };

        // Impressions from item_views (item_type carried directly).
        DB::connection('pgsql')->table('analytics.item_views')
            ->where('site_id', $site->id)
            ->selectRaw('item_type, item_id, COUNT(*) as impressions, MAX(occurred_at) as last_at')
            ->groupBy('item_type', 'item_id')
            ->get()
            ->each(fn ($r) => $bump((string) $r->item_type, (string) $r->item_id, 0, (int) $r->impressions, $r->last_at));

        // Clicks from link_clicks.product_id (mirror topItemsBySection); item_type
        // inferred from the hosting section_key.
        DB::connection('pgsql')->table('analytics.link_clicks')
            ->where('site_id', $site->id)
            ->whereNotNull('product_id')
            ->whereNotNull('section_key')
            ->selectRaw('product_id, section_key, COUNT(*) as clicks, MAX(occurred_at) as last_at')
            ->groupBy('product_id', 'section_key')
            ->get()
            ->each(function ($r) use ($bump): void {
                $type = self::CLICK_SECTION_TO_ITEM_TYPE[(string) $r->section_key] ?? null;
                if ($type === null) {
                    return;
                }
                $bump($type, (string) $r->product_id, (int) $r->clicks, 0, $r->last_at);
            });

        return $items;
    }

    /**
     * Score, blend with previous, rank with hysteresis, and shape upsert rows for
     * one content_type on one site.
     *
     * @param  array<string, array{clicks: int, impressions: int, last_at: ?string}>  $agg
     * @return list<array<string, mixed>>
     */
    private function scoreAndRank(Site $site, string $contentType, array $agg): array
    {
        $now = now();

        // Raw computed score per content_key.
        $computed = [];
        foreach ($agg as $key => $signal) {
            $ageDays = $this->ageDays($signal['last_at'], $now);
            $recency = exp(-$ageDays / self::HALF_LIFE_DAYS);
            $computed[$key] = (self::W_CLICK * $signal['clicks'] + self::W_VIEW * $signal['impressions']) * $recency;
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

        // Blend: first-seen keys blend with themselves (new == computed).
        $blended = [];
        foreach ($computed as $key => $score) {
            $prev = $prevScore[$key] ?? $score;
            $blended[$key] = self::BLEND_NEW * $score + self::BLEND_PREV * $prev;
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

        return $rows;
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

    /** Whole days between $lastAt and $now (>= 0). Null last_at → 0 (treated as fresh). */
    private function ageDays(?string $lastAt, \DateTimeInterface $now): float
    {
        if ($lastAt === null || $lastAt === '') {
            return 0.0;
        }

        $then = Carbon::parse($lastAt);

        return max(0.0, $now->getTimestamp() - $then->getTimestamp()) / 86400.0;
    }

    /** Max of two nullable ISO date strings. */
    private function maxDate(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return $a >= $b ? $a : $b;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function reportDryRun(Site $site, array $rows): void
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
    }
}
