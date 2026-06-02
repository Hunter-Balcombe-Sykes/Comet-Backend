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
     * Referrer/source CASE — maps utm_source + referrer URL into a fixed set
     * of human-readable labels. Order matters (Instagram check before Facebook
     * etc. would be wrong if domain patterns overlap).
     */
    private const SOURCE_CASE = "
        CASE
            WHEN COALESCE(utm_source,'') ILIKE 'instagram%' OR COALESCE(referrer,'') ILIKE '%instagram.com%' OR COALESCE(referrer,'') ILIKE '%l.instagram.com%' THEN 'Instagram'
            WHEN COALESCE(utm_source,'') ILIKE 'facebook%'  OR COALESCE(referrer,'') ILIKE '%facebook.com%'  OR COALESCE(referrer,'') ILIKE '%lm.facebook.com%'  OR COALESCE(referrer,'') ILIKE '%l.facebook.com%' THEN 'Facebook'
            WHEN COALESCE(utm_source,'') ILIKE 'tiktok%'    OR COALESCE(referrer,'') ILIKE '%tiktok.com%'    THEN 'TikTok'
            WHEN COALESCE(utm_source,'') ILIKE 'youtube%'   OR COALESCE(referrer,'') ILIKE '%youtube.com%'   OR COALESCE(referrer,'') ILIKE '%youtu.be%' THEN 'YouTube'
            WHEN COALESCE(utm_source,'') ILIKE 'twitter%'   OR COALESCE(utm_source,'') ILIKE 'x%'          OR COALESCE(referrer,'') ILIKE '%twitter.com%' OR COALESCE(referrer,'') ILIKE '%t.co%' OR COALESCE(referrer,'') ILIKE '%x.com%' THEN 'X (Twitter)'
            WHEN COALESCE(utm_source,'') ILIKE 'linkedin%'  OR COALESCE(referrer,'') ILIKE '%linkedin.com%' THEN 'LinkedIn'
            WHEN COALESCE(utm_source,'') ILIKE 'snapchat%'  OR COALESCE(referrer,'') ILIKE '%snapchat.com%' OR COALESCE(referrer,'') ILIKE '%sc.link%' THEN 'Snapchat'
            WHEN COALESCE(utm_source,'') ILIKE 'pinterest%' OR COALESCE(referrer,'') ILIKE '%pinterest.%'  THEN 'Pinterest'
            WHEN COALESCE(utm_source,'') ILIKE 'reddit%'    OR COALESCE(referrer,'') ILIKE '%reddit.com%'   THEN 'Reddit'
            WHEN COALESCE(utm_source,'') ILIKE 'google%'    OR COALESCE(referrer,'') ILIKE '%google.%'      THEN 'Organic (Google)'
            WHEN COALESCE(utm_source,'') ILIKE 'bing%'      OR COALESCE(referrer,'') ILIKE '%bing.com%'     THEN 'Organic (Bing)'
            WHEN COALESCE(utm_source,'') ILIKE 'duckduckgo%' OR COALESCE(referrer,'') ILIKE '%duckduckgo.com%' THEN 'Organic (DuckDuckGo)'
            WHEN COALESCE(utm_source,'') ILIKE 'yahoo%'     OR COALESCE(referrer,'') ILIKE '%search.yahoo.com%' THEN 'Organic (Yahoo)'
            WHEN referrer IS NULL OR referrer = '' THEN 'Direct Link'
            ELSE 'Other'
        END
    ";

    /** Stable label order for the referrer breakdown response. */
    private const REFERRER_LABELS = [
        'Organic (Google)', 'Organic (Bing)', 'Organic (DuckDuckGo)', 'Organic (Yahoo)',
        'Instagram', 'Facebook', 'TikTok', 'YouTube', 'X (Twitter)', 'LinkedIn',
        'Snapchat', 'Pinterest', 'Reddit', 'Direct Link', 'Other',
    ];

    public function visitsAggregate(string $userId, Carbon $from, Carbon $to): stdClass
    {
        return DB::table('analytics.site_visits')
            ->where('user_id', $userId)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('COUNT(*) as total_visits')
            ->selectRaw('COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as unique_visitors')
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
                ->selectRaw('COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as unique_clickers')
                ->selectRaw('MAX(occurred_at) as last_click_at')
                ->first() ?? (object) ['total_clicks' => 0, 'unique_clickers' => 0, 'last_click_at' => null];
        } catch (QueryException $e) {
            Log::warning('analytics.click_query_failed', ['method' => __METHOD__, 'user_id' => $userId, 'error' => $e->getMessage()]);

            return (object) ['total_clicks' => 0, 'unique_clickers' => 0, 'last_click_at' => null];
        }
    }

    public function visitsByBucket(string $userId, Carbon $from, Carbon $to, bool $hourly): Collection
    {
        [$bucketExpr, $bucketGroup] = $this->bucketExpressions($hourly);

        return DB::table('analytics.site_visits')
            ->where('user_id', $userId)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw("{$bucketExpr} as day, COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as count")
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
                ->selectRaw("{$bucketExpr} as day, COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as count")
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
            ->selectRaw(self::DEVICE_CASE.' as device, COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as visitors')
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
            ->selectRaw("DATE(occurred_at) as day, {$case} as device, COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as count")
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
            ->selectRaw("COALESCE(country_code, 'UN') as country_code, COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as visitors")
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
        $raw = DB::table('analytics.site_visits')
            ->where('user_id', $userId)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw(self::SOURCE_CASE.' as source, COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as visitors')
            ->groupByRaw(self::SOURCE_CASE)
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
            // Pull `platform` from settings JSON so the dashboard can label rows
            // by platform name (instagram, fresha, etc.) without joining a column.
            return DB::table('analytics.link_clicks as lc')
                ->join('site.blocks as b', 'b.id', '=', 'lc.link_block_id')
                ->where('lc.user_id', $userId)
                ->whereBetween('lc.occurred_at', [$from, $to])
                ->whereNull('b.deleted_at')
                ->whereRaw("LOWER(COALESCE(b.block_group, '')) = 'links'")
                ->whereRaw("LOWER(COALESCE(b.block_type, '')) = 'link'")
                ->selectRaw("b.id as block_id, b.title, b.url, b.settings->>'platform' as platform, b.settings->>'category' as category, COUNT(*) as clicks")
                ->groupByRaw("b.id, b.title, b.url, b.settings->>'platform', b.settings->>'category'")
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
            // Shared allowlist (TrackableBlockTypes) so a block_type accepted on
            // /public/analytics/clicks by the writer is also counted here — write-side
            // and read-side can't diverge on a config change.
            $trackableSectionTypes = TrackableBlockTypes::sectionTypes();

            return DB::table('analytics.link_clicks as lc')
                ->join('site.blocks as b', 'b.id', '=', 'lc.link_block_id')
                ->where('lc.user_id', $userId)
                ->whereBetween('lc.occurred_at', [$from, $to])
                ->whereNull('b.deleted_at')
                ->whereRaw("LOWER(COALESCE(b.block_group, '')) = 'sections'")
                ->whereIn(DB::raw("LOWER(COALESCE(b.block_type, ''))"), $trackableSectionTypes)
                ->selectRaw("LOWER(COALESCE(b.block_type, '')) as section_key, COUNT(*) as clicks")
                ->groupByRaw("LOWER(COALESCE(b.block_type, ''))")
                ->orderByDesc('clicks')
                ->get()
                ->map(function ($entry) {
                    $sectionKey = (string) $entry->section_key;

                    return [
                        'key' => $sectionKey,
                        'title' => $this->sectionTitle($sectionKey),
                        'clicks' => (int) ($entry->clicks ?? 0),
                    ];
                })
                ->values();
        } catch (QueryException $e) {
            Log::warning('analytics.click_query_failed', ['method' => __METHOD__, 'user_id' => $userId, 'error' => $e->getMessage()]);

            return collect();
        }
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
        return match ($sectionKey) {
            'gallery' => 'Gallery of Work',
            'services' => 'Services & Pricing',
            'booking' => 'Booking',
            'bio' => 'About',
            'documents' => 'File Preview',
            'newsletter' => 'Newsletter',
            'experience' => 'Experience',
            'credentials' => 'Credentials',
            'contact' => 'Contact',
            'contacts_collection' => 'Contacts',
            'barbershop_info' => 'Barbershop Info',
            'countdown' => 'Countdown',
            'sitepage_analytics' => 'Sitepage Analytics',
            default => ucfirst(str_replace('_', ' ', $sectionKey)),
        };
    }
}
