# Scaling Antipatterns Audit — 2026-08-24

**Branch:** development
**Lens:** Scaling antipatterns: write amplification, rebuild-on-write, weak caching
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-opus-4.6`
**Source files audited:**
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Services/Analytics/ActionScorer.php
- app/Services/Analytics/AnalyticsEvent.php
- app/Services/Analytics/ContentFreshness.php
- app/Services/Analytics/ContentPopularityReader.php
- app/Services/Analytics/ItemFamily.php
- app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php
- app/Http/Controllers/Api/User/Analytics/DevInsightsController.php
- app/Http/Resources/Platforms/PublicIntegrationConnectionResource.php
- app/Http/Resources/PublicSite/IndividualProfileResource.php
- app/Ingest/Projection/FreshaServiceProjector.php
- app/Console/Commands/ComputeContentPopularityScores.php (pulled in to verify ActionScorer's caller)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 5 complete

---

## P2 — Should fix

- [ ] **CACHE-1** · P2 — `ActionScorer::aggregate()` scans the site's full retention window of raw action events on every compute tick
    - **Where:** app/Services/Analytics/ActionScorer.php:166-178
    - **Affects:** `analytics:compute-popularity` (runs every 15 min for sites with recent activity), `analytics.action_events` read load, action-score freshness for viral sitepages.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add an `occurred_at >= now()->subDays(HALF_LIFE_DAYS-ish window)` bound to the `analytics.action_events` query so old-but-still-in-retention rows aren't rescanned every tick once their day-weight is negligible.
        - Alternatively, maintain `exposures`/`taps` as a trigger-maintained signed-delta rollup keyed by `(action_id, event, day)` so each tick reads only the small aggregate table, not the raw event log.
        - If neither lands soon, at minimum confirm the composite index (`site_id, occurred_at`) is actually used by `EXPLAIN` for this query shape — the `COUNT(DISTINCT COALESCE(...))` may defeat a simple index scan.
    - **Technical:** Category 5 (hot-path heavy work). `aggregate()` runs `selectRaw(...)->groupByRaw('action_id, event, day')->get()->each(...)` against `analytics.action_events` filtered only by `site_id` — no `occurred_at` lower bound. The GROUP BY does mean PHP only iterates the aggregated rows (not one hydration per raw event, correcting the draft's framing), but Postgres must still scan every row in the site's retention window to build that aggregate. Raw events are purged at a 90-day floor (`config('partna.analytics_raw_event_retention_days')`, `PurgeRawAnalyticsEvents`) and `HALF_LIFE_DAYS = 90.0` matches that window almost exactly, so this isn't an unbounded-forever scan — it's a full 90-day scan repeated every ~15 minutes for any site with recent traffic (`ComputeContentPopularityScores`'s `siteIdsWithRecentEvents` scoping, added under SCALE-3). For a page that goes viral and accumulates a large fraction of its 90-day budget in action-exposure/tap rows, this is real, recurring I/O pressure on the write-heavy analytics path, not a one-off.
    - **Plain English:** Every 15 minutes, the system re-adds up three months of clicks and views for any page that's had recent activity, instead of remembering yesterday's total and just adding today's numbers. A page having a viral moment makes that three-month pile bigger and bigger, so the "add it all up again" job gets slower and slower right when it matters most.
    - **Evidence:**
        ```php
        DB::connection('pgsql')->table('analytics.action_events')
            ->where('site_id', $site->id)
            ->selectRaw("action_id, event, {$day} as day, COUNT(DISTINCT COALESCE(session_id, visitor_id, id)) as sessions")
            ->groupByRaw("action_id, event, {$day}")
            ->get()
            ->each(function ($r) use (&$exposures, &$taps, $now): void {
        ```

- [ ] **CACHE-2** · P2 — Staff CSV batch endpoint runs up to 500 sequential multi-table build writes synchronously inside the HTTP request
    - **Where:** app/Http/Controllers/Api/Staff/UserSiteManagement/StaffPreAccountBuildController.php:151-189
    - **Affects:** Staff CSV batch imports (`POST /api/staff/builds/batch`); the web worker handling the request; `PreAccountBuild`/site/user writes; auto-invite email fan-out (up to 500 emails per request).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Move the row loop into a queued job (e.g. `ProcessPreAccountBuildBatch`) dispatched after the file is validated and stored; return 202 with a batch/job identifier.
        - Process rows via `Bus::batch` or a chunked cursor inside the job so one slow/failing row can't stall the whole run.
        - Persist per-row success/failure to a result store the staff UI can poll, instead of returning the full result array synchronously.
    - **Technical:** Category 2 (write amplification / hot-path fan-out). `batch()` iterates up to 500 CSV rows inside the request/response cycle and calls `PreAccountBuildService::requestBuild()` once per row — a multi-table lifecycle write (`core.users`, `site.sites`, `core.pre_account_builds`) — with `autoInvite` defaulting to `true`, so a single request can synchronously perform up to 500 build-creation transactions plus up to 500 invite-email dispatches before responding. This ties up a Laravel Cloud web worker for the full duration of the batch; under the platform's per-instance concurrency-by-memory model, a handful of large concurrent staff imports can meaningfully reduce available request capacity. The row cap (500) confirms large batches are an expected, not hypothetical, usage pattern for this endpoint.
    - **Plain English:** When staff upload a spreadsheet of up to 500 new profiles, the website currently does all 500 one at a time — right there, while the staff member's browser waits — before it says "done." If one row is slow, the whole page hangs, and busy servers can be tied up handling this one upload instead of serving other users. It should hand the spreadsheet to a background worker and immediately tell staff "we're on it."
    - **Evidence:**
        ```php
        foreach ($rows as $i => $row) {
            $email = $row['contact_email'] ?? null;
            if ($email !== null && $email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failed[] = ['row' => $i + 1, 'code' => 'INVALID_EMAIL', 'message' => "Invalid email: {$email}"];

                continue;
            }

            try {
                $result = $this->builds->requestBuild(
                    accountType: (string) ($row['account_type'] ?? ''),
                    sourceType: (string) ($row['source_type'] ?? ''),
                    rawSourceRef: (string) ($row['source_ref'] ?? ''),
                    sourceName: ($row['source_name'] ?? null) ?: null,
                    ipHash: null,
                    staff: $staff,
                    publish: true,
                    contactEmail: ($email ?: null),
                    autoInvite: filter_var($row['auto_invite'] ?? 'true', FILTER_VALIDATE_BOOLEAN),
                );
                $result['reused'] ? $reused++ : $built++;
            } catch (PreAccountBuildException $e) {
                $failed[] = ['row' => $i + 1, 'code' => $e->errorCode, 'message' => $e->getMessage()];
            }
        }
        ```

## P3 — Nice to have

- [ ] **CACHE-3** · P3 — `IntegrationConnectionObserver::saved()` inlines several independent side effects rather than chaining/batching them
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:63-146
    - **Affects:** Every meaningful platform-connection save (connect, payload refresh, (de)activation) — cache refresh, site touch, identity sync, ingest sync, menu fetch.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Consider collapsing the post-save side effects into one queued follow-up job (`Bus::chain`) for readability/observability, but note this is a polish pass, not a correctness fix — see Technical.
        - If pursued, keep the existing "meaningful change" gating and `ShouldBeUnique` dedup semantics; don't lose the idempotency the current design relies on.
    - **Technical:** Category 5 (observer fan-out). `saved()` conditionally invokes `maybeFetchMenu()`, `refresher->refresh()`, `$site->touch()`, `syncIdentityFromGoogle()`, `syncIngestSource()`, and `enableContentInstagramAuto()`. This is a real instance of the "N independent jobs per save" shape the lens flags, but severity is tempered by design already in place: every downstream job this dispatches (`MenuFetchJob`, `CloudflareCachePurgeJob` via the refresher, `RunSourceJob`) is `ShouldBeUnique` and the code's own comments state this is deliberate — "a burst coalesces to one purge + one rebuild." The work is also gated behind `wasChanged()` checks, so routine scheduled refreshes that don't change payload skip most of this entirely. Net: bounded, mostly-idempotent, O(1)-per-save work, not a cardinality-scaling risk — worth consolidating for clarity, not urgent for load.
    - **Plain English:** Saving one connected platform account currently flips on several different follow-up jobs one after another — refresh a cache, update a timestamp, sync an identity, sync a feed. The code already has safety nets so repeated triggers collapse into one real action, so this isn't causing overload today — but bundling these into one clearly-named step would make the code easier to reason about.
    - **Evidence:**
        ```php
        public function saved(IntegrationConnection $connection): void
        {
            $this->maybeFetchMenu($connection);

            if ($connection->wasRecentlyCreated
                || $connection->wasChanged('payload')
                || $connection->wasChanged('display_settings')
                || $connection->wasChanged('is_active')) {
                $this->refresher->refresh($connection);

                if (app(PlatformRegistry::class)->get($connection->platform)?->hasCompletenessPredicate()) {
                    $connection->user?->site?->touch();
                }
            }
        ```

- [ ] **CACHE-4** · P3 — `IntegrationConnectionObserver::deleted()` inlines five independent cleanup paths rather than chaining/batching them
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:206-243
    - **Affects:** Ordinary disconnects and account teardown — cache refresh, site touch, mirrored-media cleanup, ingest sync, listing-sourced cascade cleanup.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Same as CACHE-3: a consolidation/observability improvement (`Bus::chain` or a single post-delete cleanup job), not an urgent fix, given each step is already best-effort and independently try/caught.
    - **Technical:** Category 5, same root cause as CACHE-3 — `deleted()` calls `refresher->refresh()`, a conditional site touch, `cleanupMirroredMedia()`, `syncIngestSource()`, `disconnectListingSourcedConnections()`, and `clearListingSourcedWorkplaceFields()` inline. Per the "same root cause, same tier" rule this is tiered with CACHE-3 and CACHE-5. As with `saved()`, this fires per single connection delete (not per bulk operation) and each step is individually wrapped in try/catch with `report()` — a failure in one cannot cascade to block the others, which is the main risk a chain/batch would otherwise protect against.
    - **Plain English:** Deleting a connected account sets off five separate cleanup errands in a row instead of one coordinated job. It works today because each errand is independently wrapped so one failure doesn't derail the rest, but a single named cleanup step would be clearer to maintain.
    - **Evidence:**
        ```php
        public function deleted(IntegrationConnection $connection): void
        {
            $this->refresher->refresh($connection);

            if (app(PlatformRegistry::class)->get($connection->platform)?->hasCompletenessPredicate()) {
                Site::query()->where('user_id', $connection->user_id)->first()?->touch();
            }

            $this->cleanupMirroredMedia($connection);
            $this->syncIngestSource($connection);
            $this->disconnectListingSourcedConnections($connection);
            $this->clearListingSourcedWorkplaceFields($connection);
        }
        ```

- [ ] **CACHE-5** · P3 — Google Business listing disconnect deletes machine-created child connections in an unbatched `foreach`
    - **Where:** app/Observers/Core/IntegrationConnectionObserver.php:284-286
    - **Affects:** Google Business listing disconnect flow; each child connection's own `deleted()` cascade (media reclaim, ingest sync, cache purge).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If child cardinality ever grows meaningfully (currently a handful of machine-seeded connections per user — socials, booking, ordering), move to a chunked/queued delete. Not urgent at today's per-user cardinality.
        - At minimum, log the count deleted for observability, since this cascade has already caused one production surprise (see the `disconnectListingSourcedConnections` docblock's FI-15 incident notes).
    - **Technical:** Category 2 (write amplification), but bounded — this loop's cardinality is the number of machine-sourced platform connections for one user (typically single digits: socials, booking, ordering surfaces), not a value that scales with total site/event data. Each `delete()` correctly triggers the child's own `deleted()` observer (intentional, per the docblock, so media/ingest/cache cleanup runs per child) and cannot recurse since none of the children is itself a listing. This is real N-per-event write amplification in shape, but at current per-user connection counts it does not reach a scale that stresses the system — it's a candidate for tidying, not an urgent fix.
    - **Plain English:** Disconnecting a Google Business listing deletes a handful of related auto-created connections one at a time, and each deletion triggers its own cleanup. Today a user only has a few of these, so it's not a real slowdown — but the pattern is worth remembering if that number ever grows.
    - **Evidence:**
        ```php
        foreach ($listingSourced as $candidate) {
            $candidate->delete();
        }
        ```

- [ ] **CACHE-6** · P3 — `DevInsightsController::pageScores()` scans a site's entire event history with no time bound or cache
    - **Where:** app/Http/Controllers/Api/User/Analytics/DevInsightsController.php:101-117
    - **Affects:** Authenticated professionals opening the dev-insights page-score breakdown for their own site.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Bound the `analytics.section_views` and `analytics.link_clicks` queries with the same `$since` window already used by `pageDailySeries()`/`itemDailySeries()` in the same controller, or read totals from the `content_popularity_scores` projection instead of the raw event tables.
        - If this stays a live query, front it with `CacheLockService::rememberLocked` (60s TTL + jitter) since the class docblock already notes "no cache".
    - **Technical:** Category 3/5 (weak caching + unbounded hot read). Unlike the sibling `pageDailySeries()`/`itemDailySeries()` methods in the same file, which correctly filter `occurred_at >= $since`, `pageScores()` queries `analytics.section_views` (twice) and `analytics.link_clicks` with no date bound at all — full lifetime history for the site, on every request, with zero caching (the class docblock states this explicitly: "Plain-array response (no Resource), no cache"). The route (`GET /api/professional/dev-insights`) is a live, unauthenticated-gate-free (behind normal user auth) production endpoint with no environment check, despite being labeled a "DEV / testing endpoint... not production-critical." Impact is real but bounded by the fact this is a low-traffic, owner-only diagnostics page rather than the public sitepage hot path — hence P3 rather than P1/P2.
    - **Plain English:** A screen professionals can open to see how their page is scoring asks the database to add up every view and click their page has ever had, with no time limit and nothing saved from last time. A page having a big viral spike makes this screen — and the database work behind it — slower every time anyone opens it, forever.
    - **Evidence:**
        ```php
        $impressions = $this->foldToPage(
            DB::connection('pgsql')->table('analytics.section_views')
                ->where('site_id', $siteId)->whereNotNull('section_key')
                ->selectRaw('section_key, COUNT(*) as n')->groupBy('section_key')->pluck('n', 'section_key')
        );
        ```

- [ ] **CACHE-7** · P3 — `DevInsightsController::itemScores()` scans a site's entire item-view/click history with no time bound or cache
    - **Where:** app/Http/Controllers/Api/User/Analytics/DevInsightsController.php:168-191
    - **Affects:** Authenticated professionals opening the dev-insights item-score breakdown for their own site.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Same fix as CACHE-6: bound with the controller's own `$since` pattern, or source from `content_popularity_scores`; wrap in `CacheLockService::rememberLocked` if kept live.
        - Fix both in the same session (same file, same missing bound, same missing cache — see Suggested Bundled Sessions).
    - **Technical:** Category 3/5, same root cause as CACHE-6 in the same file. `itemScores()` runs full-history `COUNT(*)`/aggregate queries against `analytics.item_views` and `analytics.link_clicks` with no `occurred_at` filter, feeding the item-level score explanation. Per "same root cause, same tier," this carries the same P3 as CACHE-6.
    - **Plain English:** The companion screen that breaks scores down by item (product, service, link) has the same problem — it recounts every view and click an item has ever received, every time, with nothing cached.
    - **Evidence:**
        ```php
        foreach (DB::connection('pgsql')->table('analytics.item_views')
            ->where('site_id', $siteId)
            ->selectRaw('item_type, item_id, MAX(item_title) as title, COUNT(*) as n')
            ->groupBy('item_type', 'item_id')->get() as $r) {
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Observer fan-out consolidation:** #CACHE-3, #CACHE-4, #CACHE-5
    - **Why grouped:** Same file (`IntegrationConnectionObserver.php`), same root-cause pattern (inline multi-step side effects per model event), fixable in one pass without touching unrelated logic.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Dev-insights query bounding:** #CACHE-6, #CACHE-7
    - **Why grouped:** Same file (`DevInsightsController.php`), same missing `$since` bound already present elsewhere in the same class, same missing-cache gap.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 3 — Analytics compute date-bounding:** #CACHE-1
    - **Why grouped:** Standalone (no sibling finding shares this file/pattern), but low-risk enough to run as a normal bundle rather than requiring sign-off.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#CACHE-2 — Staff CSV batch endpoint runs synchronously in-request** · standalone: introduces a new queued job + response-contract change (202 + polling) for a staff-facing endpoint; should get its own plan and review rather than being folded into an unrelated bundle.
