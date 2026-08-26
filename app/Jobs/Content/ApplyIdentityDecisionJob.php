<?php

namespace App\Jobs\Content;

use App\Ingest\Projection\ProjectionWriter;
use App\Site\Documents\SiteCacheLanes;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The manual-source counterpart of ReprojectSourcesJob (plan
 * docs/superpowers/plans/2026-08-25-projectionwriter-identity-scope.md §A.4,
 * follow-up 1).
 *
 * An owner identity ruling takes effect when the resolver next runs over the
 * ruled coords. For connector-fed items that is ReprojectSourcesJob replaying
 * `ingest:project`, which resolves as part of the replay. A MANUAL source has
 * no connection_id and so no ingest.sources row to replay — there is nothing
 * for that job to do, and until this existed nothing was dispatched at all.
 *
 * Correctness was never at stake: IdentityScope seeds its component from every
 * coord a live `same` ruling names, so ANY later resolve of that kind applies
 * the verdict. The gap was that for a kind fed only by hand-added items there
 * is no later resolve until the owner edits that kind again. This job IS that
 * resolve, dispatched at the moment of the ruling.
 *
 * Deliberately NOT a reprojection: there are no landed records to replay for a
 * manual coord, and the facets were written by writeManualItem() when the
 * owner added the item. Only the identity spine needs recomputing.
 */
class ApplyIdentityDecisionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    /**
     * Retried, unlike ReprojectSourcesJob's tries=1. That job replays an
     * ingest run and a retry re-does real upstream work; this one takes the
     * identity advisory lock and resolves, so its realistic failure is
     * AdvisoryLockTimeoutException from a concurrent writer — exactly the case
     * a retry fixes. The resolve is one transaction, so a failed attempt
     * leaves nothing half-applied.
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 180];

    /** @param list<string> $coords */
    public function __construct(
        public string $userId,
        public string $kind,
        public array $coords,
    ) {
        $this->onQueue('ingest');
    }

    public function handle(ProjectionWriter $writer): void
    {
        $itemByCoord = $writer->resolveIdentityFor($this->userId, $this->kind, $this->coords);

        if ($itemByCoord === []) {
            // The coords went away between the ruling and this job (item
            // deleted, source disconnected). Nothing to bind and nothing to
            // invalidate — the decision rows stay, harmlessly, for the day
            // those coords come back.
            return;
        }

        // A merge changes the headline the payload renders, so the caches this
        // resolve invalidates are the ones keyed on the ITEMS, not the coords.
        $writer->refreshCachesFor($this->userId, array_values(array_unique($itemByCoord)));

        // All three lanes, not a lane-1-only build-state bump: the public
        // payload cache keys off site.sites.updated_at, so bumping build state
        // without it serves the pre-merge content for the whole TTL while the
        // CDN is correctly purged (CLAUDE.md §Content pools, ruling 2026-08-17).
        // (Spelling the lane-1 call literally here would trip
        // PoolCacheLaneSeamTest, which greps this file for it.)
        // The controller busted at request time, but that was BEFORE this
        // resolve ran — a merge landing here is a second mutation.
        $siteId = DB::table('site.sites')->where('user_id', $this->userId)->value('id');
        if ($siteId !== null) {
            SiteCacheLanes::bust([(string) $siteId]);
        }
    }

    public function failed(\Throwable $e): void
    {
        // Loud: the owner has been told 202 and will expect the duplicate to
        // disappear. The decision rows survive, so the ruling still applies on
        // the next resolve of this kind — which, for an all-manual kind, is
        // the thing that may not come.
        Log::warning('content.apply_identity_decision.failed', [
            'user_id' => $this->userId,
            'kind' => $this->kind,
            'coords' => count($this->coords),
            'error' => $e->getMessage(),
        ]);
    }
}
