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
                CloudflareCachePurgeJob::dispatch($subdomain);
            }
        }
    }
}
