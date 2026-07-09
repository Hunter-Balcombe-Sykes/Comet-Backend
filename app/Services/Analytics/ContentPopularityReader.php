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
     * menu_category|service|block|gallery_item|engine_item.
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
}
