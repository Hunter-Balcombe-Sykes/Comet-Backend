<?php

namespace App\Services\Analytics;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;

/**
 * Read-side queries for the professional analytics summary dashboard.
 *
 * Every method is a pure DB read keyed by (user_id, from, to).
 * Queries that touch click-side tables (analytics.link_clicks) catch
 * QueryException and return an empty/default so the page-view analytics
 * still render if the click ingestion pipeline is broken or missing
 * (e.g. SQLite test envs that don't have all migrations applied).
 */
class AnalyticsQueryService
{
    /**
     * Device-bucket CASE — collapses raw device_type to {desktop, mobile, other}.
     * Inlined into raw selectRaw/groupByRaw on visits-table queries so the
     * column always groups identically across queries.
     */
    private const DEVICE_CASE = "
        CASE
            WHEN device_type = 'desktop' THEN 'desktop'
            WHEN device_type IN ('mobile','tablet') THEN 'mobile'
            ELSE 'other'
        END
    ";

    /**
     * Referrer/source map — SINGLE source of truth for the SQL CASE built by
     * sourceCase() below. label => match-SQL predicate (raw SQL fragment used
     * inside a WHEN ... THEN). Order is evaluation order: Instagram before
     * Facebook etc. would be wrong if domain patterns overlap, so this must
     * stay ordered exactly as it was in the original hand-written CASE.
     * 'Direct Link' (empty/null referrer) and 'Other' (ELSE) are structural —
     * not data-driven — and are appended by sourceCase() itself.
     */
    private const REFERRER_SOURCES = [
        'Instagram' => "COALESCE(utm_source,'') ILIKE 'instagram%' OR COALESCE(referrer,'') ILIKE '%instagram.com%' OR COALESCE(referrer,'') ILIKE '%l.instagram.com%'",
        'Facebook' => "COALESCE(utm_source,'') ILIKE 'facebook%' OR COALESCE(referrer,'') ILIKE '%facebook.com%' OR COALESCE(referrer,'') ILIKE '%lm.facebook.com%' OR COALESCE(referrer,'') ILIKE '%l.facebook.com%'",
        'TikTok' => "COALESCE(utm_source,'') ILIKE 'tiktok%' OR COALESCE(referrer,'') ILIKE '%tiktok.com%'",
        'YouTube' => "COALESCE(utm_source,'') ILIKE 'youtube%' OR COALESCE(referrer,'') ILIKE '%youtube.com%' OR COALESCE(referrer,'') ILIKE '%youtu.be%'",
        'X (Twitter)' => "COALESCE(utm_source,'') ILIKE 'twitter%' OR COALESCE(utm_source,'') ILIKE 'x%' OR COALESCE(referrer,'') ILIKE '%twitter.com%' OR COALESCE(referrer,'') ILIKE '%t.co%' OR COALESCE(referrer,'') ILIKE '%x.com%'",
        'LinkedIn' => "COALESCE(utm_source,'') ILIKE 'linkedin%' OR COALESCE(referrer,'') ILIKE '%linkedin.com%'",
        'Snapchat' => "COALESCE(utm_source,'') ILIKE 'snapchat%' OR COALESCE(referrer,'') ILIKE '%snapchat.com%' OR COALESCE(referrer,'') ILIKE '%sc.link%'",
        'Pinterest' => "COALESCE(utm_source,'') ILIKE 'pinterest%' OR COALESCE(referrer,'') ILIKE '%pinterest.%'",
        'Reddit' => "COALESCE(utm_source,'') ILIKE 'reddit%' OR COALESCE(referrer,'') ILIKE '%reddit.com%'",
        'Organic (Google)' => "COALESCE(utm_source,'') ILIKE 'google%' OR COALESCE(referrer,'') ILIKE '%google.%'",
        'Organic (Bing)' => "COALESCE(utm_source,'') ILIKE 'bing%' OR COALESCE(referrer,'') ILIKE '%bing.com%'",
        'Organic (DuckDuckGo)' => "COALESCE(utm_source,'') ILIKE 'duckduckgo%' OR COALESCE(referrer,'') ILIKE '%duckduckgo.com%'",
        'Organic (Yahoo)' => "COALESCE(utm_source,'') ILIKE 'yahoo%' OR COALESCE(referrer,'') ILIKE '%search.yahoo.com%'",
    ];

    /**
     * Stable label order for the referrer breakdown response — this is display
     * order (organics first, then socials, then structural), which differs
     * from REFERRER_SOURCES' evaluation order above, so it stays a separate
     * list. Must remain a permutation of array_keys(REFERRER_SOURCES) plus the
     * two structural labels ('Direct Link', 'Other') — see #FOUND-3 guard test.
     */
    private const REFERRER_LABELS = [
        'Organic (Google)', 'Organic (Bing)', 'Organic (DuckDuckGo)', 'Organic (Yahoo)',
        'Instagram', 'Facebook', 'TikTok', 'YouTube', 'X (Twitter)', 'LinkedIn',
        'Snapchat', 'Pinterest', 'Reddit', 'Direct Link', 'Other',
    ];

    /**
     * Rebuilds the referrer/source SQL CASE from REFERRER_SOURCES plus the two
     * structural clauses (Direct Link, Other) — single source of truth so the
     * CASE and REFERRER_LABELS can't silently diverge (see #FOUND-3).
     */
    private static function sourceCase(): string
    {
        $whens = [];
        foreach (self::REFERRER_SOURCES as $label => $predicate) {
            $whens[] = "WHEN {$predicate} THEN '{$label}'";
        }
        $whens[] = "WHEN referrer IS NULL OR referrer = '' THEN 'Direct Link'";
        $whens[] = "ELSE 'Other'";

        return "CASE\n".implode("\n", $whens)."\nEND";
    }

    public function visitsAggregate(string $userId, Carbon $from, Carbon $to): stdClass
    {
        return DB::table('analytics.site_visits')
            ->where('user_id', $userId)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('COUNT(*) as total_visits')
            ->selectRaw("COUNT(DISTINCT {$this->uniqueVisitorExpr()}) as unique_visitors")
            ->selectRaw('MAX(occurred_at) as last_visit_at')
            ->first() ?? (object) ['total_visits' => 0, 'unique_visitors' => 0, 'last_visit_at' => null];
    }

    public function clicksAggregate(string $userId, Carbon $from, Carbon $to): stdClass
    {
        try {
            return DB::table('analytics.link_clicks')
                ->where('user_id', $userId)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw('COUNT(*) as total_clicks')
                ->selectRaw("COUNT(DISTINCT {$this->uniqueVisitorExpr()}) as unique_clickers")
                ->selectRaw('MAX(occurred_at) as last_click_at')
                ->first() ?? (object) ['total_clicks' => 0, 'unique_clickers' => 0, 'last_click_at' => null];
        } catch (QueryException $e) {
            Log::warning('analytics.click_query_failed', ['method' => __METHOD__, 'user_id' => $userId, 'error' => $e->getMessage()]);

            return (object) ['total_clicks' => 0, 'unique_clickers' => 0, 'last_click_at' => null];
        }
    }

    /**
     * Daily/hourly VOLUME series for the "Views" chart — COUNT(*) (page views,
     * not unique visitors) so the series sums to totals.visits. Headline + chart
     * must reconcile: the dashboard plots this under the "Views" total, which is
     * also COUNT(*). Unique-visitor reach lives in the *Aggregate methods +
     * demographics (countries/regions/referrers stay COUNT(DISTINCT)).
     */
    public function visitsByBucket(string $userId, Carbon $from, Carbon $to, bool $hourly): Collection
    {
        [$bucketExpr, $bucketGroup] = $this->bucketExpressions($hourly);

        return DB::table('analytics.site_visits')
            ->where('user_id', $userId)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw("{$bucketExpr} as day, COUNT(*) as count")
            ->groupByRaw($bucketGroup)
            ->orderBy('day')
            ->get();
    }

    public function clicksByBucket(string $userId, Carbon $from, Carbon $to, bool $hourly): Collection
    {
        [$bucketExpr, $bucketGroup] = $this->bucketExpressions($hourly);

        try {
            return DB::table('analytics.link_clicks')
                ->where('user_id', $userId)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw("{$bucketExpr} as day, COUNT(*) as count")
                ->groupByRaw($bucketGroup)
                ->orderBy('day')
                ->get();
        } catch (QueryException $e) {
            Log::warning('analytics.click_query_failed', ['method' => __METHOD__, 'user_id' => $userId, 'error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * @return array{desktop:int, mobile:int, other:int}
     */
    public function deviceTotals(string $userId, Carbon $from, Carbon $to): array
    {
        $raw = DB::table('analytics.site_visits')
            ->where('user_id', $userId)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw(self::DEVICE_CASE.' as device, COUNT(*) as visitors')
            ->groupByRaw(self::DEVICE_CASE)
            ->get()
            ->keyBy('device');

        return [
            'desktop' => (int) ($raw->get('desktop')?->visitors ?? 0),
            'mobile' => (int) ($raw->get('mobile')?->visitors ?? 0),
            'other' => (int) ($raw->get('other')?->visitors ?? 0),
        ];
    }

    public function visitsByDayByDevice(string $userId, Carbon $from, Carbon $to): Collection
    {
        $case = self::DEVICE_CASE;

        return DB::table('analytics.site_visits')
            ->where('user_id', $userId)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw("DATE(occurred_at) as day, {$case} as device, COUNT(*) as count")
            ->groupByRaw("DATE(occurred_at), {$case}")
            ->orderBy('day')
            ->get();
    }

    /**
     * Top 4 country codes by unique visitors plus an 'OTHER' bucket summing the rest.
     *
     * @return array<int, array{country_code:string, visitors:int}>
     */
    public function countries(string $userId, Carbon $from, Carbon $to): array
    {
        $raw = DB::table('analytics.site_visits')
            ->where('user_id', $userId)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw("COALESCE(country_code, 'UN') as country_code, COUNT(DISTINCT {$this->uniqueVisitorExpr()}) as visitors")
            ->groupByRaw("COALESCE(country_code, 'UN')")
            ->orderByDesc('visitors')
            ->get();

        $top = $raw->take(4)->map(fn ($r) => [
            'country_code' => $r->country_code,
            'visitors' => (int) $r->visitors,
        ])->all();

        $otherCount = (int) $raw->slice(4)->sum('visitors');
        if ($otherCount > 0) {
            $top[] = ['country_code' => 'OTHER', 'visitors' => $otherCount];
        }

        return $top;
    }

    /**
     * Fixed-order referrer breakdown — zero-fills labels with no traffic.
     *
     * @return array<int, array{label:string, visitors:int}>
     */
    public function referrers(string $userId, Carbon $from, Carbon $to): array
    {
        $case = self::sourceCase();

        $raw = DB::table('analytics.site_visits')
            ->where('user_id', $userId)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw("{$case} as source, COUNT(DISTINCT {$this->uniqueVisitorExpr()}) as visitors")
            ->groupByRaw($case)
            ->orderByDesc('visitors')
            ->get()
            ->keyBy('source');

        return array_map(
            fn (string $label) => ['label' => $label, 'visitors' => (int) ($raw->get($label)?->visitors ?? 0)],
            self::REFERRER_LABELS
        );
    }

    public function topLinks(string $userId, Carbon $from, Carbon $to): Collection
    {
        try {
            // v2: clicks self-describe (url/platform/label captured at the anchor).
            // Legacy block-era rows have url IS NULL and are excluded — the block
            // model is gone with the architecture pivot.
            return DB::table('analytics.link_clicks')
                ->where('user_id', $userId)
                ->whereBetween('occurred_at', [$from, $to])
                ->whereNotNull('url')
                ->selectRaw('url, MAX(platform) as platform, MAX(label) as label, MAX(section_key) as section_key, COUNT(*) as clicks')
                ->groupBy('url')
                ->orderByDesc('clicks')
                ->limit(10)
                ->get();
        } catch (QueryException $e) {
            Log::warning('analytics.click_query_failed', ['method' => __METHOD__, 'user_id' => $userId, 'error' => $e->getMessage()]);

            return collect();
        }
    }

    public function topSections(string $userId, Carbon $from, Carbon $to): Collection
    {
        try {
            // v2: section opens recorded by the architecture tracker into
            // analytics.section_views (per-session dedup happens at ingest). The
            // legacy derivation from block clicks is gone with the block model.
            return DB::table('analytics.section_views')
                ->where('user_id', $userId)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw("section_key, COUNT(*) as views, COUNT(DISTINCT {$this->uniqueVisitorExpr()}) as unique_viewers")
                ->groupBy('section_key')
                ->orderByDesc('views')
                ->limit(12)
                ->get()
                ->map(function ($entry) {
                    $sectionKey = (string) $entry->section_key;

                    return [
                        'key' => $sectionKey,
                        'title' => $this->sectionTitle($sectionKey),
                        'views' => (int) ($entry->views ?? 0),
                        'unique_viewers' => (int) ($entry->unique_viewers ?? 0),
                    ];
                })
                ->values();
        } catch (QueryException $e) {
            Log::warning('analytics.click_query_failed', ['method' => __METHOD__, 'user_id' => $userId, 'error' => $e->getMessage()]);

            return collect();
        }
    }

    /**
     * Clicks grouped by destination platform slug (instagram, fresha, shopify, ...).
     * v2 rows only — legacy block clicks carry no platform.
     *
     * @return array<int, array{platform:string, clicks:int, unique_clickers:int}>
     */
    public function platformClicks(string $userId, Carbon $from, Carbon $to): array
    {
        try {
            return DB::table('analytics.link_clicks')
                ->where('user_id', $userId)
                ->whereBetween('occurred_at', [$from, $to])
                ->whereNotNull('platform')
                ->selectRaw("platform, COUNT(*) as clicks, COUNT(DISTINCT {$this->uniqueVisitorExpr()}) as unique_clickers")
                ->groupBy('platform')
                ->orderByDesc('clicks')
                ->get()
                ->map(fn ($r) => [
                    'platform' => (string) $r->platform,
                    'clicks' => (int) $r->clicks,
                    'unique_clickers' => (int) $r->unique_clickers,
                ])
                ->all();
        } catch (QueryException $e) {
            Log::warning('analytics.click_query_failed', ['method' => __METHOD__, 'user_id' => $userId, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * Outbound clicks per product on the sitepage shop section(s).
     * Scoped to shop-family section keys so services/events product_id rows
     * (see topServices()/topEvents()) never pollute this list — see
     * topItemsBySection() for the shared grouping logic. Limit stays 10 (this
     * method's pre-existing contract); topServices()/topEvents() are new and
     * use the smaller default.
     *
     * @return array<int, array{product_id:string, title:?string, url:?string, clicks:int, unique_clickers:int}>
     */
    public function topProducts(string $userId, Carbon $from, Carbon $to): array
    {
        return $this->topItemsBySection($userId, $from, $to, ['shop-products', 'shop-tracks', 'shop'], 10);
    }

    /**
     * Outbound clicks per booking service/event item on the sitepage booking
     * section. Section key differs by architecture: deck/dock/flick use 'book',
     * bento uses 'services'.
     *
     * @return array<int, array{product_id:string, title:?string, url:?string, clicks:int, unique_clickers:int}>
     */
    public function topServices(string $userId, Carbon $from, Carbon $to): array
    {
        return $this->topItemsBySection($userId, $from, $to, ['book', 'services']);
    }

    /**
     * Outbound clicks per event item on the sitepage events section.
     *
     * @return array<int, array{product_id:string, title:?string, url:?string, clicks:int, unique_clickers:int}>
     */
    public function topEvents(string $userId, Carbon $from, Carbon $to): array
    {
        return $this->topItemsBySection($userId, $from, $to, ['events', 'attend']);
    }

    /**
     * Generic per-item click rollup, scoped to a set of section keys — shared by
     * topProducts()/topServices()/topEvents() now that shop, booking, and event
     * sitepage sections all carry product_id/product_title on the same
     * analytics.link_clicks table. Scoping by section_key IN (...) is what keeps
     * the three lists disjoint; NULL section_key rows are never included (old
     * shop-only rows already carry a shop section_key from the same tracker, so
     * this scoping is safe against pre-change data — see caller docblocks).
     *
     * @param  array<int, string>  $sectionKeys
     * @return array<int, array{product_id:string, title:?string, url:?string, clicks:int, unique_clickers:int}>
     */
    private function topItemsBySection(string $userId, Carbon $from, Carbon $to, array $sectionKeys, int $limit = 8): array
    {
        try {
            return DB::table('analytics.link_clicks')
                ->where('user_id', $userId)
                ->whereBetween('occurred_at', [$from, $to])
                ->whereNotNull('product_id')
                ->whereIn('section_key', $sectionKeys)
                ->selectRaw("product_id, MAX(product_title) as title, MAX(url) as url, COUNT(*) as clicks, COUNT(DISTINCT {$this->uniqueVisitorExpr()}) as unique_clickers")
                ->groupBy('product_id')
                ->orderByDesc('clicks')
                ->limit($limit)
                ->get()
                ->map(fn ($r) => [
                    'product_id' => (string) $r->product_id,
                    'title' => $r->title !== null ? (string) $r->title : null,
                    'url' => $r->url !== null ? (string) $r->url : null,
                    'clicks' => (int) $r->clicks,
                    'unique_clickers' => (int) $r->unique_clickers,
                ])
                ->all();
        } catch (QueryException $e) {
            Log::warning('analytics.click_query_failed', ['method' => __METHOD__, 'user_id' => $userId, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * COALESCE(visitor_id::text, ip_hash) — the visitor_id column is UUID on
     * Postgres, so it needs an explicit ::text cast to COALESCE against the
     * TEXT ip_hash fallback. SQLite has no UUID type (visitor_id is already
     * TEXT there) and doesn't understand the `::text` cast syntax, so this
     * conditionally drops the cast in test envs — same driver-branch pattern
     * as bucketExpressions() below. Scoped to this new method only; the eight
     * other call sites in this file with the same raw ::text fragment predate
     * this change and are a separate cleanup.
     */
    private function uniqueVisitorExpr(): string
    {
        $driver = DB::connection('pgsql')->getDriverName();

        return $driver === 'sqlite' ? 'COALESCE(visitor_id, ip_hash)' : 'COALESCE(visitor_id::text, ip_hash)';
    }

    /**
     * Region breakdown within one country by unique visitors — defaults to AU so
     * the dashboard can chart states (ISO-3166-2 suffixes: NSW, VIC, ...). 'UN'
     * collects rows where the edge couldn't resolve a region.
     *
     * @return array<int, array{region_code:string, visitors:int}>
     */
    public function regions(string $userId, Carbon $from, Carbon $to, string $countryCode = 'AU'): array
    {
        return DB::table('analytics.site_visits')
            ->where('user_id', $userId)
            ->where('country_code', $countryCode)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw("COALESCE(region_code, 'UN') as region_code, COUNT(DISTINCT {$this->uniqueVisitorExpr()}) as visitors")
            ->groupByRaw("COALESCE(region_code, 'UN')")
            ->orderByDesc('visitors')
            ->get()
            ->map(fn ($r) => [
                'region_code' => (string) $r->region_code,
                'visitors' => (int) $r->visitors,
            ])
            ->all();
    }

    /**
     * Top cities by unique visitors — demographics (unique reach), so
     * COUNT(DISTINCT) like the other geo breakdowns. Rows with no resolved city
     * are dropped (not bucketed) so the list stays meaningful; the edge fills
     * city on a best-effort basis.
     *
     * Grouped by (city, country_code) so same-named cities in different
     * countries don't collapse into one row. Coordinates are the average of
     * the per-visit edge-resolved lat/lon (AVG smooths PoP-level jitter for
     * the same metro); rows older than the lat/lon rollout return null and
     * simply don't get a map pin.
     *
     * @return array<int, array{city:string, country_code:?string, visitors:int, latitude:?float, longitude:?float}>
     */
    public function cities(string $userId, Carbon $from, Carbon $to, int $limit = 6): array
    {
        return DB::table('analytics.site_visits')
            ->where('user_id', $userId)
            ->whereNotNull('city')
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw("city, country_code, COUNT(DISTINCT {$this->uniqueVisitorExpr()}) as visitors, AVG(latitude) as latitude, AVG(longitude) as longitude")
            ->groupBy('city', 'country_code')
            ->orderByDesc('visitors')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'city' => (string) $r->city,
                'country_code' => $r->country_code !== null ? (string) $r->country_code : null,
                'visitors' => (int) $r->visitors,
                'latitude' => $r->latitude !== null ? round((float) $r->latitude, 4) : null,
                'longitude' => $r->longitude !== null ? round((float) $r->longitude, 4) : null,
            ])
            ->all();
    }

    /**
     * Session totals + average duration over the window. "Engaged" = at least two
     * heartbeats (≥10s visible); the average ignores drive-by bounces so it reads
     * as "time people actually spent".
     */
    public function sessionsAggregate(string $userId, Carbon $from, Carbon $to): stdClass
    {
        $empty = (object) ['total_sessions' => 0, 'engaged_sessions' => 0, 'avg_duration_seconds' => 0];

        try {
            return DB::table('analytics.site_sessions')
                ->where('user_id', $userId)
                ->whereBetween('started_at', [$from, $to])
                ->selectRaw('COUNT(*) as total_sessions')
                ->selectRaw('COUNT(*) FILTER (WHERE duration_seconds >= 10) as engaged_sessions')
                ->selectRaw('COALESCE(ROUND(AVG(duration_seconds) FILTER (WHERE duration_seconds >= 10)), 0) as avg_duration_seconds')
                ->first() ?? $empty;
        } catch (QueryException $e) {
            Log::warning('analytics.session_query_failed', ['method' => __METHOD__, 'user_id' => $userId, 'error' => $e->getMessage()]);

            return $empty;
        }
    }

    /**
     * Sessions seen in the last 75s (three missed 25s heartbeats = gone).
     * Uncached — feeds the dashboard's live-now poll.
     */
    public function liveVisitors(string $userId): int
    {
        try {
            return (int) DB::table('analytics.site_sessions')
                ->where('user_id', $userId)
                ->where('last_seen_at', '>=', now()->subSeconds(75))
                ->count();
        } catch (QueryException $e) {
            Log::warning('analytics.session_query_failed', ['method' => __METHOD__, 'user_id' => $userId, 'error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Sitepage conversions in the window: enquiry-form submissions + newsletter
     * signups. Both are first-party tables, not analytics events, so they stay
     * accurate even with beacons blocked.
     *
     * @return array{enquiries:int, subscribers:int}
     */
    public function conversions(string $userId, Carbon $from, Carbon $to): array
    {
        try {
            $enquiries = (int) DB::table('site.enquiries')
                ->where('user_id', $userId)
                ->whereBetween('created_at', [$from, $to])
                ->count();
        } catch (QueryException) {
            $enquiries = 0;
        }

        try {
            $subscribers = (int) DB::table('notifications.email_subscriptions')
                ->where('user_id', $userId)
                ->where('status', 'subscribed')
                ->whereBetween('created_at', [$from, $to])
                ->count();
        } catch (QueryException) {
            $subscribers = 0;
        }

        return ['enquiries' => $enquiries, 'subscribers' => $subscribers];
    }

    /**
     * @return array{0:string,1:string} [select-expression, group-expression]
     */
    private function bucketExpressions(bool $hourly): array
    {
        if (! $hourly) {
            return ['DATE(occurred_at)', 'DATE(occurred_at)'];
        }

        // SQLite test envs need strftime; pgsql uses DATE_TRUNC.
        $driver = DB::connection('pgsql')->getDriverName();
        $expr = $driver === 'sqlite'
            ? "strftime('%Y-%m-%d %H:00:00', occurred_at)"
            : "DATE_TRUNC('hour', occurred_at)";

        return [$expr, $expr];
    }

    private function sectionTitle(string $sectionKey): string
    {
        // Single source in config/partna.php analytics.section_titles — a new section
        // type is a config edit, not a code change. Unknown keys humanize the raw key.
        $titles = config('partna.analytics.section_titles', []);

        return $titles[$sectionKey] ?? ucfirst(str_replace('_', ' ', $sectionKey));
    }
}
