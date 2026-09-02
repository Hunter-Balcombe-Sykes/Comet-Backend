<?php

namespace App\Jobs\Platforms;

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\PreAccountBuildEvent;
use App\Routing\IriCanonicalizer;
use App\Routing\LinkProjector;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\DisplaySettingsFilter;
use App\Services\Platforms\GoogleBusinessApifyScraper;
use App\Services\Platforms\GoogleBusinessAutoSync;
use App\Services\Platforms\LinkInBioDetector;
use App\Services\Platforms\Payloads\GoogleBusinessPayload;
use App\Services\Platforms\Registry\Platform;
use App\Services\Platforms\ScrapeCreators\FindSocialProfilesClient;
use App\Services\Platforms\WebsiteLinkHarvester;
use App\Services\PreAccount\BuildProgress;
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

    // $autoConnectBooking rides in from the staff/ManyChat build path. NOT part
    // of uniqueId() below: two enriches of the same place for the same user are
    // the same job whatever their origin, and keying on it would let a staff
    // build and a dashboard connect run concurrently against one listing.
    public bool $autoConnectBooking = false;

    public function __construct(
        public readonly string $userId,
        public readonly string $placeId,
        bool $autoConnectBooking = false,
    ) {
        $this->autoConnectBooking = $autoConnectBooking;
        $this->onQueue(config('partna.queues.signup', 'signup'));
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
        // Setup progress (2026-09-02): say the stage has STARTED, so the
        // signup card's status line names the job actually running.
        BuildProgress::noteForUser($this->userId, PreAccountBuildEvent::STAGE_LISTING, PreAccountBuildEvent::STATUS_STARTED, 'Pulling your Google listing');
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

        // Item 9c (2026-09-01): the workplace seed consumes ONLY the Place
        // Details payload already on the row — it never needed the harvest or
        // the paid Apify result it used to wait ~17-57s behind. Seeding FIRST
        // starts the whole website-scan subtree (WorkplaceObserver →
        // ScanPreviousWebsiteContentJob → gallery/logo/menu/accent) in
        // parallel with the scrape below, and fixes a quiet loss: the
        // enrichment-unavailable soft-fail used to return before the
        // workplace ever seeded, dropping a valid Place Details card because
        // an unrelated paid call came back empty. This is also the R2 fix-1
        // intent finished: previous_website now lands before the harvest
        // itself, not merely before seed().
        $autoSync->seedWorkplaceEarly($this->userId, $gbp->toArray());

        // A listing whose "website" is a link-in-bio page (linktr.ee, beacons,
        // msha.ke, stan.store) has nothing for the anchor harvest to find —
        // those pages render their links from JSON — but it is exactly the
        // page that bundles the business's ordering / booking / socials.
        // Unroll it through the same job Instagram bio links use (overnight
        // 2026-08-18 F15: Top Choice Wollongong's linktree produced 0 links)
        // and skip the anchor harvest for it.
        $website = $gbp->website();
        $isLinkInBio = is_string($website) && $website !== '' && app(LinkInBioDetector::class)->matches($website);
        if ($isLinkInBio) {
            // Setup progress (2026-09-02): the platforms row is owed from here.
            BuildProgress::noteForUser($this->userId, PreAccountBuildEvent::STAGE_PLATFORMS, PreAccountBuildEvent::STATUS_STARTED, 'Checking your website for platforms');
            LinkInBioScanJob::dispatch($this->userId, $website, $this->autoConnectBooking);
            Log::info('google_business.enrich_job.link_in_bio_unroll', ['user_id' => $this->userId, 'place_id' => $this->placeId]);
        }
        $harvest = $isLinkInBio ? [] : $harvester->harvest($website);

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

        // Item 11g (2026-09-01): when the harvest named an Instagram or
        // Facebook account, ask the discovery lane what OTHER platforms that
        // identity verifiably has. Lowest authority in the union below — a
        // link published on the site or crawled off the listing always beats
        // a vendor corroboration — and fail-open: an empty map leaves the
        // merge exactly as it was. Budget lives in the client (claim before
        // call); no notification rides on any of this.
        $discovered = $this->discoverSocials($harvest['socials'] ?? []);

        // Merge: Apify (when it ran) is the base; harvested keys overlay it —
        // links published on the business's own site are at least as
        // authoritative as ones crawled off the listing. Socials merge per
        // network so each source can fill networks the other missed.
        $enrichment = [
            ...($enrichment ?? []),
            ...array_diff_key($harvest, ['socials' => true]),
            ...(($harvest['socials'] ?? []) !== [] || ($enrichment['socials'] ?? []) !== []
                ? ['socials' => [...$discovered, ...($enrichment['socials'] ?? []), ...($harvest['socials'] ?? [])]]
                : []),
        ];

        // The harvested links live on their OWN integrations now (Reservations /
        // Online-ordering / Social), not on the Google Business payload. Seed them
        // only into slots the user hasn't filled, tagged source:'google-business'.
        // Booking syncs for every account type; the reservation/ordering/workplace/
        // social seeds are Business-Partna only (see GoogleBusinessAutoSync::seed).
        // (The workplace card itself seeded at the top of handle() — Item 9c.)
        $findings = $autoSync->seed(
            $this->userId,
            $enrichment,
            $gbp->name(),
            $gbp->toArray(),
            $this->autoConnectBooking,
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

            $payload = [
                ...$businessInfo,
                'apifyFetchedAt' => now()->toIso8601String(),
                // What THIS scrape produced — drives the connect modal's "found
                // platforms" list (only this run's, with live status + Change-to).
                'syncFindings' => $findings,
            ];

            // PRIV-2: internal bookkeeping is not held against someone who has not
            // signed up — the same minimisation InstagramSourceGenerator applies to
            // this key, extended to the Google path. What renders is unaffected: the
            // findings' real output is the seeded connections persisted above.
            //
            // The cost is REAL and permanent, not merely deferred. A 'conflict'
            // finding writes no row by definition (GoogleBusinessAutoSync's header),
            // so for a conflict the finding IS the whole output — and it does not come
            // back on claim: this job runs once per build, ClaimSiteService's
            // claim-time RefreshConnectionJob re-fetches Place Details only, and
            // GoogleBusinessAutoSync never touches LinkRouter so there is no
            // routing.source_intents backstop the way there is on the Instagram side.
            // The owner sees it again only if they reconnect from the dashboard, which
            // re-runs this job. Same trade LinkInBioScanJob already accepts.
            //
            // unset, NOT "skip the assignment": $businessInfo is rebuilt from the
            // STORED payload via payloadOf(), so a value written before this guard
            // existed rides straight back through otherwise. That only self-heals on a
            // build re-run or retry, though — GoogleBusinessFetch carries the same
            // unset so the 12h refresh cron (which does NOT filter on user status)
            // clears already-affected rows without a backfill.
            if ($fresh->ownerIsUnclaimed()) {
                unset($payload['syncFindings']);
            }

            $fresh->forceFill([
                'payload' => $payload,
                'apify_status' => 'ok',
            ])->saveQuietly();
        });

        if (! $saved) {
            return;
        }

        // Setup progress (2026-09-02): the listing row the feed shows. The
        // enriched payload was written inside the transaction closure — read
        // the row back (first(), never value(): the cast is what decodes it).
        $connection->refresh();
        $listingPayload = GoogleBusinessPayload::fromArray($connection->payload);
        $listing = $listingPayload->toArray();
        BuildProgress::noteForUser(
            $this->userId,
            PreAccountBuildEvent::STAGE_LISTING,
            PreAccountBuildEvent::STATUS_LANDED,
            'Pulled the Google listing — hours, photos and reviews',
            [
                'name' => $listingPayload->name(),
                'rating' => is_numeric($listing['rating'] ?? $listing['totalScore'] ?? null) ? (float) ($listing['rating'] ?? $listing['totalScore']) : null,
                'reviews' => is_numeric($listing['reviewsCount'] ?? $listing['userRatingCount'] ?? null) ? (int) ($listing['reviewsCount'] ?? $listing['userRatingCount']) : null,
                'photos' => array_slice(array_values(array_filter(array_map(
                    static fn (array $p): ?string => $p['photoPicUrl'],
                    $listingPayload->photos(),
                ), static fn (?string $u): bool => $u !== null && $u !== '')), 0, 3),
                // Sign-up preview (2026-09-02, A.5): up to three review samples
                // WHEN the stored listing carries reviews. On the pre-claim
                // path it never does — GoogleBusinessPayload::stripThirdPartyPii
                // drops `reviews` before the provisional write (PRIV-1) — so
                // this is [] for a signup preview and fills only for a claimed
                // owner's re-enrich. The scene hides the block when empty.
                'reviewSamples' => array_slice(array_values(array_filter(array_map(
                    static function (mixed $r): ?array {
                        if (! is_array($r)) {
                            return null;
                        }
                        $text = $r['text'] ?? $r['reviewText'] ?? null;
                        $author = $r['name'] ?? $r['authorName'] ?? $r['author'] ?? null;
                        $rating = $r['rating'] ?? $r['stars'] ?? null;

                        return is_string($text) && trim($text) !== ''
                            ? ['author' => is_string($author) ? $author : null, 'rating' => is_numeric($rating) ? (float) $rating : null, 'text' => trim($text)]
                            : null;
                    },
                    is_array($listing['reviews'] ?? null) ? $listing['reviews'] : [],
                ))), 0, 3),
            ],
        );

        // Terminal success: clear both markers so a genuine later reconnect
        // re-scrapes (and re-bills) fresh instead of replaying a stale cache.
        Cache::forget($inflightKey);
        Cache::forget($resultKey);

        // ALWAYS try the Google-photos menu scan after an enrichment (owner
        // 2026-07-17, amended T6/D1 2026-08-27: with a sufficient platform
        // menu the scan is enrich-only — no new scan-owned items) — the job
        // itself gates on the menu capability + AI keys and no-ops for
        // everyone else. The settling delay now applies only when an
        // ordering-platform fetch actually needs settling.
        GoogleMenuPhotoScanJob::dispatchAfterEnrich($this->userId, $this->placeId);
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

    /**
     * Item 11g: {network => url} additions from the find-social-profiles
     * lane, seeded off the FIRST projectable harvested IG/FB profile URL
     * (Instagram preferred — the vendor's richest corroboration source; the
     * endpoint costs 10 credits, so at most ONE call, never both networks).
     * Handle extraction rides the same catalog projection seedSocials itself
     * trusts — a reserved segment (/reel/…, profile.php) yields no
     * identifier and the lane stays quiet, spending nothing. Keys translate
     * into the harvest vocabulary ('twitter', not the registry's 'x') so the
     * socials union reads to seedSocials exactly like a harvest.
     *
     * @param  array<string, mixed>  $harvestSocials
     * @return array<string, string>
     */
    private function discoverSocials(array $harvestSocials): array
    {
        foreach (['instagram', 'facebook'] as $network) {
            $url = $harvestSocials[$network] ?? null;
            if (! is_string($url) || $url === '') {
                continue;
            }

            try {
                $projection = app(LinkProjector::class)->project(app(IriCanonicalizer::class)->canonicalize($url));
                if ($projection->surfaceKey !== $network.'.profile'
                    || ! is_string($projection->identifier) || $projection->identifier === '') {
                    continue; // not a profile URL — the other network still gets its (free) look
                }

                $discovered = [];
                foreach (app(FindSocialProfilesClient::class)->discover($network, $projection->identifier, $this->userId) ?? [] as $key => $profileUrl) {
                    $discovered[$key === 'x' ? 'twitter' : $key] = $profileUrl;
                }

                return $discovered;
            } catch (Throwable $e) {
                report($e);

                return [];
            }
        }

        return [];
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
