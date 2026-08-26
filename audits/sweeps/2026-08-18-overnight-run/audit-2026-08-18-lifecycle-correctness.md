# Lifecycle Correctness Audit — 2026-08-18

**Branch:** audit-fix/instagram-wave-findings-2026-08-18
**Lens:** Lifecycle correctness — race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4-6`
**Source files audited:**
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Jobs/Platforms/GoogleBusinessEnrichJob.php, ScanPreviousWebsiteContentJob.php
- app/Services/Platforms/GoogleBusinessApifyScraper.php, GoogleBusinessAutoSync.php
- app/Services/Media/MediaMirror.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Services/Platforms/Payloads/InstagramPayload.php
- app/Ingest/Projection/ProjectionWriter.php
- app/Ingest/Landing/Lander.php
- app/Ingest/SourceProvisioner.php
- app/Ingest/Connectors/FreshaConnector.php, AppleMusicConnector.php
- app/Site/Pools/PoolResolver.php, ItemLinkRules.php, LiveSourceScope.php
- routes/console.php
- supabase/migrations/20260727130000_ingest_schema.sql, 20260727140000_content_schema.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 6 complete
- P2 Medium: 2 of 11 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#LIFE-1** · P1 — `ProjectionWriter::resolveItems`/`bindGroup` run identity resolution with no lock, no transaction
    - **Where:** app/Ingest/Projection/ProjectionWriter.php:592-746
    - **Affects:** Any user with two sources of the same content `kind` (e.g. Spotify tracks + SoundCloud tracks, both `track`) whose `RunSourceJob`s are claimed and executed concurrently by different queue workers.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Serialize `resolveItems()`+`bindGroup()` per `(user_id, kind)` behind a `Cache::lock()` — mirroring the `platformConnectionLock` pattern `GoogleBusinessEnrichJob::persist()` already uses for exactly this "re-read fresh, never clobber a concurrent write" shape — rather than a raw `lockForUpdate` over the whole read set (which would serialize unrelated users).
        - Add a regression test that runs two `projectStream()` calls for the same `(user, kind)` concurrently and asserts no duplicate `content.item_anchors` rows and no premature `mergeInto()` delete.
    - **Technical:** `SourceScheduler::claimOne()`/`claimDue()` claim and lock at the *source* grain, not at `(user_id, kind)`. Two different sources of the same kind for one user can therefore be claimed and run by two separate workers at once. `projectStream()`'s per-record transaction (line 198) only covers the `source_items`/`identity_keys` upsert; `resolveItems()` (reads `content.identity_keys` + `content.item_anchors` scoped by `user_id`+`kind`, not `stream_id`) and `bindGroup()` (reads-then-inserts `content.item_anchors`, then calls `mergeInto()`, which hard-`DELETE`s a discarded item carrying no curation) run entirely outside any transaction or lock. The class's own docblock at line 194 discusses only the same-stream deadlock case ("accepted, not retried") — it does not address two *different* streams of the same kind racing through this later, unprotected stage. A lost race can hard-delete a just-created item's row via `mergeInto()`'s cascade before the losing worker's own group-assignment logic re-reads it.
    - **Plain English:** When two of a performer's connected accounts (say Spotify and SoundCloud) both get their catalogue refreshed at the same moment, the system has two clerks race to file the same person's paperwork into one folder. Right now nothing stops both clerks from grabbing an unlabeled folder at once, and the loser's own already-filed paperwork can get shredded a moment later. This should happen rarely today (most users connect one music platform), but as more users connect multiple platforms this becomes a routine coincidence.
    - **Evidence:**
        ```php
        $anchors = DB::table('content.item_anchors')
            ->where('user_id', $userId)
            ->whereIn('coord', $group)
            ->orderBy('bound_at')
            ->get(['coord', 'item_id', 'superseded_by', 'bound_at']);
        // ...
        foreach ($effective->reject(fn (string $itemId) => $itemId === $winner) as $loser) {
            $this->mergeInto($userId, keptItemId: $winner, discardedItemId: (string) $loser);
        }
        ```

- [ ] **#LIFE-2** · P1 — `content.source_stats` review aggregate is not filtered by retired/disconnected source_items, republishing a hidden review score
    - **Where:** app/Site/Pools/PoolResolver.php:314-321 (`statsFor()`)
    - **Affects:** Public reviews pool `stats` badge (star rating + count) for any site whose review-carrying platform connection is later disconnected or whose review source item is retired by absence-folding.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Join `content.source_items` and add `whereNull('si.removed_at')`.
        - For connection-sourced review stats, also filter out a `deleted_at`/`is_active=false` `site.platform_connections` row, matching `LiveSourceScope`'s definition of "live" exactly.
    - **Technical:** `PoolResolver` establishes "disconnect = hide" as a hard contract everywhere else in this class — `LiveSourceScope::apply()` is deliberately called for both the library query and the pinned-items re-check in `resolve()` (lines 218, 229), and `reviewsSuppressedByOwner()` exists specifically so a hidden review doesn't leak. `statsFor()` is the one aggregate read in this file that skips the check: it joins `content.source_stats` to `content.source_items` with no `removed_at`/connection-liveness filter, so a disconnected Google Business connection's stale `rating_avg`/`rating_count` keeps being served in the public `pools.reviews.stats` envelope after the owner disconnects it.
    - **Plain English:** If a business owner disconnects their Google listing because the reviews on it are bad, the star rating badge on their public page should disappear along with the reviews. Right now it doesn't — the old score keeps showing because the code that reads the "star rating" number forgot to check whether the reviews are still connected, even though every other part of this same file does check.
    - **Evidence:**
        ```php
        $row = DB::connection('pgsql')->table('content.source_stats as ss')
            ->join('content.source_items as si', 'si.source_id', '=', 'ss.source_id')
            ->whereIn('si.item_id', array_column($selection, 'id'))
            ->orderByDesc('ss.rating_count')
            ->first(['ss.rating_avg', 'ss.rating_count', 'ss.summary_text']);
        ```

- [ ] **#LIFE-3** · P1 — Item's fallback public `platform`/`url` can be derived from a retired or deactivated connection
    - **Where:** app/Site/Pools/PoolResolver.php:528-616 (`$sourceRows` query and `$sourcePlatforms` derivation)
    - **Affects:** Any public pool item with no `f_link` row of its own (e.g. a Fresha service, which has no per-service URL) whose only contributing connection is disconnected or deactivated after the item was landed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->where('site.platform_connections.is_active', true)` to the connection arm of the `$sourceRows` join, matching `LiveSourceScope`'s three-part definition of live (present, not deleted, active) instead of only checking `deleted_at`.
    - **Technical:** The comment at line 526 explicitly claims this query is "live only", but the `where` clause at lines 533-539 tests only `deleted_at IS NULL`, never `is_active`. `$sourcePlatforms` (line 604) then derives the item's fallback public `platform`/`url` from this same query — so an *inactive* (not deleted) connection can still be published as an item's primary link source, contradicting `LiveSourceScope`'s own definition used two methods up in the same class.
    - **Plain English:** A "pause this connection" switch and a "delete this connection" switch should both make its content stop showing publicly. Here, only the delete switch is checked — pausing a connection quietly leaves its content on the public page anyway, because one query in this file uses a narrower definition of "gone" than the rest of the class.
    - **Evidence:**
        ```php
        ->where(function ($w) {
            $w->where('content.sources.kind', 'manual')
                ->orWhere(function ($c) {
                    $c->whereNotNull('site.platform_connections.id')
                        ->whereNull('site.platform_connections.deleted_at');
                });
        })
        ```

- [ ] **#LIFE-4** · P1 — Public pool `links` array can surface a link from a disconnected/retired source
    - **Where:** app/Site/Pools/PoolResolver.php:485-496 (`$sourceLinks` query)
    - **Affects:** Public sitepage pool payloads (`watch`, `listen`, `media`, etc.) for any item that has both a live source (keeping it in the selection) and a stale `content.f_link` row from a source the owner later disconnected.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Join `content.source_items` and filter `whereNull('content.source_items.removed_at')`.
        - Add `whereNull('site.platform_connections.deleted_at')` and `where('site.platform_connections.is_active', true)` for connection-sourced links, aligning with `LiveSourceScope`.
    - **Technical:** `linkSet()` (consumed here) builds the item's `links` array — and therefore its primary outbound URL — from this `$sourceLinks` query, which has no liveness filter at all: no `source_items.removed_at` check, no `platform_connections.deleted_at`/`is_active` check. An item that stays selected via one live source (e.g. a manual pin, or a different still-connected platform) can still surface a dead link pointing at a platform the owner disconnected. This is the same "disconnect = hide" contract `LiveSourceScope` enforces for item presence, just not applied to the link array feeding an item that *is* still shown.
    - **Plain English:** A performer disconnects their old booking platform and connects a new one. Their page still shows because other content keeps it alive — but the "book now" button can still point at the old, disconnected platform's dead link, because the code that builds the button never re-checks whether that particular link's source is still connected.
    - **Evidence:**
        ```php
        $sourceLinks = DB::connection('pgsql')->table('content.f_link')
            ->join('content.sources', 'content.sources.id', '=', 'content.f_link.source_id')
            ->leftJoin('site.platform_connections', 'site.platform_connections.id', '=', 'content.sources.connection_id')
            ->whereIn('content.f_link.item_id', $ids)
            ->orderByDesc('content.sources.priority')
            ->get([...])
            ->groupBy('item_id');
        ```

- [ ] **#LIFE-5** · P1 — A newly-provisioned eager ingest source is permanently stranded if its first-run dispatch fails, with no reconcile job
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:277-333 (`maybeRunEagerly`)
    - **Affects:** Any user connecting Instagram (or any connector with `runsEagerlyOnConnect()`) during a transient queue-dispatch outage — their media never appears, indefinitely, with no automatic recovery.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a daily reconcile command that finds `ingest.sources` rows provisioned via an eager-run connector with `auto_sync = false` and no successful `last_run_at`, and re-claims/re-dispatches them.
        - Alternatively, persist an explicit "needs eager run" flag the normal `ingest:dispatch` scheduler can pick up instead of leaving `auto_sync = false` (which the scheduler's `claimDue()` never selects).
    - **Technical:** The method's own comment is explicit: a dispatch failure releases the claim so the row is re-runnable, but "this does NOT re-run it: the eager trigger fires once, on creation, and nothing retries it. A lost dispatch therefore means this user's media never arrives — `auto_sync=false` keeps the scheduler away no matter what `next_attempt_at` says. Recovering one needs a manual re-run." This is category 4's canonical gap exactly: an external-state-dependent transition (queue dispatch) with no sibling reconcile job. The caller (`syncIngestSource()`) only `Log::warning`s a `\Throwable`, which Nightwatch does not alert on.
    - **Plain English:** When someone connects Instagram, the system tries to schedule the very first data pull. If that scheduling call hits a brief outage — which happens occasionally in any cloud system — the system gives up permanently and nobody is told. The account looks connected but the person's photos never show up, and there's no automatic retry, ever, unless a human notices and manually re-runs it.
    - **Evidence:**
        ```php
        try {
            RunSourceJob::dispatch((string) $sourceId);
        } catch (\Throwable $e) {
            // ... a lost dispatch therefore means this user's media never
            // arrives — auto_sync=false keeps the scheduler away no matter
            // what next_attempt_at says. Recovering one needs a manual re-run.
            $scheduler->release((string) $sourceId, 'error', false);
            throw $e;
        }
        ```

- [ ] **#LIFE-6** · P1 — `buildPools()` swallows a query failure and blanks every pool for the full-length cache TTL
    - **Where:** app/Services/PublicSite/IndividualProfilePayloadBuilder.php:240-248 (`buildPools()`)
    - **Affects:** Every public sitepage visitor for a site whose pool resolution hits a transient `QueryException` — content vanishes entirely (not just the failing pool) and the empty result is cacheable for the full 60s TTL, not the 10s degraded TTL.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `catch` the exception per-pool: log it with the pool name, site ID and a discriminating message, `continue` to the next pool instead of `return []`.
        - Wire the caught exception into the same degraded-state signal `lastBuildDegraded()`/`degradedCacheTtl()` already expose (currently sourced only from `$this->resolver->hasDegraded()`), so a pool-query failure gets the short TTL instead of the normal one.
    - **Technical:** The `catch (QueryException) { return []; }` at line 243-247 exits the entire `foreach` over `PoolRegistry::POOLS`, so ANY single pool query failure — this is public sitepage resolution, explicitly the hottest read path in this codebase per the scale context — wipes every pool from the payload, not just the failing one. Worse, `lastBuildDegraded()` (line 690-693) only reflects `$this->resolver->hasDegraded()`; it has no visibility into this catch, so `IndividualProfileController`/`WarmPublicSiteCacheJob` cache the resulting empty-pools payload for the full `cacheTtl()` (60s) rather than `degradedCacheTtl()` (10s). Under exactly the "thousands of users; one page goes viral" scale scenario this lens is asked to reason about, a Postgres blip during a traffic spike now serves an empty page for up to 60s per occurrence, with zero log signal.
    - **Plain English:** If one small part of a database lookup for a performer's page has a brief hiccup, the entire page's content — videos, music, links, everything — currently vanishes for every visitor, and that blank page gets cached and kept for up to a full minute before anyone even tries again. This is most likely to happen exactly when a page is getting a lot of traffic (a database under load), which is the worst possible time for the whole page to go blank.
    - **Evidence:**
        ```php
        try {
            $resolved = $this->pools->resolve($site, $pool);
        } catch (QueryException) {
            // Partial test envs may not provision the content/sections
            // tables (the getContentMedia precedent); in production they
            // always exist. A missing lane yields no pools, never a 500.
            return [];
        }
        ```

## P2 — Should fix

- [ ] **#LIFE-7** · P2 — `analytics:compute-popularity`'s fixed lookback window drops a site's final popularity signal if it goes dormant during a missed scheduler tick
    - **Where:** routes/console.php:139-152
    - **Affects:** Public sitepage popularity ranking for a site whose last-ever activity lands inside a >45-minute scheduler outage window before the site goes dormant.
    - **Effort:** M (~2–4h, interim mitigation) — the code's own comment marks the full fix (a persisted watermark) as a larger, deferred schema change.
    - **What to do:**
        - No change strictly required before pilot — the code already documents this as a known, bounded, self-healing-in-the-common-case gap ("degrades a cosmetic ranking, not money/auth").
        - If addressed now: widen the lookback further as an interim step, or persist a `last_successful_run_at` watermark per the code's own noted proper fix.
    - **Technical:** This is not a new finding so much as an existing, in-code-documented open item (see the `⚠️ ALSO OPEN` comment block at lines 139-152) surfaced for visibility: the popularity sweep scopes itself to sites with a raw event in the last 60 minutes, so `K` consecutive missed 15-minute scheduler ticks (deploy restart, Redis blip) can lose a site's final activity if it happens to land in the gap right before the site goes dormant. The team has already reasoned through the math and bounded the blast radius; this is included here because it remains unfixed and matches the anchor-decoupling pattern (a fixed wall-clock window standing in for a durable watermark).
    - **Plain English:** A background job that ranks how popular each person's content is only looks at the last hour of activity each time it runs. If the job itself has a brief outage lasting more than about 45 minutes, and a page's very last visitor arrived during that outage before the page went quiet, that visit is never counted. This only affects a cosmetic popularity ranking, not money or security, and the team already knows about it and has a proper fix planned.
    - **Evidence:**
        ```php
        // ⚠️ ALSO OPEN (mitigated, not fixed) — missed-tick gap introduced by that
        // scoping: at the 15-min cadence, a W-minute lookback survives K consecutive
        // missed ticks (deploy restart, scheduler blip) with zero gap only when
        // W >= (K+1) x 15. ... Proper fix is a persisted last-successful-run watermark
        // instead of a fixed lookback — larger work, likely a schema change, deferred.
        ```

- [ ] **#LIFE-8** · P2 — `ItemLinkRules::syncedPlatformsFor()` counts a retired/disconnected source link as still-synced, blocking a manual link add
    - **Where:** app/Site/Pools/ItemLinkRules.php:82-92
    - **Affects:** Dashboard pool curation — an owner cannot hand-add a platform link for an item if a stale `content.f_link` row still names that platform, even after disconnecting the platform.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Join `content.source_items` and filter `whereNull('content.source_items.removed_at')`.
        - Add `whereNull('site.platform_connections.deleted_at')` and `where('site.platform_connections.is_active', true)`.
    - **Technical:** This dedup guard exists to stop an owner from overwriting a currently-*live* synced link with a manual one, but it reads `content.f_link` → `content.sources` → `site.platform_connections` with no liveness filter at all — the same root-cause pattern as #LIFE-4/#LIFE-2/#LIFE-3 above, in a sibling file. Unlike those, this one is dashboard-only (blocks a UI action rather than leaking public data), which is why it sits at P2 rather than P1 despite the identical defect shape.
    - **Plain English:** After disconnecting a platform, an owner should be free to type in their own link for that same platform. Right now the system still thinks the old, disconnected platform "owns" that slot and refuses the manual entry, because it never checks whether the old platform connection is actually still active.
    - **Evidence:**
        ```php
        return DB::connection('pgsql')->table('content.f_link')
            ->join('content.sources', 'content.sources.id', '=', 'content.f_link.source_id')
            ->join('site.platform_connections', 'site.platform_connections.id', '=', 'content.sources.connection_id')
            ->where('content.f_link.item_id', $itemId)
            ->distinct()
            ->pluck('site.platform_connections.platform')
            ->map(fn ($p) => (string) $p)
            ->all();
        ```

- [ ] **#LIFE-9** · P2 — Manual retry of a failed website scan re-dispatches billed OCR/AI sub-jobs with no dedup guard
    - **Where:** app/Jobs/Platforms/ScanPreviousWebsiteContentJob.php:73-121, 497-509
    - **Affects:** Users with `previous_website` set; vendor OCR/AI spend if a support engineer clicks Horizon's "Retry" on a failed run.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Give `WebsiteMenuPdfScanJob`/`WebsiteMenuHtmlScanJob` dispatches an idempotency key derived from `(user_id, url, scan_type)` so a re-run of the parent cannot re-bill already-completed sub-work.
        - Alternatively, record dispatched sub-job keys on a parent-level column and skip already-dispatched ones on retry.
    - **Technical:** `$tries = 1` already prevents *automatic* re-dispatch, and the class docblock + `failed()` log (`'note' => 'single-attempt job; manual Horizon retry re-bills OCR/AI sub-jobs'`) already document and flag the residual risk at the point of failure. The remaining gap is a *manual* Horizon retry, which nothing technically blocks — it re-runs the whole billed OCR/AI fan-out from scratch. Because the system already warns operators inline, this is real but lower-urgency than an unflagged gap would be.
    - **Plain English:** Scanning a website triggers several paid AI tasks (reading menus, PDFs). If the scan fails partway and someone clicks "try again" in the admin tools, it repeats all the paid tasks from scratch — including ones that already worked and got billed once. The system already warns whoever looks at the failure log about this risk, but nothing stops the double-billing from actually happening if they retry anyway.
    - **Evidence:**
        ```php
        public int $tries = 1;
        // ...
        'note' => 'single-attempt job; manual Horizon retry re-bills OCR/AI sub-jobs',
        ```

- [ ] **#LIFE-10** · P2 — `FreshaConnector` discards the vendor's actual GraphQL error, replacing it with a fixed generic message
    - **Where:** app/Ingest/Connectors/FreshaConnector.php:105-118, 300-320
    - **Affects:** Anyone debugging a Fresha menu ingest failure — a rotated persisted-query hash and any other GraphQL rejection reason are indistinguishable in the logs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `fetchBookingFlow()`, when `$decoded['errors']` is present, extract and return/log the first error's `message` alongside the `null` result so the caller's `Unavailable` message can include it.
    - **Technical:** `fetchBookingFlow()` checks `isset($decoded['errors'])` and returns `null` on either a non-200 status or a GraphQL `errors` key, discarding the decoded body either way. The caller then always yields the same fixed, hand-written `Unavailable` explanation regardless of what Fresha actually said. Given Fresha's persisted-query hash is explicitly documented as something that "WILL rotate" and this connector saw active rework in the last 24h of commits (W6 Fresha work), a verbatim vendor error would materially speed up the exact re-pin diagnosis this connector's own docblock describes a runbook for.
    - **Plain English:** When Fresha's system rejects our request, it sends back a specific reason. Right now we throw that reason away and just write "something's probably wrong with the pinned query" every time, whether the real cause was a rotated security token, a bad request, or something else entirely. Keeping Fresha's actual words would let whoever's debugging it see the real cause instead of guessing.
    - **Evidence:**
        ```php
        $decoded = json_decode($response['body'], true);
        if (! is_array($decoded) || isset($decoded['errors'])) {
            // A rejected persisted-query hash surfaces as a GraphQL `errors`
            // key on a 200, not as a non-200 status.
            return null;
        }
        ```

- [ ] **#LIFE-11** · P2 — `GoogleBusinessAutoSync::seedWorkplace()` is a check-then-write with no lock, unlike every sibling seed method in the same class
    - **Where:** app/Services/Platforms/GoogleBusinessAutoSync.php:414-460
    - **Affects:** A site whose owner edits their workplace description/category/website at the same moment a Google Business enrich job runs and tries to seed the same fields.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Take a per-site (or per-user) `Cache::lock()` around the read-blank-check-write cycle, matching the pattern `seedReservation()`/`seedBooking()`/`seedOrdering()` already use via `withReservationsXorLock()`/`withBookingXorLock()`/`withPlatformSeedLock()` in this same class.
    - **Technical:** Every other `seed*()` method in this file (`seedReservation`, `seedBooking`, `seedOrdering`) explicitly documents and wraps its read-then-write span in a shared lock — the `seedBooking()` comment even names the pattern: "the `has()`-then-`write()` check spans fresha/square/booking... The `has()` check MUST re-run INSIDE the closure — it's the read half of check-then-write." `seedWorkplace()` is the one sibling that skips this entirely: it does `Workplace::firstOrNew()`, reads `blank($workplace->{$key})`, and `save()`s with no lock at all. This is a genuine inconsistency within a class whose authors clearly understood and defended against this exact race everywhere else.
    - **Plain English:** This file has a well-established rule: before writing auto-filled business details, lock the record so a person's own edit can't be silently overwritten by the automatic sync happening at the same instant. Every part of this file follows that rule except the one that fills in the "About this business" text and website — that one skipped the lock, so a user editing their own bio at the wrong moment could have it clobbered by the automated Google sync.
    - **Evidence:**
        ```php
        $workplace = Workplace::query()->firstOrNew(['site_id' => (string) $site->id]);
        $sources = is_array($workplace->field_sources) ? $workplace->field_sources : [];
        $stamp = now()->toIso8601String();
        $changed = false;
        foreach ($fields as $key => $value) {
            if ($this->blank($workplace->{$key} ?? null)) {
                $workplace->{$key} = $value;
                $sources[$key] = ['source' => 'google-business', 'at' => $stamp];
                $changed = true;
            }
        }
        if ($changed) {
            $workplace->field_sources = $sources;
            $workplace->save();
        }
        ```

- [ ] **#LIFE-12** · P2 — `MediaMirror::fail()` has no aggregate escalation path, so a systemic outage stays invisible to Nightwatch
    - **Where:** app/Services/Media/MediaMirror.php:175-185
    - **Affects:** Instagram media mirroring for every user, if R2 credentials break or `SafeUrlFetcher`'s upstream host resolution fails wholesale.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Keep the per-call `Log::warning` (correct: one dead CDN link must not fail the whole sync), but add a rate/count check in the calling job that escalates via `report()`/`$this->fail()` when the failure rate crosses a threshold within a run.
    - **Technical:** `fail()` is deliberately non-throwing per-call — its docblock says so, and that's correct for a single dead link. The gap is purely at the aggregate level: there is no mechanism anywhere that turns "every mirror call in this run failed" into a Nightwatch-visible signal. Nightwatch alerts on exceptions and detected-slow jobs, not on `Log::warning` volume, so a full R2/credentials outage produces thousands of silent warnings and zero pages.
    - **Plain English:** If the storage system that copies Instagram photos breaks completely, the app currently just writes a quiet note in a log file for every single failed copy — nobody gets paged, no alarm sounds. A full outage could run for hours before a human notices, purely because nothing counts up "did a lot of these just fail in a row?" and says something.
    - **Evidence:**
        ```php
        private function fail(string $assetId, string $reason, string $sourceUrl, ?string $error = null): bool
        {
            Log::warning('media_mirror.failed', array_filter([...]));
            return false;
        }
        ```

- [x] **#LIFE-13** · P2 — Generic `QueryException` catch in `IntegrationConnectionObserver::syncIngestSource()` hides real database failures at debug level
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:234-252
    - **Affects:** Ingest-source provisioning for every platform connection save. A real DB failure (transaction abort, unique-constraint race from concurrent saves) is swallowed at debug level, invisible to Nightwatch.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Catch `Illuminate\Database\UniqueConstraintViolationException` specifically for the one known-benign race (a concurrent duplicate provisioning attempt, see #LIFE-10 in the media-jobs section... i.e. #LIFE-... below) and treat it as a no-op.
        - Let every other `QueryException` propagate to the existing `\Throwable` branch below it (which already does `report($e)` + `Log::warning`), instead of intercepting all `QueryException`s at `Log::debug`.
    - **Technical:** The comment justifies this as "the only way this write can raise one is a test DB that never provisioned the ingest mirror" — but that reasoning only covers a *missing table*, not the genuinely possible unique-constraint collision this same call chain can now hit (`ingest.sources` carries `sources_unique_per_connection UNIQUE (connection_id, source_key)` per `supabase/migrations/20260727130000_ingest_schema.sql:57`, and `SourceProvisioner::sync()`'s find-then-insert at lines 76-99 has no `insertOrIgnore` guard against it). A blanket `catch (QueryException)` at `Log::debug` therefore also swallows that real race, indistinguishable from the intended "test env" case.
    - **Plain English:** When two updates to the same connected platform happen close together, the database can correctly refuse the second one to prevent a duplicate. Right now that refusal — along with any other genuine database problem — gets written to a debug log nobody reads, instead of the "something's wrong" log the file already has right below it for other errors.
    - **Evidence:**
        ```php
        } catch (QueryException $e) {
            Log::debug('IntegrationConnectionObserver ingest-source sync query failure', [
                'platform_connection_id' => $connection->id,
                'message' => $e->getMessage(),
            ]);
        }
        ```

- [x] **#LIFE-14** · P2 — `SourceProvisioner::sync()`'s find-then-insert has no guard against its own unique constraint, so a concurrent connection save throws uncaught inside an observer
    - **Where:** app/Ingest/SourceProvisioner.php:76-99
    - **Affects:** Any user whose platform connection is saved twice in close succession (dashboard save racing a scheduled refresh, or the deferred-connect payload-fill write racing the initial insert).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Switch the `insert()` at line 82 to `insertOrIgnore()` followed by a re-read, exactly like `ProjectionWriter::ensureManualSource()` already does for the equivalent race on `content.sources` ("insertOrIgnore + re-read... since a concurrent caller may have won the partial-unique race").
    - **Technical:** `ingest.sources` carries `UNIQUE (connection_id, source_key)` (`sources_unique_per_connection`, `supabase/migrations/20260727130000_ingest_schema.sql:57`), so this is not a duplicate-rows risk (the DB constraint prevents that) — but the plain `insert()` at line 82 has no `insertOrIgnore`/typed-catch guard, so the *loser* of a concurrent race throws an uncaught `QueryException` from inside `syncIngestSource()`'s try/catch (see #LIFE-13), which currently swallows it at debug level rather than crashing. This is a correctness gap regardless: the loser's identity/selection sync for that write is silently dropped, and the pattern used one file over in `ProjectionWriter::ensureContentSource()`/`ensureManualSource()` for the identical shape already demonstrates the correct fix in this codebase.
    - **Technical (continued):** `content.sources` has the same shape gap — `ProjectionWriter::ensureContentSource()` (line 273-293) does a plain find-then-insert against `idx_content_sources_connection` (a UNIQUE index, `supabase/migrations/20260727140000_content_schema.sql:40`) with no guard, while its sibling `ensureManualSource()` two methods down explicitly uses `insertOrIgnore()` + re-read for the identical race. Apply the same fix to both call sites.
    - **Plain English:** Two places in the code check "does this row already exist?" and then insert if not — but if two things happen at the exact same moment, both can pass the check before either has inserted, and the database (correctly) rejects the second one. One method in this same codebase already knows how to handle that gracefully; two nearby methods don't, and currently just fail loudly (or get silently logged away) instead.
    - **Evidence:**
        ```php
        $existing = DB::table('ingest.sources')
            ->where('connection_id', $connection->id)
            ->where('source_key', $sourceKey)
            ->first(['id', 'identifier', 'auto_sync', 'selection_ref', 'cost_units']);

        if ($existing === null) {
            DB::table('ingest.sources')->insert([...]);
            return ['status' => 'created', 'source_key' => $sourceKey];
        }
        ```

- [ ] **#LIFE-15** · P2 — Ingest-badge lookup swallows every `QueryException` with zero logging
    - **Where:** app/Site/Pools/PoolResolver.php:559-570 (`itemPayloads()`)
    - **Affects:** The dashboard item sheet's "last synced" / auto-sync badges; Nightwatch observability of the same public-hot-path query.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log the caught exception at warning level with `connectionIds` count and the exception message before falling back to `[]`, so a real Postgres failure is distinguishable from the documented "ingest schema absent in this env" case.
    - **Technical:** Unlike #LIFE-13 (which at least logs at debug), this catch has *zero* logging — `catch (QueryException) { $ingestByConnection = []; }` — on a query that runs on the same public hot path `PoolResolver::itemPayloads()` serves. A genuine DB failure here is indistinguishable from "ingest schema not provisioned" and produces no log line at all, only a silently degraded sync badge.
    - **Plain English:** If a database read for "when was this last synced?" badge quietly fails, the page still loads fine — it just always shows "never synced," and there's no record anywhere that anything went wrong. A real outage and "this environment doesn't have that feature" look identical: silence.
    - **Evidence:**
        ```php
        try {
            $ingestByConnection = DB::connection('pgsql')->table('ingest.sources')
                ->whereIn('connection_id', $connectionIds)
                ->orderByDesc('last_run_at')
                ->get(['connection_id', 'last_run_at', 'auto_sync'])
                ->unique('connection_id')
                ->keyBy('connection_id')
                ->all();
        } catch (QueryException) {
            // No ingest schema in this environment: badges read "never".
            $ingestByConnection = [];
        }
        ```

- [ ] **#LIFE-16** · P2 — `platforms:enrich-pending-cards` is missing all three of this file's own mandatory scheduler conventions
    - **Where:** routes/console.php:499-502
    - **Affects:** The link-card enrichment safety net — silent failure with no Nightwatch alert, a 24-hour stale-lock window after any crashed run, and potential blocking of the per-minute scheduler tick.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->onFailure($reportScheduledFailure('platforms:enrich-pending-cards'))`.
        - Replace bare `->withoutOverlapping()` with an explicit TTL (e.g. `->withoutOverlapping(30)`).
        - Add `->runInBackground()`.
    - **Technical:** The file's own header (lines 11-25) mandates `onFailure`, an explicit `withoutOverlapping(N)`, and `runInBackground()` for every daily/cron-scale entry — and every other entry in the file follows it. This entry follows none of the three. If the command throws (vendor outage, schema mismatch), the failure is invisible to Nightwatch; if a run is hard-killed, the default 1440-minute lock silently suppresses the next day's run too; and a slow run blocks the per-minute scheduler tick that also drives the `keep-alive-ping` entry this file explicitly built to prevent cold-start blips.
    - **Plain English:** This file has a written rule at the top: every scheduled maintenance task must have an alarm wired to it, a sensible lock timeout, and must run in the background so it doesn't hold up other tasks. This particular task — a safety net that fills in missing link previews — was added without any of the three, breaking the file's own checklist.
    - **Evidence:**
        ```php
        Schedule::command('platforms:enrich-pending-cards --older-than=30')
            ->dailyAt('03:20')
            ->withoutOverlapping()
            ->onOneServer();
        ```

- [ ] **#LIFE-17** · P2 — `content:refresh-item-caches` is missing all three of this file's own mandatory scheduler conventions
    - **Where:** routes/console.php:506-509
    - **Affects:** The item-cache repair backstop for content that missed its projection refresh — same failure modes as #LIFE-16.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->onFailure($reportScheduledFailure('content:refresh-item-caches'))`.
        - Replace bare `->withoutOverlapping()` with an explicit TTL.
        - Add `->runInBackground()`.
    - **Technical:** Identical gap to #LIFE-16, in the sibling entry added in the same recent change (`e8e0c2d0f X4: content:refresh-item-caches` — 2026-08-18, per the commit log). Both new entries were added to the crowded 03:xx daily block without following the conventions block at the top of this same file that every pre-existing entry honours; this is very likely a copy-paste of one new entry off the other rather than off an existing compliant one.
    - **Plain English:** Same problem as the finding above, in the twin task that repairs stale item caches — it was added in the same recent change and has the same three missing safety nets.
    - **Evidence:**
        ```php
        Schedule::command('content:refresh-item-caches')
            ->dailyAt('03:25')
            ->withoutOverlapping()
            ->onOneServer();
        ```

## P3 — Nice to have

- [ ] **#LIFE-18** · P3 — `display_settings` read-modify-write in `enableContentInstagramAuto()` is not race-safe
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:175-202
    - **Affects:** A freshly-created Instagram connection whose `display_settings` is written by another concurrent process at the exact same moment.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the PHP read/unset/write with an atomic Postgres JSONB operation, e.g. `display_settings = display_settings - 'auto_sync_latest'`.
    - **Technical:** The method reads `$connection->display_settings` from the in-memory model (already loaded before this `saved()` observer fired), unsets one key, and writes the whole JSON blob back via a raw `DB::table()->update()`. Any other concurrent write to a different key in the same JSON column is lost. Low real-world likelihood: this only runs once, on a brand-new connection row (`wasRecentlyCreated`), so a genuine collision needs another process to write `display_settings` on that exact row within the same request cycle.
    - **Plain English:** When Instagram is first connected, the system reads the settings, changes one item, and saves the whole thing back — like retyping a whole form from memory instead of editing just one line. If someone else changes a different setting on that same connection at the very same instant, their change could be lost.
    - **Evidence:**
        ```php
        $settings = (array) ($connection->display_settings ?? []);
        if (($settings[AutoSyncSetting::KEY] ?? null) === false) {
            unset($settings[AutoSyncSetting::KEY]);
            DB::connection('pgsql')->table('site.platform_connections')
                ->where('id', $connection->id)
                ->update([
                    'display_settings' => $settings === [] ? null : json_encode($settings),
                    'updated_at' => now(),
                ]);
        }
        ```

- [ ] **#LIFE-19** · P3 — `MediaMirror::mirror()` fetches up to 80 MB before applying the 15 MB still-image size cap
    - **Where:** app/Services/Media/MediaMirror.php:70-109
    - **Affects:** Media-mirror queue worker memory during Instagram content ingest, if an oversized or malformed asset is fetched.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply `MAX_BYTES` (15 MB) rather than `MAX_VIDEO_BYTES` (80 MB) to the initial `withMaxBytes()` fetch whenever the response can already be inferred as a still image (e.g. non-`ftyp`-prefixed body), reserving the 80 MB cap for the confirmed-video branch.
    - **Technical:** `mirror()` fetches every URL with `->withMaxBytes(self::MAX_VIDEO_BYTES)` (80 MB) regardless of expected content type, and only checks `strlen($body) > self::MAX_BYTES` (15 MB) after the video-signature check fails and the full body is already in memory. A pathological or hostile still image up to 80 MB is fully buffered before being rejected. Bounded impact: only reachable for `instagram:`-prefixed (owned) refs, one asset per queue-job invocation, and Instagram's own CDN doesn't realistically serve 80 MB "images" — this is hardening, not an active exploit path today.
    - **Plain English:** The code is supposed to reject photos bigger than 15 MB, but it downloads up to 80 MB into memory *before* checking the size. A handful of oversized files being processed at once could use more memory than intended, though this requires an unusual file from a source that's already limited to Instagram content.
    - **Evidence:**
        ```php
        $response = $this->fetcher->withMaxBytes(self::MAX_VIDEO_BYTES)->tryFetch($sourceUrl);
        // ...
        if ($body === '' || strlen($body) > self::MAX_BYTES) {
            return $this->fail($assetId, 'body_rejected', $sourceUrl);
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Pool liveness-filter gaps:** #LIFE-2, #LIFE-3, #LIFE-4, #LIFE-8
    - **Why grouped:** Same file (`PoolResolver.php`, plus sibling `ItemLinkRules.php`), identical root cause — a query reads `content.f_link`/`content.source_stats`/`content.source_items` without the same `is_active`/`removed_at`/`deleted_at` liveness filter `LiveSourceScope` already establishes as the contract elsewhere in this class. One PR can apply the same join fix four times.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Scheduler convention completeness:** #LIFE-16, #LIFE-17
    - **Why grouped:** Same file (`routes/console.php`), same two sibling entries added in the same recent commit, same three missing chained calls.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Silent-exception observability hardening:** #LIFE-13, #LIFE-15
    - **Why grouped:** Same root-cause pattern (a `QueryException` swallowed with insufficient/zero logging on the same general read path) across two files in the same subsystem (ingest-badge/source lookups); same fix shape (narrow the catch, add a log line).
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 4 — Ingest/platform-sync hardening:** #LIFE-11, #LIFE-14
    - **Why grouped:** Both are check-then-write/find-then-insert races in the platform-connection ingest pipeline (`app/Services/Platforms`, `app/Ingest`) with an existing in-codebase precedent for the correct fix (`withPlatformSeedLock`/`insertOrIgnore`+re-read) already used by sibling methods in the same files.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#LIFE-1 — ProjectionWriter identity-resolution race** · L-effort, concurrency-correctness change to the core content-identity spine; needs its own plan and sign-off before touching `bindGroup()`/`mergeInto()`'s hard-delete path.
- **#LIFE-5 — Eager ingest source stranding** · Needs a design decision (reconcile command vs. persisted flag) before implementation; not a pure bugfix.
- **#LIFE-6 — buildPools() swallows QueryException on the hottest read path** · Touches the public sitepage cache/TTL contract directly; isolate for careful review of the degraded-TTL wiring.
- **#LIFE-9 — ScanPreviousWebsiteContentJob re-billing risk** · Touches money (vendor OCR/AI spend); mandatory standalone per policy.
- **#LIFE-7 — analytics:compute-popularity lookback gap** · Already a known, deferred, larger-scope item per the code's own comment; do not fold into an unrelated session.
- **#LIFE-18 — display_settings race** · Small and isolated; no natural bundle partner.
- **#LIFE-19 — MediaMirror image-cap ordering** · Small and isolated; no natural bundle partner.
