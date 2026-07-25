<?php

namespace App\Jobs\Cloudflare;

use App\Models\Core\Site\Site;
use App\Services\Cloudflare\CloudflarePurgeService;
use App\Services\Moderation\EdgePurgeEscalator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

// Purges the Cloudflare edge cache for one professional's public profile URL.
// Dispatched on every site mutation that changes payload visible at the edge
// (SiteObserver::saved, account_type transitions, future block/media writes).
//
// Why a dedicated retry policy (not HasCloudflareRetryPolicy):
//   The KV policy targets the KV REST API's failure profile (rare, slow). Cache
//   purge has its own 4xx/5xx semantics — short retries with exponential backoff
//   are enough; a third retry at 60s is wasted because the underlying mutation
//   has long since settled. Keep this distinct from the KV trait.
class CloudflareCachePurgeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 15, 60];

    // Short-circuit permanent failures (e.g. revoked token) so failed()/Nightwatch fires after 2 attempts, not 3.
    public int $maxExceptions = 2;

    // Derived worst case (2026-07-20, complements SCALE-101 in CloudflarePurgeService,
    // updated for the cache-edge-reconcile/LIFE-1 residual fix): purgeHandle() chunks
    // up to ~1,480 URLs at 30/chunk = ~50 sequential blocking Http::post()->throw()
    // calls to Cloudflare, each now explicitly bounded to timeout(10)+connectTimeout(3)
    // (previously unbounded — Laravel's blanket 30s default applied). Typical case:
    // ~3s/call under a healthy Cloudflare API — 50 x 3s = 150s, + the pacing budget's
    // 2s hard ceiling = ~152s, leaving real margin under 180s. The per-call bound
    // narrows (doesn't eliminate) the pathological case: it now takes 18+ chunks
    // hitting the full 10s ceiling to exhaust the 180s budget, vs. 6 at the old
    // unbounded ~30s default — a broadly degraded Cloudflare API can still exhaust it
    // and get the job killed mid-purge. That residual is accepted scope (a
    // retry ledger / resumable purge was explicitly declined as a follow-up); the
    // volume-signal log in CloudflarePurgeService::purgeHandle() is the mitigation —
    // it surfaces an oversized catalog before it starts failing outright.
    // Sanity-checked against the queue: the job's OWN $timeout wins over Horizon's
    // supervisor-level timeout (Illuminate\Queue\Worker::timeoutForJob() prefers
    // $job->timeout() over $options->timeout), so config/horizon.php's
    // supervisor-level timeout doesn't cap this. What DOES matter is the
    // 'cloudflare' queue's connection — config/queue.php 'redis' has
    // retry_after=360 (default) — 180 stays comfortably under that so Redis can't
    // re-reserve this job to a second worker while the first is still purging
    // (which would duplicate purges).
    public int $timeout = 180;

    /**
     * Coalesce window: while a purge for this handle is queued/running, duplicate
     * dispatches from the same request's observer cascade (or a rapid burst of
     * edits) are dropped. MUST exceed $timeout — ShouldBeUnique's lock is a cache
     * lock with TTL = $uniqueFor, and it is only force-released on CLEAN
     * completion; a timeout-kill leaves it to expire on its own TTL. If
     * $uniqueFor < $timeout, a purge still running past the TTL loses its dedupe
     * protection mid-flight and a genuine duplicate slips through. Raised 120->240
     * alongside the $timeout 15->180 bump for exactly that reason; 240 also stays
     * under redis retry_after=360. Pinned by a test — do not raise $timeout without
     * raising this too.
     *
     * Follow-ups keep a SHORT 30s lock instead: their dispatch delay (the
     * shortest schedule offset, 120s by default) exceeds any lock we'd want,
     * and a lock outliving the delay would coalesce
     * away the follow-up owed to a LATER edit — leaving that edit's stale re-pin
     * window uncovered for the router's 24h HTML TTL. A follow-up does the same
     * ~50-chunk work, but its lock is deliberately shorter than $timeout: dropping
     * a redundant follow-up is cheap, dropping a later edit's follow-up is not.
     */
    public int $uniqueFor = 240;

    /**
     * R3-CACHE-1: set when this purge was dispatched from a bulk fan-out
     * (ReconcilePlatformTakedownJob), not a real-time site mutation. Routes to
     * the lowest-priority cloudflare_bulk lane and adds a '|b' uniqueId
     * discriminator — WITHOUT the discriminator, a delayed bulk purge would
     * hold the SAME dispatch-time ShouldBeUnique lock as a real-time purge for
     * the same handle and silently suppress it, making the user under takedown
     * see their OWN unrelated edits fail to purge. Declared as a plain property
     * with a class-level default (NOT a promoted readonly constructor param):
     * a promoted property has no class default, so an in-flight 'cloudflare'
     * payload serialized before this change and unserialized after it would
     * leave the property uninitialized and fatal in uniqueId() on retry.
     */
    public bool $bulk = false;

    /**
     * Which follow-up in the schedule this is (1-based); 0 for a primary purge.
     * Feeds uniqueId() so the up-front-dispatched follow-ups don't coalesce into
     * each other, and logging. No job ever re-dispatches on the strength of it.
     *
     * Plain property with a class-level default, assigned in the constructor body
     * — NOT a promoted readonly param. A promoted property has no class default,
     * so a payload serialized before this deploy and unserialized after it would
     * leave this uninitialized and fatal in uniqueId() on retry. Same reasoning
     * as $bulk above; $followUp stays a promoted bool so in-flight payloads
     * carrying it keep working.
     */
    public int $followUpDepth = 0;

    public function uniqueId(): string
    {
        // Include the custom domain so a purge that must also bust the custom
        // domain isn't coalesced into a handle-only purge already in flight.
        // Follow-ups get their own lock namespace so the primary's lock can't
        // swallow them. A moderation-context purge (hide_content) gets its own
        // discriminator too — it must never be coalesced away by a routine
        // purge for the same handle in flight (e.g. a concurrent unrelated
        // profile edit), since it's the ONLY backstop that evicts hidden
        // content from the edge (see failed()). A bulk (takedown fan-out)
        // purge gets its own discriminator for the same reason — see $bulk.
        return strtolower(trim($this->handle))
            .'|'.strtolower(trim((string) $this->customDomain))
            .($this->followUp ? '|fu'.$this->followUpDepth : '')
            .($this->moderationCaseId !== null ? '|m'.$this->moderationCaseId : '')
            .($this->bulk ? '|b' : '');
    }

    public function __construct(
        public readonly string $handle,
        public readonly ?string $customDomain = null,
        public readonly bool $followUp = false,
        // Set only when this purge enforces a moderation decision (currently
        // hide_content, dispatched by PurgeModerationCacheJob). Drives failed()
        // below — routine purges (SiteObserver, gallery, account edits) must
        // NOT page on-call, so this stays null for every other dispatch site.
        public readonly ?string $moderationCaseId = null,
        bool $bulk = false,
        int $followUpDepth = 0,
    ) {
        $this->bulk = $bulk;
        $this->followUpDepth = $followUpDepth;

        // Isolated from user-facing work so a burst of site mutations can't
        // delay notifications or mail delivery. Bulk (takedown fan-out) purges
        // route to the lowest-priority cloudflare_bulk lane instead — see
        // config/horizon.php supervisor-1 and $bulk's docblock — so a large
        // takedown structurally never competes with real-time purges.
        if ($this->bulk) {
            $this->onQueue(config('partna.queues.cloudflare_bulk', 'cloudflare_bulk'));
        } else {
            $this->onQueue(config('partna.queues.cloudflare', 'cloudflare'));
        }
        $this->uniqueFor = $followUp ? 30 : 240;
    }

    public function handle(CloudflarePurgeService $purge): void
    {
        $h = strtolower(trim($this->handle));
        if ($h === '') {
            $this->fail(new \RuntimeException('Empty handle dispatched to CloudflareCachePurgeJob'));

            return;
        }

        // Resolve the active custom domain from the handle when a dispatcher didn't
        // pass one, so EVERY purge — from any observer/job, present or future — busts
        // the custom-domain edge cache too, not just the .partna.au URLs. (Fix
        // 2026-06-16: Instagram/service/media changes dispatched handle-only, leaving
        // custom domains like tuesdae.co stale until a manual dashboard "purge
        // everything". SiteObserver already passed it; the others didn't.) Only an
        // 'active' custom domain is actually served, so only that is purged.
        $customDomain = $this->customDomain;
        if ($customDomain === null) {
            $site = Site::query()
                ->where('subdomain', $h)
                ->first(['custom_domain', 'custom_domain_status']);
            if ($site && $site->custom_domain_status === 'active' && $site->custom_domain) {
                $customDomain = (string) $site->custom_domain;
            }
        }

        $purge->purgeHandle($h, $customDomain);

        // A visitor can hit the just-purged URL while the payload layers are
        // still inside their staleness windows (Laravel Cloud's OWN Cloudflare
        // edge honours s-maxage on api/public/profiles and sits outside our
        // zone's purge reach, + the Worker's subrequest cache) — the router
        // would then re-pin that stale render under its 24h HTML TTL. Delayed
        // follow-up purges evict any such re-pin.
        //
        // All of them are dispatched HERE, up-front, one per schedule entry —
        // not chained. A chain loses its tail if any link exhausts its retries,
        // and the last offset is precisely the one a degraded-Cloudflare window
        // most needs. uniqueId()'s depth discriminator keeps them from
        // coalescing, and the 30s follow-up lock expires long before any delay.
        if (! $this->followUp) {
            /** @var list<int> $schedule */
            $schedule = array_values((array) config('partna.cache.purge_followup_schedule', [120, 300, 900]));

            foreach ($schedule as $index => $offsetSeconds) {
                // Forward $bulk so a takedown's follow-ups also stay on the
                // cloudflare_bulk lane — dropping that would let them compete
                // with real-time purges, defeating the lane isolation.
                self::dispatch(
                    $this->handle,
                    $this->customDomain,
                    followUp: true,
                    moderationCaseId: $this->moderationCaseId,
                    bulk: $this->bulk,
                    followUpDepth: $index + 1,
                )->delay(now()->addSeconds((int) $offsetSeconds));
            }
        }
    }

    /**
     * Terminal failure. Always reports + logs. When this purge enforces a
     * moderation hide_content decision, ALSO pages on-call staff: hide_content
     * keeps the owner active, so SyncSubdomainToKvJob never retires the KV
     * routing entry for it — this edge purge is the only thing that evicts the
     * hidden content from caches.default. If it exhausts retries silently,
     * hidden content can keep serving indefinitely. Routine (non-moderation)
     * purge failures never reach this branch — moderationCaseId is null — so
     * SiteObserver/gallery/account-edit purges don't page on-call.
     */
    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('cloudflare.cache_purge.failed', [
            'handle' => $this->handle,
            'error' => $e->getMessage(),
        ]);

        if ($this->moderationCaseId !== null) {
            app(EdgePurgeEscalator::class)->escalate($this->handle, $this->moderationCaseId);
        }
    }
}
