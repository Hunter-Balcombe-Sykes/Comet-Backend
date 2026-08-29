<?php

namespace App\Jobs\Platforms;

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Segments\UserSegment;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\Site;
use App\Services\Cache\SiteCacheService;
use App\Services\Segments\SegmentResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OV-A staff kill-switch takedown. Flips is_active=false on connections of a
 * disabled platform — globally (segmentId null) or for one segment's members —
 * so existing content stops rendering (public payload filters is_active=true).
 * No data deleted: only the flag changes. Re-enable does NOT reactivate.
 *
 * R3-CACHE-1: uses a chunked query-builder update (not a per-model save), so
 * IntegrationConnectionObserver::saved() never fires. Of its 12 reachable
 * behaviours only two apply to a bulk is_active flip — the cache purge and the
 * updated_at bump — both reproduced explicitly below (subdomain lookup as one
 * join per chunk, replacing the per-row N+1). The other ten (identity fold,
 * content-selection seeding, Instagram slot reconcile, mirrored-media cleanup,
 * tenant/platform dirty guards) all gate on wasRecentlyCreated or a payload/
 * platform/user_id write, none of which this operation performs — pinned by
 * ReconcilePlatformTakedownJobTest's enumeration-guard test, which fails the
 * day a future observer arm gets gated on is_active and this takedown ought to
 * run it too. Purges dispatch bulk:true onto the lowest-priority cloudflare_bulk
 * lane (never competing with real-time purges) with an optional run-global
 * stagger; see config/partna.php 'cache.bulk_purge_*'.
 *
 * CORRECTION (#W1-EDGE-1, 2026-08-29): "only two apply" undercounted by one.
 * IntegrationConnectionObserver also touches($connection->user->site) on an
 * is_active change for platforms with a completeness predicate (shop,
 * fresha) — a THIRD applicable behaviour this job did not reproduce. Rather
 * than special-case those two platforms, this job now reproduces that
 * behaviour's EFFECT unconditionally for every affected user below (lane 2:
 * the origin payload cache key), which covers it for all platforms, not just
 * the two with a completeness predicate.
 */
class ReconcilePlatformTakedownJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public array $backoff = [30, 120, 300];

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public string $platform,
        public ?string $segmentId = null,
    ) {
        $this->onQueue(config('partna.queues.platform_refresh'));
    }

    public function handle(SegmentResolver $resolver): void
    {
        $query = IntegrationConnection::query()
            ->where('platform', $this->platform)
            ->active();

        if ($this->segmentId !== null) {
            $segment = UserSegment::query()->find($this->segmentId);
            if ($segment === null) {
                return; // segment removed between dispatch and run
            }
            $userIds = $resolver->userIds($segment);
            if ($userIds === []) {
                return;
            }
            $query->whereIn('user_id', $userIds);
        }

        $stagger = (int) config('partna.cache.bulk_purge_stagger_seconds', 0);
        $cap = (int) config('partna.cache.bulk_purge_max_delay_seconds', 3600);
        $warnThreshold = (int) config('partna.cache.bulk_purge_volume_warning_threshold', 1000);

        // Run-GLOBAL, not per-chunk: resetting this inside chunkById's callback
        // would collapse the stagger to 200-row buckets. $seen dedupes a user
        // holding several rows on one platform (which can straddle a chunk
        // boundary) to a single purge, since ShouldBeUnique's dedupe lock can't
        // be relied on once dispatch delays exceed CloudflareCachePurgeJob's
        // uniqueFor.
        $index = 0;
        $seen = [];

        // chunkById is safe while mutating is_active: pages by ascending id, and
        // flipped rows drop out of the active() filter without being revisited.
        $query->chunkById(200, function ($connections) use (&$index, &$seen, $stagger, $cap): void {
            $ids = $connections->pluck('id');

            // Bulk flip, one statement per page, no model events (see class
            // docblock) — updated_at set explicitly since a query-builder update
            // skips Eloquent's auto-bump.
            IntegrationConnection::query()->whereIn('id', $ids)->update([
                'is_active' => false,
                'updated_at' => now(),
            ]);

            $userIds = $connections->pluck('user_id')->unique();

            // Lane 2 — the origin payload cache key
            // (public.profile:{handle}:{ts}, derived from site.sites.updated_at).
            // The bulk flip above is a raw query-builder update: no Eloquent
            // event fires, so nothing else rolls this column for these users.
            // Without it the taken-down platform's content keeps rendering
            // from the pre-takedown payload cache for up to its TTL + stale
            // window. ONE statement per PAGE (not per row) — asserted by a
            // dedicated test.
            $now = now();
            DB::connection('pgsql')->table('site.sites')
                ->whereIn('user_id', $userIds)
                ->update(['updated_at' => $now]);

            // One join per page replaces the refresher's per-row
            // User::with('site')->find() N+1.
            $subdomainsByUser = Site::query()
                ->whereIn('user_id', $userIds)
                ->pluck('subdomain', 'user_id');

            foreach ($subdomainsByUser as $subdomain) {
                if ($subdomain === null || isset($seen[$subdomain])) {
                    continue;
                }
                $seen[$subdomain] = true;

                // Defeats the 30s handle.resolve cache, which ALSO caches
                // updated_at_ts — without raising the floor, a reader that
                // queried pre-flip can re-cache the stale ts for the
                // remainder of that cache's TTL, and the rotated key above is
                // never constructed (SiteCacheService::raiseResolveFloor()
                // docblock).
                app(SiteCacheService::class)->raiseResolveFloor($subdomain, $now->timestamp);

                $pending = CloudflareCachePurgeJob::dispatch($subdomain, bulk: true);

                $delaySeconds = $stagger > 0 ? min($index * $stagger, $cap) : 0;
                if ($delaySeconds > 0) {
                    $pending->delay(now()->addSeconds($delaySeconds));
                }
                $index++;
            }

            // Deliberately NOT routed through SiteCacheLanes::bust():
            // (1) bust() delays lane 3 by EDGE_PURGE_DELAY_SECONDS (15s) —
            //     SiteCacheLanes.php:66-69 explicitly carves compliance
            //     purges (this job named among them) out of that delay; a
            //     takedown's edge purge must stay immediate.
            // (2) lane 1 (BuildState::bump) is the POOL-DOCUMENT lane. A
            //     connection's is_active flip is filtered LIVE by
            //     SitepageDataResolverService — there is no document to
            //     rebuild here, so lane 1 does not apply.
            // Lane 2 is reproduced explicitly above instead. A future sweep
            // finding this job outside the bust() seam is not an omission.
        });

        if (count($seen) > $warnThreshold) {
            // Volume signal only — no continuation/self-redispatch loop here on
            // purpose (see plan §A4E). A very large takedown is already cheaper
            // than the old per-model-save path; this just makes an oversized
            // fan-out visible before it becomes a problem.
            Log::warning('Platform takedown purge fan-out exceeded volume threshold', [
                'platform' => $this->platform,
                'segment_id' => $this->segmentId,
                'distinct_subdomains' => count($seen),
                'threshold' => $warnThreshold,
            ]);
        }
    }

    public function failed(\Throwable $e): void
    {
        // A takedown that exhausts retries mid-sweep leaves some connections still
        // active — a partially applied compliance action. Surface it with context;
        // re-dispatching completes the sweep (flips are idempotent).
        report($e);

        Log::error('Platform takedown reconciliation permanently failed — takedown may be partially applied', [
            'platform' => $this->platform,
            'segment_id' => $this->segmentId,
            'message' => $e->getMessage(),
        ]);
    }
}
