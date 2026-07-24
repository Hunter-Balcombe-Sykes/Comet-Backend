<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\DisplaySettingsFilter;
use App\Services\Platforms\GoogleBusinessApifyScraper;
use App\Services\Platforms\GoogleBusinessAutoSync;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\WebsiteLinkHarvester;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

// Background half of the Google Business connect. The Place Details snapshot is
// fetched synchronously by the controller (so the card renders instantly), while
// the slower Apify enrichment — menu / reservation / order / booking / social
// links — runs here so connect() returns immediately instead of blocking a
// PHP-FPM worker on a multi-second actor run.
//
// The connection row is written by the controller with payload.apifyStatus =
// 'pending' BEFORE this job is dispatched; the job merges the Apify result and
// flips the status to 'ok' / 'unavailable'. The dashboard polls the selection
// endpoint until the status leaves 'pending'.
class GoogleBusinessEnrichJob implements ShouldBeUnique, ShouldQueue, ThrottledByProvider
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Apify single-place run is usually well under a minute; allow headroom.
    public int $timeout = 130;

    // Unlimited attempts, bounded by retryUntil() below — the 'platform-connect'
    // RateLimited middleware RELEASES this job when the actor is over-limit, and every
    // release counts as an attempt. A finite $tries would mass-fail enrichments during
    // a burst the gate exists to absorb. Genuine errors stay capped by $maxExceptions.
    public int $tries = 0;

    /** @var list<int> Backoff between exception-triggered retries (not rate-limit releases). */
    public array $backoff = [30, 120];

    public int $maxExceptions = 2;

    // One enrichment per connection at a time. The window matches retryUntil() so a job
    // parked in rate-limit purgatory can't have a duplicate slip in and bill a second
    // Apify run. The lock also releases on completion/failure — worst-case backstop.
    public int $uniqueFor = 900;

    // JOB-2: cache-marker TTLs for paid-scrape idempotency (see handle()). Inflight
    // matches $uniqueFor/retryUntil's 15-minute window — it only needs to outlive one
    // job's retry lifetime. Result TTL deliberately OUTLIVES the retry deadline so
    // every retry inside that window (including the very last one) can still reuse it.
    //
    // Residual assumption (accepted, not a defect): these markers live ONLY in cache.
    // If the cache store is flushed or LRU-evicts them between attempts, the markers
    // vanish and the next retry re-bills Apify as if it were a first attempt. They
    // can't be backstopped in the DB instead — apify_status's CHECK constraint
    // (supabase/migrations/20260701220000_promote_gb_apify_status_placeid.sql) only
    // allows 'pending' | 'ok' | 'unavailable'; a 'processing' value is illegal.
    private const APIFY_INFLIGHT_TTL = 900;

    private const APIFY_RESULT_TTL = 1800;

    public function __construct(
        public readonly string $userId,
        public readonly string $placeId,
    ) {
        $this->onQueue(config('partna.queues.scraping', 'scraping'));
    }

    // Key on user + place: a true duplicate (retry / double connect of the same
    // place) dedups; reconnecting a DIFFERENT place still runs.
    public function uniqueId(): string
    {
        return $this->userId.':'.$this->placeId;
    }

    /** Apify actor for the 'platform-connect' rate budget. */
    public function providerRateKey(): string
    {
        return Platform::GoogleBusiness->value;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new RateLimited('platform-connect')];
    }

    // Wall-clock deadline for rate-limit releases. An enrichment held behind the actor's
    // per-minute limit keeps retrying until it runs or 15 min elapses, then lapses to
    // failed() (terminal) — never an infinite park.
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(15);
    }

    public function handle(GoogleBusinessApifyScraper $scraper, GoogleBusinessAutoSync $autoSync, WebsiteLinkHarvester $harvester): void
    {
        $connection = $this->connection();
        if (! $connection) {
            return;
        }

        $inflightKey = CacheKeyGenerator::googleBusinessApifyInflight($this->userId, $this->placeId);
        $resultKey = CacheKeyGenerator::googleBusinessApifyResult($this->userId, $this->placeId);

        // JOB-2: captured BEFORE any write below — this is what distinguishes "a
        // PREVIOUS attempt started the paid call" (inflight already set) from
        // "this attempt did" (we're about to set it ourselves further down).
        $inflightBefore = Cache::get($inflightKey);
        $cachedResult = Cache::get($resultKey);

        // In-house first: the business's OWN website usually carries the
        // social / reservation / ordering / booking links — one free fetch
        // recovers them instantly. The paid Apify run only fires when the
        // listing plausibly holds Maps-only data the site can't provide
        // (food places: menu + google reserve/food links) or the harvest
        // came back empty.
        $gbp = GoogleBusinessPayload::fromArray($connection->payload);
        $harvest = $harvester->harvest($gbp->website());

        $enrichment = null;
        // OBS-6 context: which of three states produced a null enrichment —
        // tracked here so the soft-failure log below can tell them apart.
        $apifyAttempted = false;
        $reusedCachedResult = false;
        $orphanedRun = false;

        if ($this->needsApify($harvest, $gbp->category())) {
            if (is_array($cachedResult)) {
                // A previous attempt in this job's retry window already paid for
                // and cached the result — reuse it, never re-bill for a retry.
                $enrichment = $cachedResult['enrichment'];
                $reusedCachedResult = true;
                Log::info('google_business.enrich_job.apify_result_reused', [
                    'user_id' => $this->userId,
                    'place_id' => $this->placeId,
                ]);
            } elseif ($inflightBefore !== null) {
                // Inflight marker set, no result cached: a prior attempt died
                // DURING the paid call (worker kill, timeout, crash). POLICY:
                // refuse to re-bill — that run's data is unrecoverable. The free
                // website harvest above still ran. Recovery is NOT immediate on
                // reconnect: the cache keys are userId:placeId, so reconnecting
                // the SAME place reuses the SAME inflight marker and stays
                // blocked until it expires (APIFY_INFLIGHT_TTL, 900s). Only a
                // reconnect to a DIFFERENT place gets a fresh key pair right away.
                $enrichment = null;
                $orphanedRun = true;
                Log::warning('google_business.enrich_job.apify_orphaned_run', [
                    'user_id' => $this->userId,
                    'place_id' => $this->placeId,
                    'inflight_since' => $inflightBefore,
                ]);
            } else {
                // First attempt: mark inflight BEFORE the paid call, cache the
                // result IMMEDIATELY after it returns (before any other work) —
                // so even if seed() below throws, the paid result survives.
                Cache::put($inflightKey, now()->toIso8601String(), self::APIFY_INFLIGHT_TTL);
                $apifyAttempted = true;
                $enrichment = $scraper->fetch($this->placeId, $this->userId);
                Cache::put($resultKey, ['enrichment' => $enrichment], self::APIFY_RESULT_TTL);
            }
        }

        if ($enrichment === null && $harvest === []) {
            // OBS-6: this used to be a silent soft-failure. The three booleans
            // above discriminate "harvest found nothing, Apify never called"
            // from "Apify ran and returned nothing" from "refused to re-bill an
            // orphaned run" — otherwise all three log-indistinguishable.
            Log::warning('google_business.enrich_job.enrichment_unavailable', [
                'user_id' => $this->userId,
                'place_id' => $this->placeId,
                'apify_attempted' => $apifyAttempted,
                // Constant true by construction: we're inside the $enrichment === null branch above.
                'apify_returned_null' => true,
                'apify_result_reused' => $reusedCachedResult,
                'apify_orphaned_run' => $orphanedRun,
                'harvest_empty' => true,
                'website' => $gbp->website(),
                'category' => $gbp->category(),
            ]);

            // Soft failure: keep the Place Details payload, just mark the Apify
            // layer 'unavailable' so the dashboard stops polling. No hard fail —
            // the core card is unaffected and a re-connect can retry.
            $this->mark($connection, 'unavailable');

            return;
        }

        // Merge: Apify (when it ran) is the base; harvested keys overlay it —
        // links published on the business's own site are at least as
        // authoritative as ones crawled off the listing. Socials merge per
        // network so each source can fill networks the other missed.
        $enrichment = [
            ...($enrichment ?? []),
            ...array_diff_key($harvest, ['socials' => true]),
            ...(($harvest['socials'] ?? []) !== [] || ($enrichment['socials'] ?? []) !== []
                ? ['socials' => [...($enrichment['socials'] ?? []), ...($harvest['socials'] ?? [])]]
                : []),
        ];

        // The harvested links live on their OWN integrations now (Reservations /
        // Online-ordering / Social), not on the Google Business payload. Seed them
        // only into slots the user hasn't filled, tagged source:'google-business'.
        // Booking syncs for every account type; the reservation/ordering/workplace/
        // social seeds are Business-Partna only (see GoogleBusinessAutoSync::seed).
        $findings = $autoSync->seed(
            $this->userId,
            $enrichment,
            $gbp->name(),
            $gbp->toArray(),
        );

        // LIFE-10: write behind the per-user/platform lock, rebuilding the
        // payload from a FRESHLY re-read row (never the stale $connection above)
        // so a concurrent ScheduledRefresh write can't be clobbered by this
        // multi-second job's last-write-wins save. GoogleBusinessController's
        // connect()/applySync()/forget() all wrap their mutations in the same
        // withConnectionLock key (see the controller's own PWL-1 comments), so
        // a concurrent dashboard save is covered too, not just scheduled
        // refreshes. persist() also re-applies the place_id predicate as an
        // implicit CAS: if the user reconnected a DIFFERENT place mid-scrape,
        // it abandons instead of writing stale data over the new connection.
        $saved = $this->persist($connection, 'success', function (IntegrationConnection $fresh) use ($findings) {
            // Write back business-info only: strip the enrichment keys (stale ones
            // from a pre-cleanup connect included) and record apifyFetchedAt + this
            // run's findings. apifyStatus is now a real column, not a payload key.
            // The GB payload has no public change, so saveQuietly — the seeded rows
            // above fired their own sitepage cache purges.
            // WS-B2: gate display sections the owner switched off out of the
            // persisted payload — the same DisplaySettingsFilter gate
            // GoogleBusinessFetch applies — so a re-enrich while a section is off
            // never re-seeds suppressed data.
            $businessInfo = $this->gateDisabled(
                Arr::except($this->payloadOf($fresh), ['menu', 'reservation', 'order', 'booking', 'socials']),
                $fresh,
            );

            $fresh->forceFill([
                'payload' => [
                    ...$businessInfo,
                    'apifyFetchedAt' => now()->toIso8601String(),
                    // What THIS scrape produced — drives the connect modal's "found
                    // platforms" list (only this run's, with live status + Change-to).
                    'syncFindings' => $findings,
                ],
                'apify_status' => 'ok',
            ])->saveQuietly();
        });

        if (! $saved) {
            return;
        }

        // Terminal success: clear both markers so a genuine later reconnect
        // re-scrapes (and re-bills) fresh instead of replaying a stale cache.
        Cache::forget($inflightKey);
        Cache::forget($resultKey);

        // ALWAYS try the Google-photos menu scan after an enrichment (owner
        // 2026-07-17) — it enriches whatever the ordering-platform scrape
        // produced (longer descriptions, dietary badges, scan-only dishes)
        // rather than competing with it. Delayed so a same-connect
        // MenuFetchJob settles first; the job itself gates on the menu
        // capability + AI keys and no-ops for everyone else.
        GoogleMenuPhotoScanJob::dispatch($this->userId, $this->placeId)
            ->delay(now()->addMinutes(5));
    }

    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('google_business.enrich_job.failed', [
            'user_id' => $this->userId,
            'place_id' => $this->placeId,
            'error' => $e->getMessage(),
        ]);

        // Terminal: no further retry of THIS job will happen, so a user-initiated
        // retry (reconnect) must be allowed to bill again. Deliberately cleared
        // BEFORE the mark() write below (which can itself fail: lock timeout,
        // stale connection, or an uncaught exception from the write) so this
        // guarantee is unconditional — a future reconnect is never left blocked
        // behind a stale marker just because the status write had trouble. This
        // is safe now that mark()'s terminal persist() never releases the job on
        // a lock timeout (see persist()'s docblock): there is no path left where
        // clearing first lets a resurrected job replay with clean cache state.
        Cache::forget(CacheKeyGenerator::googleBusinessApifyInflight($this->userId, $this->placeId));
        Cache::forget(CacheKeyGenerator::googleBusinessApifyResult($this->userId, $this->placeId));

        $connection = $this->connection();
        if ($connection) {
            $this->mark($connection, 'unavailable', terminal: true);
        }
    }

    /**
     * Whether the paid Apify run is still needed after the website harvest.
     * Food-oriented places keep it (menu action link + google reserve/food
     * URLs only exist on the Maps listing); everyone else skips it once the
     * harvest found anything usable. An empty harvest always falls through
     * to Apify so coverage never regresses.
     */
    private function needsApify(array $harvest, ?string $category): bool
    {
        if ($harvest === []) {
            return true;
        }

        $cat = strtolower($category ?? '');
        foreach (['restaurant', 'cafe', 'coffee', 'bar', 'bakery', 'food', 'pizza', 'kitchen', 'diner', 'eatery', 'bistro', 'pub', 'takeaway', 'grill'] as $kw) {
            if (str_contains($cat, $kw)) {
                return true;
            }
        }

        return false;
    }

    // The user's single google-business connection, matched on the indexed
    // place_id column — guards against clobbering after the user reconnected a
    // DIFFERENT place while this job was queued. The model's soft-delete scope
    // adds deleted_at IS NULL, matching the partial index.
    private function connection(): ?IntegrationConnection
    {
        return IntegrationConnection::query()
            ->where('user_id', $this->userId)
            ->where('platform', Platform::GoogleBusiness->value)
            ->where('place_id', $this->placeId)
            ->first();
    }

    private function mark(IntegrationConnection $connection, string $status, bool $terminal = false): void
    {
        $this->persist($connection, 'mark:'.$status, function (IntegrationConnection $fresh) use ($status) {
            $fresh->forceFill([
                'payload' => [
                    ...$this->gateDisabled($this->payloadOf($fresh), $fresh),
                    'apifyFetchedAt' => now()->toIso8601String(),
                ],
                'apify_status' => $status,
            ])->saveQuietly();
        }, $terminal);
    }

    /**
     * LIFE-10: serialize a re-read→mutate→write cycle behind the SAME
     * per-user/platform lock key ScheduledRefresh (the other writer that
     * actually contends for a google-business row) takes — same key builder
     * (CacheKeyGenerator::platformConnectionLock) — so this multi-second job
     * can never clobber that concurrent write with a stale in-memory read.
     * ManagesIntegrationConnection::withConnectionLock uses the identical key
     * builder, but no google-business controller action (connect/applySync/
     * forget) calls it, so it is NOT actually a concurrent writer this lock
     * serializes against today — see the note in handle(). google-business is
     * single-selection (resource_id always equals the platform slug), so the
     * platform-wide key here was always equivalent to a per-account one for
     * this platform — the 2026-07-21 removal of the suffix parameter changes
     * nothing for this call site.
     *
     * $connection is used ONLY to build the lock key (platform / user_id
     * never change across a reconnect of the same row); the row $mutate
     * operates on is re-selected fresh from inside the lock via connection()'s
     * own `WHERE place_id = ?` predicate — which doubles as an implicit
     * compare-and-swap: if the user reconnected a DIFFERENT place while this
     * job ran, the re-select comes back null and we abandon rather than write
     * a payload describing a place they no longer have connected.
     *
     * $terminal marks a call made from failed(): Laravel has already called
     * Job::fail() (deleted=true, failed=true on the driver job) before our
     * failed() callback runs. InteractsWithQueue::release() does not check
     * either flag — it forwards unconditionally to the driver (e.g. Redis
     * RedisJob::release() -> RedisQueue::deleteAndRelease(), which moves the
     * job from reserved back to delayed), which would RESURRECT an already
     * terminally-failed job. failed() clears the JOB-2 cost-guard cache
     * markers before calling this, so a resurrected run would see clean cache
     * state and re-bill Apify — the exact scenario JOB-2 exists to prevent.
     * So on lock timeout from a terminal call, give up instead of releasing;
     * from a non-terminal (handle()) call, releasing is correct and safe —
     * the retry is free because JOB-2's result cache serves the cached
     * enrichment instead of re-billing.
     *
     * @param  callable(IntegrationConnection):void  $mutate
     * @return bool true if $mutate ran and saved; false on CAS-miss or lock timeout
     */
    private function persist(IntegrationConnection $connection, string $stage, callable $mutate, bool $terminal = false): bool
    {
        $key = CacheKeyGenerator::platformConnectionLock($connection->platform, $connection->user_id);

        try {
            return Cache::lock($key, 10)->block(5, function () use ($stage, $mutate) {
                $fresh = $this->connection();
                if (! $fresh) {
                    Log::warning('google_business.enrich_job.stale_connection', [
                        'user_id' => $this->userId,
                        'place_id' => $this->placeId,
                        'stage' => $stage,
                    ]);

                    return false;
                }

                $mutate($fresh);

                return true;
            });
        } catch (LockTimeoutException $e) {
            report($e);
            Log::warning('google_business.enrich_job.lock_timeout', [
                'user_id' => $this->userId,
                'place_id' => $this->placeId,
                'stage' => $stage,
            ]);

            if ($terminal) {
                // Terminal: no further retry of THIS job will happen (see the
                // docblock above) — releasing here would resurrect an
                // already-failed job and re-bill Apify. Just give up; a
                // user-initiated reconnect is the only recovery path.
                return false;
            }

            // Free re-run: JOB-2's result cache will serve the enrichment on the
            // next attempt without re-billing Apify. NEVER fall through and write
            // unlocked — that's the exact race this lock exists to prevent.
            $this->release(30);

            return false;
        }
    }

    /** @return array<string,mixed> */
    private function payloadOf(IntegrationConnection $connection): array
    {
        return GoogleBusinessPayload::fromArray($connection->payload)->toArray();
    }

    /**
     * Strip the payload keys of any display section the owner switched off, so a
     * re-enrich never persists data we won't serve (WS-B2 — mirrors the gate in
     * GoogleBusinessFetch). placeId is exempted: it is the refresh identity key
     * (GoogleBusinessFetch reads payload.placeId) and, unlike GoogleBusinessFetch's
     * `[...$payload, ...$details]` merge, this persist path has no base spread to
     * restore it — dropping it would 500 the next scheduled refresh on missing_place_id.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function gateDisabled(array $payload, IntegrationConnection $connection): array
    {
        $disabled = array_diff(
            DisplaySettingsFilter::disabledKeys('google-business', $connection->display_settings),
            ['placeId'],
        );

        return Arr::except($payload, $disabled);
    }
}
