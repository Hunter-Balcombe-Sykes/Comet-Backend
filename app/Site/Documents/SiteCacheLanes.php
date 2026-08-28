<?php

namespace App\Site\Documents;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use Illuminate\Support\Facades\DB;

/**
 * The three cache lanes a raw write must fire, in one place (parent spec §4).
 *
 * 1. `BuildState::bump` — the document build state the sitepage renderer reads.
 * 2. `site.sites.updated_at` — the ORIGIN's own payload cache key
 *    (`IndividualProfilePayloadBuilder::cacheKey()` derives from this column, TTL
 *    60s). Skipping it means the origin keeps serving its previous payload from
 *    its own cache even after the edge has been purged.
 * 3. The Cloudflare edge purge — pools serve LIVE (Option B), but the CDN in
 *    front still holds the rendered page, so without it "the site follows
 *    instantly" is true of the payload and false of what a visitor sees.
 *
 * Extracted because "fired two of the three" is this codebase's recurring
 * defect, not a hypothetical one: `6ab3028e8` fixed exactly that in
 * `PoolController::poolChanged()` — build state and edge purge fired, lane 2 did
 * not, and an owner's drag looked like it had not taken for up to a minute.
 * Three hand-maintained copies of a contract whose failure mode is silent
 * staleness is three chances to forget the same lane again.
 *
 * Deliberately NOT on `ManualServiceWriter`: `ProjectionWriter` needs these
 * lanes too and `ManualServiceWriter` already depends on `ProjectionWriter`, so
 * homing them there would invert that edge. `ManualServiceWriter::invalidate()`
 * stays as the named seam its callers (and
 * `tests/Feature/Architecture/CollectionWriteInvalidationGuardTest`) reference —
 * it now delegates here rather than re-rolling the lanes.
 */
final class SiteCacheLanes
{
    /**
     * Seconds between the first write of a burst and the edge purge it
     * triggers (I1, 2026-08-28). Mirrors BuildState::EVENT_BUILD_DELAY_SECONDS
     * deliberately: both lanes are debounced by the same window, so one owner
     * burst produces one build AND one purge per site rather than one build
     * and N purges.
     *
     * THE COALESCING IS THE JOB'S OWN UNIQUE LOCK, NOT THE DELAY.
     * CloudflareCachePurgeJob is ShouldBeUnique with $uniqueFor = 35, and that
     * lock is acquired at dispatch (PendingDispatch::__destruct) and released
     * when the job STARTS PROCESSING. Undelayed, a purge starts ~1 s later and
     * frees the lock, so every subsequent write in the burst dispatches its own
     * purge. Delaying the job holds the lock for the whole window instead.
     *
     * MUST therefore stay STRICTLY BELOW that $uniqueFor. At or above it the
     * lock expires before the job runs and per-write volume returns silently,
     * with every existing test still green — pinned by
     * tests/Feature/Content/PoolCacheLanesTest.php.
     *
     * Safe against stale content because it delays lane 3 ONLY. Lane 2 below
     * moves site.sites.updated_at at T+0, and the origin payload cache KEY is
     * derived from that column — the key changes immediately, so the 60 s TTL
     * entry is orphaned rather than served. The delay therefore cannot extend
     * payload staleness at all; it extends only the edge's rendered HTML, by
     * at most this many seconds, which is inside the window lane 2 already
     * tolerates. The re-pin hazard (a visitor racing the purge and re-pinning
     * a stale render under the router's 24h TTL) is still covered by
     * config('partna.cache.purge_followup_schedule'), which now lands at
     * T+30 rather than T+15.
     *
     * Compliance purges are NOT affected: nothing moderation-, takedown- or
     * deletion-related routes through bust() — PurgeModerationCacheJob and
     * ReconcilePlatformTakedownJob dispatch CloudflareCachePurgeJob directly
     * and stay immediate.
     */
    public const EDGE_PURGE_DELAY_SECONDS = 15;

    /** @param  list<string>  $siteIds */
    public static function bust(array $siteIds): void
    {
        foreach (array_unique($siteIds) as $siteId) {
            BuildState::bump($siteId);

            DB::connection('pgsql')->table('site.sites')
                ->where('id', $siteId)->update(['updated_at' => now()]);

            $subdomain = (string) (DB::connection('pgsql')->table('site.sites')
                ->where('id', $siteId)->value('subdomain') ?? '');

            if ($subdomain !== '') {
                // Delayed, not immediate — see EDGE_PURGE_DELAY_SECONDS. Lane 3
                // still fires on every bust(); only its TIMING moved.
                CloudflareCachePurgeJob::dispatch($subdomain)
                    ->delay(self::EDGE_PURGE_DELAY_SECONDS);
            }
        }
    }
}
