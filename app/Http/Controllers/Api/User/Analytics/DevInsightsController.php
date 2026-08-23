<?php

namespace App\Http\Controllers\Api\User\Analytics;

use App\Console\Commands\ComputeContentPopularityScores;
use App\Enums\SitepageId;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Services\Analytics\ContentFreshness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * DEV / testing endpoint — surfaces the raw popularity scores AND the analytics
 * that feed them (impressions + clicks), per page and per scored item, for the
 * authenticated professional's own site. Not production-critical: it exists so
 * scores can be explained + demoed, mirroring the dashboard's own "each thing has
 * a page" pattern (a list → click through to a per-entity breakdown + graph).
 *
 * Plain-array response (no Resource), no cache — the same ad-hoc norm the sibling
 * UserAnalyticsController uses for its analytics reads.
 *
 * @see ComputeContentPopularityScores  the job whose output this explains
 */
class DevInsightsController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(
        private readonly ContentFreshness $freshness,
    ) {}

    /**
     * Mirrors ComputeContentPopularityScores::CLICK_SECTION_TO_ITEM_TYPE (a private
     * const there, not importable). Maps a click's section_key → the item_type its
     * clicks score as, so this endpoint can attribute link_clicks to the same item
     * grain the scoring job does. Keep in lockstep by hand.
     */
    private const CLICK_SECTION_TO_ITEM_TYPE = [
        'shop' => 'shop_product', 'shop-products' => 'shop_product', 'shop-tracks' => 'shop_product', 'bandcamp' => 'shop_product',
        'book' => 'service', 'services' => 'service',
        'events' => 'engine_item', 'attend' => 'engine_item',
        'listen' => 'listen_item', 'music' => 'listen_item', 'spotify' => 'listen_item', 'apple-music' => 'listen_item', 'soundcloud' => 'listen_item', 'podcast' => 'listen_item',
        'watch' => 'watch_item', 'youtube' => 'watch_item', 'twitch' => 'watch_item', 'vimeo' => 'watch_item',
        'custom' => 'link_item', 'other' => 'link_item',
    ];

    /**
     * GET /api/professional/dev-insights
     *
     * @return JsonResponse {
     *                      pages: array<array{page, score, rank, computed_at, impressions, clicks}>,
     *                      items: array<string /*content_type* /, array<array{content_key, title, score, rank, computed_at, impressions, clicks}>>,
     *                      daily_series: array{ pages: array<day, array<page, array{impressions?, clicks?}>>, items: array<day, array<"type:id", array{impressions?, clicks?}>> }
     *                      }
     */
    public function index(Request $request): JsonResponse
    {
        $site = $this->currentSite($this->currentUser($request));
        // Dev/testing endpoint only — days of daily-series history to return
        // (the per-entity change-over-time graph).
        $seriesDays = (int) config('partna.analytics.dev_insights_series_days', 30);
        $since = Carbon::now()->utc()->subDays($seriesDays)->startOfDay();

        // Same freshness boosts the scoring job applies — surfaced so the score
        // breakdown can show the additive term per page / link item.
        $fresh = $this->freshness->boostsForSite($site);

        return $this->success([
            'pages' => $this->pageScores($site->id),
            'items' => $this->itemScores($site->id, $fresh['link_item']),
            'daily_series' => [
                'pages' => $this->pageDailySeries($site->id, $since),
                'items' => $this->itemDailySeries($site->id, $since),
            ],
        ]);
    }

    /**
     * Page scores + the impressions/clicks feeding them, section_keys folded to
     * their page via SECTION_KEY_TO_PAGE. A page has no direct events — it is the
     * sum of its sections' section_views (impressions) and link_clicks (clicks).
     *
     * @return array<array{page: string, score: float|null, rank: int|null, computed_at: mixed, impressions: int, clicks: int, dwell_seconds: int}>
     */
    private function pageScores(string $siteId): array
    {
        // Pages are actions since 2026-08-23: their rows live in the 'action'
        // family as page:<id>. Stripped here so the dev view keeps page ids.
        $scores = DB::connection('pgsql')->table('analytics.content_popularity_scores')
            ->where('site_id', $siteId)->where('content_type', 'action')
            ->where('content_key', 'like', 'page:%')
            ->orderBy('rank')->get(['content_key', 'score', 'rank', 'computed_at'])
            ->keyBy(fn ($row): string => substr((string) $row->content_key, 5));

        $impressions = $this->foldToPage(
            DB::connection('pgsql')->table('analytics.section_views')
                ->where('site_id', $siteId)->whereNotNull('section_key')
                ->selectRaw('section_key, COUNT(*) as n')->groupBy('section_key')->pluck('n', 'section_key')
        );
        // Dwell (page grain only): SUM of the section-dwell annotations, in ms —
        // surfaced in seconds since that's the unit the score weights (0.05/s).
        $dwellMs = $this->foldToPage(
            DB::connection('pgsql')->table('analytics.section_views')
                ->where('site_id', $siteId)->whereNotNull('section_key')
                ->selectRaw('section_key, COALESCE(SUM(duration_ms), 0) as n')->groupBy('section_key')->pluck('n', 'section_key')
        );
        $clicks = $this->foldToPage(
            DB::connection('pgsql')->table('analytics.link_clicks')
                ->where('site_id', $siteId)->whereNotNull('section_key')
                ->selectRaw('section_key, COUNT(*) as n')->groupBy('section_key')->pluck('n', 'section_key')
        );

        $pages = collect($scores->keys())->merge(array_keys($impressions))->merge(array_keys($clicks))->unique();

        return $pages->map(fn (string $p): array => [
            'page' => $p,
            'score' => $scores->get($p) !== null ? (float) $scores->get($p)->score : null,
            'rank' => $scores->get($p)?->rank !== null ? (int) $scores->get($p)->rank : null,
            'computed_at' => $scores->get($p)?->computed_at,
            'impressions' => $impressions[$p] ?? 0,
            'clicks' => $clicks[$p] ?? 0,
            'dwell_seconds' => (int) round(($dwellMs[$p] ?? 0) / 1000),
        ])->sortBy(fn (array $r): int => $r['rank'] ?? PHP_INT_MAX)->values()->all();
    }

    /**
     * Collapse a section_key => count map onto page => summed count.
     *
     * @param  Collection<string, int>  $bySectionKey
     * @return array<string, int>
     */
    private function foldToPage($bySectionKey): array
    {
        $out = [];
        foreach ($bySectionKey as $sectionKey => $n) {
            $page = SitepageId::SECTION_KEY_TO_PAGE[$sectionKey] ?? null;
            if ($page !== null) {
                $out[$page] = ($out[$page] ?? 0) + (int) $n;
            }
        }

        return $out;
    }

    /**
     * Item scores grouped by content_type, each with the impressions (item_views)
     * and clicks (link_clicks, attributed by section_key) that feed them.
     *
     * @param  array<string, float>  $linkFreshness  additive boost per link_item content_key
     * @return array<string, list<array{content_key: string, title: string|null, score: float, rank: int, computed_at: string, impressions: int, clicks: int, freshness: float}>>
     */
    private function itemScores(string $siteId, array $linkFreshness = []): array
    {
        $scores = DB::connection('pgsql')->table('analytics.content_popularity_scores')
            ->where('site_id', $siteId)->where('content_type', '!=', 'page')
            ->orderBy('content_type')->orderBy('rank')
            ->get(['content_type', 'content_key', 'score', 'rank', 'computed_at']);

        // Impressions per "{item_type}:{item_id}" from item_views (carries its own type + title).
        $impressions = [];
        $titles = [];
        foreach (DB::connection('pgsql')->table('analytics.item_views')
            ->where('site_id', $siteId)
            ->selectRaw('item_type, item_id, MAX(item_title) as title, COUNT(*) as n')
            ->groupBy('item_type', 'item_id')->get() as $r) {
            $key = "{$r->item_type}:{$r->item_id}";
            $impressions[$key] = (int) $r->n;
            $titles[$key] = $r->title;
        }

        // Clicks per "{item_type}:{product_id}" — item_type inferred from section_key,
        // exactly as the scoring job attributes item clicks.
        $clicks = [];
        foreach (DB::connection('pgsql')->table('analytics.link_clicks')
            ->where('site_id', $siteId)->whereNotNull('product_id')->whereNotNull('section_key')
            ->selectRaw('product_id, section_key, MAX(product_title) as title, COUNT(*) as n')
            ->groupBy('product_id', 'section_key')->get() as $r) {
            $itemType = self::CLICK_SECTION_TO_ITEM_TYPE[$r->section_key] ?? null;
            if ($itemType === null) {
                continue;
            }
            $key = "{$itemType}:{$r->product_id}";
            $clicks[$key] = ($clicks[$key] ?? 0) + (int) $r->n;
            $titles[$key] ??= $r->title;
        }

        $byType = [];
        foreach ($scores as $row) {
            $key = "{$row->content_type}:{$row->content_key}";
            $byType[$row->content_type][] = [
                'content_key' => $row->content_key,
                'title' => $titles[$key] ?? null,
                'score' => (float) $row->score,
                'rank' => (int) $row->rank,
                'computed_at' => $row->computed_at,
                'impressions' => $impressions[$key] ?? 0,
                'clicks' => $clicks[$key] ?? 0,
                // Only link_items carry freshness (per-URL connection rows).
                'freshness' => $row->content_type === 'link_item'
                    ? round($linkFreshness[$row->content_key] ?? 0.0, 2)
                    : 0.0,
            ];
        }

        return $byType;
    }

    /**
     * 30-day daily impressions + clicks per page (section_keys folded to page).
     *
     * @return array<string, array<string, array{impressions?: int, clicks?: int}>> day => page => counts
     */
    private function pageDailySeries(string $siteId, Carbon $since): array
    {
        $out = [];
        foreach (DB::connection('pgsql')->table('analytics.section_views')
            ->where('site_id', $siteId)->where('occurred_at', '>=', $since)->whereNotNull('section_key')
            ->selectRaw('occurred_at::date as day, section_key, COUNT(*) as n, COALESCE(SUM(duration_ms), 0) as dwell_ms')
            ->groupByRaw('occurred_at::date, section_key')->get() as $r) {
            $page = SitepageId::SECTION_KEY_TO_PAGE[$r->section_key] ?? null;
            if ($page !== null) {
                $out[(string) $r->day][$page]['impressions'] = ($out[(string) $r->day][$page]['impressions'] ?? 0) + (int) $r->n;
                $dwellSeconds = (int) round(((int) $r->dwell_ms) / 1000);
                if ($dwellSeconds > 0) {
                    $out[(string) $r->day][$page]['dwell_seconds'] = ($out[(string) $r->day][$page]['dwell_seconds'] ?? 0) + $dwellSeconds;
                }
            }
        }
        foreach (DB::connection('pgsql')->table('analytics.link_clicks')
            ->where('site_id', $siteId)->where('occurred_at', '>=', $since)->whereNotNull('section_key')
            ->selectRaw('occurred_at::date as day, section_key, COUNT(*) as n')
            ->groupByRaw('occurred_at::date, section_key')->get() as $r) {
            $page = SitepageId::SECTION_KEY_TO_PAGE[$r->section_key] ?? null;
            if ($page !== null) {
                $out[(string) $r->day][$page]['clicks'] = ($out[(string) $r->day][$page]['clicks'] ?? 0) + (int) $r->n;
            }
        }

        return $out;
    }

    /**
     * 30-day daily impressions + clicks per item, keyed "{item_type}:{item_id}".
     *
     * @return array<string, array<string, array{impressions?: int, clicks?: int}>> day => "type:id" => counts
     */
    private function itemDailySeries(string $siteId, Carbon $since): array
    {
        $out = [];
        foreach (DB::connection('pgsql')->table('analytics.item_views')
            ->where('site_id', $siteId)->where('occurred_at', '>=', $since)
            ->selectRaw('occurred_at::date as day, item_type, item_id, COUNT(*) as n')
            ->groupByRaw('occurred_at::date, item_type, item_id')->get() as $r) {
            $key = "{$r->item_type}:{$r->item_id}";
            $out[(string) $r->day][$key]['impressions'] = ($out[(string) $r->day][$key]['impressions'] ?? 0) + (int) $r->n;
        }
        foreach (DB::connection('pgsql')->table('analytics.link_clicks')
            ->where('site_id', $siteId)->where('occurred_at', '>=', $since)
            ->whereNotNull('product_id')->whereNotNull('section_key')
            ->selectRaw('occurred_at::date as day, product_id, section_key, COUNT(*) as n')
            ->groupByRaw('occurred_at::date, product_id, section_key')->get() as $r) {
            $itemType = self::CLICK_SECTION_TO_ITEM_TYPE[$r->section_key] ?? null;
            if ($itemType === null) {
                continue;
            }
            $key = "{$itemType}:{$r->product_id}";
            $out[(string) $r->day][$key]['clicks'] = ($out[(string) $r->day][$key]['clicks'] ?? 0) + (int) $r->n;
        }

        return $out;
    }
}
