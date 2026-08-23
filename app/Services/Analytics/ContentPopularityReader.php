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
     * All ITEM popularity ranks for a site, grouped by content_type then keyed
     * by content_key → rank. content_type ∈ shop_product|menu_item|
     * menu_category|service|block|gallery_item|engine_item|listen_item|
     * watch_item|link_item. The 'action' rows are excluded — they have their
     * own readers (actionScoresForSite / pageRanksFromActions) and their own
     * wire key (actions), so this map stays a pure content-rank surface.
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
                ->where('content_type', '!=', ActionScorer::CONTENT_TYPE)
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
     * Stored smart scores for the unified action list — action id => blended
     * score. Feeds ActionSlots in smart mode. Fail-open: [] on a read error
     * (the site still renders in newest order).
     *
     * @return array<string, float>
     */
    public function actionScoresForSite(?string $siteId): array
    {
        if ($siteId === null || $siteId === '') {
            return [];
        }
        try {
            $rows = DB::connection('pgsql')
                ->table('analytics.content_popularity_scores')
                ->where('site_id', $siteId)
                ->where('content_type', ActionScorer::CONTENT_TYPE)
                ->get(['content_key', 'score']);
        } catch (QueryException $e) {
            Log::warning('analytics.action_scores_read_failed', ['site_id' => $siteId, 'error' => $e->getMessage()]);

            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->content_key] = (float) $row->score;
        }

        return $out;
    }

    /**
     * Page order from the action layer (spec §6): the `page:*` action rows in
     * rank order → page id => 1-based rank. Replaces the retired page-score
     * family as SitepageDataResolverService::buildPageOrder's input.
     *
     * @return array<string, int>
     */
    public function pageRanksFromActions(?string $siteId): array
    {
        $scores = $this->actionScoresForSite($siteId);
        $pages = [];
        foreach ($scores as $id => $score) {
            if (str_starts_with($id, 'page:')) {
                $pages[substr($id, 5)] = $score;
            }
        }
        arsort($pages);
        $out = [];
        $rank = 1;
        foreach (array_keys($pages) as $pageId) {
            $out[$pageId] = $rank++;
        }

        return $out;
    }

    /**
     * Flat item-family scores — content_key => blended score across every
     * item type (everything except 'action'). Keys are the item id for most
     * families, the catalog handle for shop_product and the url for link_item;
     * consumers look up id → handle → url. Collisions keep the max. Fail-open.
     *
     * @return array<string, float>
     */
    public function itemScoresForSite(?string $siteId): array
    {
        if ($siteId === null || $siteId === '') {
            return [];
        }
        try {
            $rows = DB::connection('pgsql')
                ->table('analytics.content_popularity_scores')
                ->where('site_id', $siteId)
                ->where('content_type', '!=', ActionScorer::CONTENT_TYPE)
                ->get(['content_key', 'score']);
        } catch (QueryException $e) {
            Log::warning('analytics.item_scores_read_failed', ['site_id' => $siteId, 'error' => $e->getMessage()]);

            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $key = (string) $row->content_key;
            $out[$key] = max($out[$key] ?? 0.0, (float) $row->score);
        }

        return $out;
    }
}
