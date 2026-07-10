<?php

namespace App\Services\Analytics;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Read-side accessor for analytics.content_popularity_scores — the polymorphic
 * popularity ranks upserted by analytics:compute-popularity.
 *
 * Payload builders call forSite() once per build and annotate their content
 * arrays / pageOrder from the returned maps. One indexed read per build
 * (site_id, content_type, rank), behind the 60s public-profile cache.
 *
 * Fail-open: any read fault (missing table in a partial SQLite env, DB blip)
 * returns empty maps so a scoring outage degrades to "no ranks" (canonical
 * order + null popularityRank) rather than breaking the public payload.
 */
class ContentPopularityReader
{
    /**
     * All popularity ranks for a site, grouped by content_type then keyed by
     * content_key → rank. content_type ∈ page|shop_product|menu_item|
     * menu_category|service|block|gallery_item|engine_item. The derived
     * 'action' rows are excluded — they have their own reader
     * (rankedActionsForSite) and their own wire key (rankedActions), so the
     * popularity map stays a pure content-rank surface.
     *
     * @return array<string, array<string, int>>
     */
    public function forSite(?string $siteId): array
    {
        if ($siteId === null || $siteId === '') {
            return [];
        }

        try {
            $rows = DB::connection('pgsql')
                ->table('analytics.content_popularity_scores')
                ->where('site_id', $siteId)
                ->where('content_type', '!=', RankedActionsComputer::CONTENT_TYPE)
                ->orderBy('content_type')
                ->orderBy('rank')
                ->get(['content_type', 'content_key', 'rank']);
        } catch (QueryException $e) {
            Log::warning('analytics.popularity_read_failed', ['site_id' => $siteId, 'error' => $e->getMessage()]);

            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->content_type][(string) $row->content_key] = (int) $row->rank;
        }

        return $out;
    }

    /**
     * The unified ranked-action rows for a site (content_type='action', keyed
     * '<kind>:<ref>'), ordered by rank. Same fail-open posture as forSite():
     * any read fault returns [] so the payload degrades to its prior-ordered
     * cold path instead of breaking.
     *
     * @return list<array{key: string, score: float, rank: int}>
     */
    public function rankedActionsForSite(?string $siteId): array
    {
        if ($siteId === null || $siteId === '') {
            return [];
        }

        try {
            $rows = DB::connection('pgsql')
                ->table('analytics.content_popularity_scores')
                ->where('site_id', $siteId)
                ->where('content_type', RankedActionsComputer::CONTENT_TYPE)
                ->orderBy('rank')
                ->get(['content_key', 'score', 'rank']);
        } catch (QueryException $e) {
            Log::warning('analytics.ranked_actions_read_failed', ['site_id' => $siteId, 'error' => $e->getMessage()]);

            return [];
        }

        return $rows->map(static fn ($row): array => [
            'key' => (string) $row->content_key,
            'score' => (float) $row->score,
            'rank' => (int) $row->rank,
        ])->all();
    }
}
